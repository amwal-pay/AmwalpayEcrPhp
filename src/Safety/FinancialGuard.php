<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Safety;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Exception\DuplicateMerchantReferenceException;
use AmwalPay\Ecr\Failure\Busy;
use AmwalPay\Ecr\Failure\Unresolved;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\EcrInquiry;
use AmwalPay\Ecr\Result\EcrResult;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\NotFound;

/**
 * The payment-safety rules a PHP till cannot leave to each request:
 *
 * 1. **One financial transaction per terminal at a time.** A second sale, void
 *    or refund while one is running, from any PHP process on the machine, gets
 *    {@see Busy} and nothing is sent.
 * 2. **A merchant reference is used once.** Sending a reference this terminal has
 *    already been sent throws {@see DuplicateMerchantReferenceException}: that is
 *    how a till resends a payment it is unsure about.
 * 3. **No new money movement while an outcome is unknown.** After a timeout, a
 *    lost connection, an unreadable or unauthenticated answer, the reference is
 *    kept as unresolved, and the next sale, void or refund on that terminal gets
 *    {@see Unresolved} until an inquiry by reference settles it, or an operator
 *    settles it with {@see markResolved()}.
 * 4. **The reference is persisted before the request leaves**, so a PHP process
 *    killed mid-wait leaves an unresolved entry, not a forgotten payment.
 *
 * Inquiries, receipts and close receipt move no money and are never blocked.
 * The Windows SDK leaves these rules to the till (its web sample enforces 1);
 * the PHP SDK enforces them by default. Turn off with
 * `EcrConfig(guardFinancialOperations: false)` only if your own system enforces
 * the same rules.
 */
final class FinancialGuard
{
    public function __construct(
        private readonly TransactionJournal $journal,
        private readonly TerminalLock $lock,
        private readonly bool $enabled = true,
        private readonly bool $extendTimeLimit = true,
        private readonly ?EcrLogger $logger = null,
    ) {
    }

    public static function fromConfig(EcrConfig $config, ?EcrLogger $logger = null): self
    {
        $directory = $config->stateDirectory ?? (rtrim(sys_get_temp_dir(), '\\/') . \DIRECTORY_SEPARATOR . 'amwalpay-ecr');
        return new self(
            new FileTransactionJournal($directory),
            new FileTerminalLock($directory),
            $config->guardFinancialOperations,
            $config->extendTimeLimit,
            $logger,
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Runs one sale, void or refund under the rules above.
     *
     * @param string $terminalKey Identifies the physical terminal (see the terminals' `terminalKey()`).
     * @param string $reference The resolved merchant reference the request will carry.
     * @param float $budgetSeconds The longest the call can take, for PHP's time limit.
     * @param callable(): EcrResult $send Builds and sends the request.
     * @throws DuplicateMerchantReferenceException
     */
    public function run(
        string $terminalKey,
        EcrTransactionType $type,
        string $reference,
        string $amountMinorUnits,
        string $currencyCode,
        float $budgetSeconds,
        callable $send,
    ): EcrResult {
        if (!$this->enabled) {
            return $this->protected($budgetSeconds, $send);
        }

        $release = $this->lock->tryAcquire($terminalKey);
        if ($release === null) {
            $this->logger?->debug('[Guard] ' . $type->messageType() . ' ' . $reference . ' refused: another transaction is running on this terminal');
            return new Failed($reference, new Busy(
                'Another sale, void or refund is in progress on this terminal. Nothing was sent; '
                . 'wait for it to finish, then try again.',
            ));
        }

        try {
            $previous = $this->journal->find($terminalKey, $reference);
            if ($previous !== null) {
                throw new DuplicateMerchantReferenceException($previous);
            }

            $open = $this->journal->unresolved($terminalKey);
            if ($open !== []) {
                $references = implode(', ', array_map(static fn (JournalEntry $e): string => $e->reference, $open));
                $this->logger?->debug('[Guard] ' . $type->messageType() . ' ' . $reference . ' refused: unresolved ' . $references);
                return new Failed($reference, new Unresolved(
                    'An earlier transaction on this terminal has an unknown outcome: ' . $references
                    . '. Nothing was sent. Inquire by that merchant reference before taking a new payment.',
                ));
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->journal->put($terminalKey, new JournalEntry(
                $reference,
                $type->value,
                JournalEntry::PENDING,
                $amountMinorUnits,
                $currencyCode,
                $now,
                $now,
            ));

            $result = $this->protected($budgetSeconds, $send);
            $this->record($terminalKey, $reference, $result);
            return $result;
        } finally {
            $release();
        }
    }

    /**
     * Lets an inquiry by reference settle an unresolved entry: a record whose
     * status is Approved or Declined settles it. Not found settles nothing:
     * after a lost answer it does not prove nothing was charged.
     */
    public function observeInquiry(string $terminalKey, string $originalReference, EcrInquiry $inquiry): void
    {
        if (!$this->enabled) {
            return;
        }
        $entry = $this->journal->find($terminalKey, $originalReference);
        if ($entry === null || !$entry->isUnresolved()) {
            return;
        }
        if ($inquiry instanceof Found) {
            $status = $inquiry->transaction->status;
            $state = $inquiry->transaction->isApproved() ? JournalEntry::APPROVED
                : ($inquiry->transaction->isDeclined() ? JournalEntry::DECLINED : JournalEntry::UNKNOWN);
            $this->journal->put($terminalKey, $entry->with(
                state: $state,
                status: $status,
                note: $state === JournalEntry::UNKNOWN
                    ? 'Inquiry found status "' . $status . '": confirm on the terminal or with Amwal, then mark it resolved.'
                    : 'Settled by inquiry by merchant reference.',
            ));
        } elseif ($inquiry instanceof NotFound) {
            $this->journal->put($terminalKey, $entry->with(
                state: JournalEntry::UNKNOWN,
                note: 'Inquiry found no record (' . $inquiry->reason . '). That does not prove nothing was charged.',
            ));
        }
    }

    /** @return list<JournalEntry> */
    public function unresolved(string $terminalKey): array
    {
        return $this->enabled ? $this->journal->unresolved($terminalKey) : [];
    }

    public function entry(string $terminalKey, string $reference): ?JournalEntry
    {
        return $this->enabled ? $this->journal->find($terminalKey, $reference) : null;
    }

    /**
     * Settles an unresolved entry by hand, after the outcome was confirmed on the
     * terminal or with Amwal. `$note` should say how it was confirmed.
     */
    public function markResolved(string $terminalKey, string $reference, string $note): JournalEntry
    {
        if (!$this->enabled) {
            throw new \LogicException('The financial guard is off: there is no journal to settle');
        }
        $entry = $this->journal->find($terminalKey, $reference);
        if ($entry === null) {
            throw new \InvalidArgumentException('No transaction with merchant reference ' . $reference . ' was sent to this terminal');
        }
        if (!$entry->isUnresolved()) {
            return $entry;
        }
        if (trim($note) === '') {
            throw new \InvalidArgumentException('Say how the outcome was confirmed');
        }
        $resolved = $entry->with(state: JournalEntry::RESOLVED, note: trim($note));
        $this->journal->put($terminalKey, $resolved);
        $this->logger?->debug('[Guard] ' . $reference . ' marked resolved by the operator');
        return $resolved;
    }

    private function record(string $terminalKey, string $reference, EcrResult $result): void
    {
        $entry = $this->journal->find($terminalKey, $reference);
        if ($entry === null) {
            return;
        }
        if ($result instanceof Approved) {
            $this->journal->put($terminalKey, $entry->with(state: JournalEntry::APPROVED, status: 'Approved'));
            return;
        }
        if ($result instanceof Declined) {
            $this->journal->put($terminalKey, $entry->with(state: JournalEntry::DECLINED, status: $result->responseCode));
            return;
        }
        if (!$result instanceof Failed) {
            return;
        }
        if (!$result->failure->outcomeUnknown()) {
            // Nothing was sent: the reference was never used and may be used again.
            $this->journal->remove($terminalKey, $reference);
            return;
        }
        $failure = $result->failure->kind() . ': ' . $result->failure->message;
        $current = $this->journal->find($terminalKey, $reference) ?? $entry;
        if (!$current->isUnresolved()) {
            // The automatic inquiry already settled it.
            $this->journal->put($terminalKey, $current->with(failure: $failure));
            return;
        }
        $this->journal->put($terminalKey, $current->with(state: JournalEntry::UNKNOWN, failure: $failure));
    }

    /**
     * Runs `$send` so that neither PHP's time limit nor a browser that gives up
     * can kill it between sending the request and reading the answer.
     *
     * @param callable(): EcrResult $send
     */
    private function protected(float $budgetSeconds, callable $send): EcrResult
    {
        $previousAbort = ignore_user_abort(true);
        if ($this->extendTimeLimit) {
            $limit = (int) ini_get('max_execution_time');
            if ($limit > 0) {
                $needed = (int) ceil($budgetSeconds) + 30;
                if (!\function_exists('set_time_limit') || @set_time_limit($needed) === false) {
                    $this->logger?->debug(sprintf(
                        '[Guard] max_execution_time is %ds and could not be raised to %ds: PHP may stop this request before the terminal answers',
                        $limit,
                        $needed,
                    ));
                }
            }
        }
        try {
            return $send();
        } finally {
            ignore_user_abort((bool) $previousAbort);
        }
    }
}
