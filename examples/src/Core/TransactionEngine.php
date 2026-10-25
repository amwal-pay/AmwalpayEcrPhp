<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\EcrOpenedSession;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Result\CloseReceiptFailed;
use AmwalPay\Ecr\Result\CloseReceiptRefused;
use AmwalPay\Ecr\Result\EcrReceipt;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\ReceiptClosed;
use AmwalPay\Ecr\Result\ReceiptUnavailable;

/**
 * Runs one operation against a registered terminal: resolve the config, open
 * the session through {@see EcrSessions::open()}, probe, execute, and classify
 * the answer. The flow and the money-safety rules of the Windows samples' Core.
 */
final class TransactionEngine
{
    public function __construct(
        private readonly TerminalRepository $repository,
        private readonly EcrExampleSettings $settings,
        private readonly LogBuffer $log,
        private readonly string $sdkStateDirectory,
    ) {
    }

    public function resolveConfig(Terminal $terminal): SelectedTerminalConfig
    {
        return SelectedTerminalConfig::resolve(
            $terminal,
            $this->settings->environment(),
            $this->settings->secureHashKeyFor($terminal->mode()),
            $this->sdkStateDirectory,
        );
    }

    public function logSelectedConfig(SelectedTerminalConfig $config): void
    {
        $logger = $this->loggerFor($config);
        $prefix = $config->usesWebService() ? '[Web Service]' : '[Wi-Fi]';
        $logger->debug($prefix . ' selected ' . $config->terminal->serialNumber . ' (' . $config->modeLabel() . ')');
        $logger->debug($prefix . ' SDK connection: ' . $config->connectionSummary());
        foreach ($config->issues() as $issue) {
            $logger->debug($prefix . ' config issue: ' . $issue);
        }
    }

    /**
     * Runs `$request` and returns the state to show.
     *
     * @param callable(TransactionState): bool $report Called with InProgress just before the
     *        request goes out. Returns false when the operator has cancelled: nothing is sent and
     *        the run ends Idle.
     */
    public function run(TransactionRequest $request, callable $report): TransactionState
    {
        $registered = $this->repository->findBySerial($request->terminalSerial);
        if ($registered === null) {
            return new StateFailed('Terminal ' . $request->terminalSerial . ' is no longer registered');
        }
        $active = $this->resolveConfig($registered);
        if (!$active->isReady()) {
            return new StateFailed(implode("\n", $active->issues()));
        }
        try {
            $session = $this->open($registered, $active);
        } catch (\InvalidArgumentException | \LogicException $e) {
            return new StateFailed($e->getMessage());
        }

        $reference = $request->type->movesMoney() && $request->merchantReference === ''
            ? self::newReference()
            : $request->merchantReference;

        $probe = $session->probeReachability();
        if ($probe !== null && !$probe->reachable) {
            $this->loggerFor($active)->debug('[' . $probe->endpoint . '] probe failed' . ($probe->error !== null ? ' — ' . $probe->error : ''));
            return new StateFailed(TransactionOutcomes::unreachableMessage($probe->endpoint, $probe->port, $probe->error));
        }

        if (!$report(new StateInProgress())) {
            // Cancelled while checking: nothing has been sent, so nothing can have happened.
            return new StateIdle();
        }

        try {
            return $this->execute($session, $request, $reference);
        } catch (\InvalidArgumentException $e) {
            // Refused before anything was built or sent: a plain "not completed".
            return new StateFailed($e->getMessage());
        } catch (\Throwable $e) {
            $this->loggerFor($active)->debug('[App] unexpected error: ' . $e->getMessage());
            return $request->type->movesMoney()
                ? new StateFailed('Unexpected error: ' . $e->getMessage(), $reference)
                : new StateFailed('Unexpected error: ' . $e->getMessage());
        }
    }

    private function execute(EcrOpenedSession $session, TransactionRequest $request, string $reference): TransactionState
    {
        if ($request->type === EcrTransactionType::Inquiry) {
            // By reference leaves the date blank (a reference is not day-scoped);
            // by receipt number sends the form's date. Never invent today's date
            // for Web Service: the Hub filters by that day.
            $inquiry = $request->looksUpByReference()
                ? $session->inquireByReference($request->originalReference, $request->transactionDate, $request->originalTerminalId, $request->merchantReference)
                : $session->inquire($request->receiptNumber, $request->transactionDate, $request->originalTerminalId);
            return TransactionOutcomes::fromInquiry($inquiry, $request);
        }
        $result = match ($request->type) {
            EcrTransactionType::Sale => $session->sale((string) $request->amount, $reference),
            EcrTransactionType::Void => $session->void($request->receiptNumber, $request->originalTerminalId, $reference),
            EcrTransactionType::Refund => $session->refund((string) $request->amount, $request->receiptNumber, $request->transactionDate, $request->originalTerminalId, $reference),
            default => throw new \LogicException($request->type->displayName() . ' is not run from here'),
        };
        return TransactionOutcomes::fromResult($result, $request->withMerchantReference($reference));
    }

    /**
     * Asks the terminal to put its receipt away. Call from the Close receipt
     * action, not when the result dialog is dismissed. Never throws.
     *
     * @param string $originalMerchantReference Required for Web Service: the sale, void or refund reference.
     */
    public function closeTerminalReceipt(string $terminalSerial, string $originalMerchantReference = ''): string
    {
        $registered = $this->repository->findBySerial($terminalSerial);
        if ($registered === null) {
            return 'Terminal ' . $terminalSerial . ' is no longer registered';
        }
        if (!$registered->mode()->isSupportedInExample()) {
            return 'Close receipt is not available for this terminal mode';
        }
        $active = $this->resolveConfig($registered);
        if (!$active->isReady()) {
            return implode("\n", $active->issues());
        }
        try {
            $closed = $this->open($registered, $active)->closeReceipt(originalMerchantReference: $originalMerchantReference);
            return match (true) {
                $closed instanceof ReceiptClosed => 'Terminal is back on its idle screen',
                $closed instanceof CloseReceiptRefused => 'Terminal refused: ' . $closed->reason,
                $closed instanceof CloseReceiptFailed => 'Could not reach the terminal: ' . $closed->failure->message,
                default => 'Could not close the receipt',
            };
        } catch (\Throwable $e) {
            $this->loggerFor($active)->debug('[App] close receipt failed: ' . $e->getMessage());
            return 'Could not close the receipt: ' . $e->getMessage();
        }
    }

    /** Fetches the e-receipt of an inquired transaction. Never throws. */
    public function fetchReceipt(StateInquired $current): EcrReceipt
    {
        $registered = $this->repository->findBySerial($current->request->terminalSerial);
        if ($registered === null) {
            return self::unavailable('Terminal ' . $current->request->terminalSerial . ' is no longer registered');
        }
        $found = $current->inquiry instanceof Found ? $current->inquiry : null;
        $receiptNumber = $found !== null && trim($found->transaction->stan) !== '' ? $found->transaction->stan : $current->request->receiptNumber;
        if (trim($receiptNumber) === '') {
            return self::unavailable('This transaction has no receipt number to fetch a receipt by');
        }
        $day = $found !== null ? self::dayOf($found->transaction->transactionTime) : '';
        $transactionDate = $day !== '' ? $day : $current->request->transactionDate;
        $active = $this->resolveConfig($registered);
        if (!$active->isReady()) {
            return self::unavailable(implode("\n", $active->issues()));
        }
        try {
            return $this->open($registered, $active)->receipt($receiptNumber, $transactionDate, $current->request->originalTerminalId);
        } catch (\Throwable $e) {
            // A receipt reads and nothing more, so failing to get one is never worse than not asking.
            $this->loggerFor($active)->debug('[App] receipt failed: ' . $e->getMessage());
            return self::unavailable($e->getMessage());
        }
    }

    /** The backend's `2026-08-09T16:58:16` as `yyyyMMdd`, or "". */
    public static function dayOf(string $transactionTime): string
    {
        $digits = preg_replace('/[^0-9]/', '', $transactionTime) ?? '';
        return \strlen($digits) >= 8 ? substr($digits, 0, 8) : '';
    }

    /** Same shape the SDK generates: 12 uppercase hex characters. */
    public static function newReference(): string
    {
        return EcrSessions::newMerchantReference();
    }

    private static function unavailable(string $reason): EcrReceipt
    {
        return new ReceiptUnavailable('', $reason, '');
    }

    private function open(Terminal $registered, SelectedTerminalConfig $active): EcrOpenedSession
    {
        return EcrSessions::open($registered->serialNumber, $active->plan, $this->loggerFor($active));
    }

    private function loggerFor(SelectedTerminalConfig $config): EcrLogger
    {
        $tag = $config->usesWebService() ? 'EcrWebService' : 'Ecr';
        $log = $this->log;
        return EcrLogger::from(static fn (string $message) => $log->append($tag, $message));
    }
}
