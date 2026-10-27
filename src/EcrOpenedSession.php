<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Internal\Validate;
use AmwalPay\Ecr\Result\CloseReceiptFailed;
use AmwalPay\Ecr\Result\EcrInquiry;
use AmwalPay\Ecr\Result\EcrReceipt;
use AmwalPay\Ecr\Result\EcrReceiptClosed;
use AmwalPay\Ecr\Result\EcrResult;
use AmwalPay\Ecr\Result\EcrSettlement;
use AmwalPay\Ecr\Result\ReceiptUnavailable;
use AmwalPay\Ecr\Safety\FinancialGuard;
use AmwalPay\Ecr\Safety\JournalEntry;

/**
 * One opened transport for a validated plan: every sale, void, refund,
 * inquiry, e-receipt, close receipt and settlement for that terminal goes
 * through it, the same way over Wi-Fi, USB cable and Web Service.
 *
 * Open it with {@see EcrSessions::open()}.
 */
final class EcrOpenedSession
{
    /** @internal Use {@see EcrSessions::open()}. */
    public function __construct(
        public readonly EcrSessionPlan $plan,
        private readonly ?EcrTerminal $local,
        private readonly ?EcrWebServiceTerminal $web,
    ) {
        if (($local === null) === ($web === null)) {
            throw new \InvalidArgumentException('A session has exactly one transport');
        }
    }

    public function usesWebService(): bool
    {
        return $this->web !== null;
    }

    public function usesLocalTerminal(): bool
    {
        return $this->local !== null;
    }

    /** Receipt URLs exist only on the local protocol (Wi-Fi and USB cable). */
    public function supportsReceipt(): bool
    {
        return $this->local !== null;
    }

    /** Wi-Fi and USB cable send `SETTLEMENT`. Web Service posts `/Ecr/settle`. */
    public function supportsSettlement(): bool
    {
        return $this->local !== null || $this->web !== null;
    }

    /** The Wi-Fi or USB cable terminal behind this session, or null on Web Service. */
    public function lanTerminal(): ?EcrTerminal
    {
        return $this->local;
    }

    /** The Web Service terminal behind this session, or null on Wi-Fi and USB cable. */
    public function webServiceTerminal(): ?EcrWebServiceTerminal
    {
        return $this->web;
    }

    /**
     * Wi-Fi and USB cable probe the link before a sale. Web Service returns
     * null, so a sale is not held for a Hub probe: `/Ecr/CheckReachability` is
     * {@see self::checkReachability()}, called on its own.
     */
    public function probeReachability(): ?EcrReachability
    {
        return $this->local?->probeReachability();
    }

    /**
     * Posts `/Ecr/CheckReachability` with the probe timeout. Null when this
     * session is not Web Service. A sale does not call this.
     */
    public function checkReachability(): ?EcrReachability
    {
        return $this->web?->probeReachability();
    }

    /**
     * @param string|int|float|Amount $amount Major units, e.g. "1.234".
     * @param string $merchantReference This attempt's own reference. Save it before calling.
     */
    public function sale(string|int|float|Amount $amount, string $merchantReference = ''): EcrResult
    {
        return $this->local !== null
            ? $this->local->sale($amount, $merchantReference)
            : $this->webTerminal()->sale($amount, $merchantReference);
    }

    /** @param string $originalTerminalId Wi-Fi only; Web Service uses the terminal in the plan. */
    public function void(string $receiptNumber, string $originalTerminalId = '', string $merchantReference = ''): EcrResult
    {
        return $this->local !== null
            ? $this->local->void($receiptNumber, $originalTerminalId, $merchantReference)
            : $this->webTerminal()->void($receiptNumber, $merchantReference);
    }

    /** @param string $transactionDate The original's day as `yyyyMMdd`. */
    public function refund(
        string|int|float|Amount $amount,
        string $receiptNumber,
        string $transactionDate,
        string $originalTerminalId = '',
        string $merchantReference = '',
    ): EcrResult {
        return $this->local !== null
            ? $this->local->refund($amount, $receiptNumber, $transactionDate, $originalTerminalId, $merchantReference)
            : $this->webTerminal()->refund($amount, $receiptNumber, $transactionDate, $merchantReference);
    }

    public function inquire(string $receiptNumber, string $transactionDate, string $originalTerminalId = ''): EcrInquiry
    {
        return $this->local !== null
            ? $this->local->inquire($receiptNumber, $transactionDate, $originalTerminalId)
            : $this->webTerminal()->inquire($receiptNumber, $transactionDate);
    }

    /**
     * Reads the attempt you sent as `$originalReference`: the answer to a sale,
     * void or refund that never answered.
     */
    public function inquireByReference(
        string $originalReference,
        string $transactionDate = '',
        string $originalTerminalId = '',
        string $merchantReference = '',
    ): EcrInquiry {
        return $this->local !== null
            ? $this->local->inquireByReference($originalReference, $transactionDate, $originalTerminalId, $merchantReference)
            : $this->webTerminal()->inquireByReference($originalReference, $transactionDate, $merchantReference);
    }

    /** Wi-Fi and USB cable only. On Web Service it answers {@see ReceiptUnavailable}. */
    public function receipt(string $receiptNumber, string $transactionDate, string $originalTerminalId = ''): EcrReceipt
    {
        if ($this->local === null) {
            return new ReceiptUnavailable('', 'Receipt fetch is only supported over Wi‑Fi and USB cable ECR', '');
        }
        return $this->local->receipt($receiptNumber, $transactionDate, $originalTerminalId);
    }

    /**
     * Asks the terminal to put its receipt away and go back to idle. Call it
     * from the till's Close receipt action, never automatically.
     *
     * Pass the arguments by name: both are strings. On Web Service, when only
     * `$merchantReference` is given it is taken as the original, and the SDK
     * generates this request's own id.
     *
     * @param string $merchantReference This close request's own id. Blank: generated.
     * @param string $originalMerchantReference Web Service requires it: the sale, void or refund being
     *        dismissed. Wi-Fi ignores it.
     */
    public function closeReceipt(string $merchantReference = '', string $originalMerchantReference = ''): EcrReceiptClosed
    {
        if ($this->local !== null) {
            return $this->local->closeReceipt($merchantReference);
        }
        $original = Validate::isBlank($originalMerchantReference) ? $merchantReference : $originalMerchantReference;
        if (Validate::isBlank($original)) {
            return new CloseReceiptFailed($merchantReference, new Malformed('Web Service close receipt needs the original merchant reference'));
        }
        // Only one reference passed: it is the original; the SDK mints this request's id.
        $closeReference = Validate::isBlank($originalMerchantReference) ? '' : $merchantReference;
        return $this->webTerminal()->closeReceipt($original, $closeReference);
    }

    /**
     * Closes the current batch. Wi-Fi and USB cable send `SETTLEMENT`. Web
     * Service posts `/Ecr/settle`. Confirm with the cashier before calling: the
     * close starts as soon as the request arrives.
     *
     * @param string $merchantReference This request's own id. Blank: generated.
     */
    public function settlement(string $merchantReference = ''): EcrSettlement
    {
        return $this->local !== null
            ? $this->local->settlement($merchantReference)
            : $this->webTerminal()->settlement($merchantReference);
    }

    /** Identifies this terminal to the guard's lock and journal. */
    public function terminalKey(): string
    {
        return $this->local?->terminalKey() ?? $this->webTerminal()->terminalKey();
    }

    public function guard(): FinancialGuard
    {
        return $this->local?->guard() ?? $this->webTerminal()->guard();
    }

    /**
     * Sales, voids and refunds on this terminal whose outcome is still unknown.
     * While any is listed, a new one answers {@see \AmwalPay\Ecr\Failure\Unresolved}.
     *
     * @return list<JournalEntry>
     */
    public function pendingOutcomes(): array
    {
        return $this->guard()->unresolved($this->terminalKey());
    }

    /**
     * Inquires by reference for every unknown outcome on this terminal, and lets
     * the answers settle what they can. Run it at start-up and after a failure.
     * Safe to repeat: an inquiry moves no money.
     *
     * @return array<string, EcrInquiry> keyed by the original merchant reference
     */
    public function reconcile(): array
    {
        $results = [];
        foreach ($this->pendingOutcomes() as $entry) {
            $results[$entry->reference] = $this->inquireByReference($entry->reference);
        }
        return $results;
    }

    /**
     * Settles an unknown outcome by hand, once it was confirmed on the terminal
     * or with Amwal: for example an inquiry that keeps answering "not found".
     *
     * @param string $note How it was confirmed, for the record.
     */
    public function markResolved(string $merchantReference, string $note): JournalEntry
    {
        return $this->guard()->markResolved($this->terminalKey(), trim($merchantReference), $note);
    }

    private function webTerminal(): EcrWebServiceTerminal
    {
        if ($this->web === null) {
            throw new \LogicException('Session has no transport');
        }
        return $this->web;
    }
}
