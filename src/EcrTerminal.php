<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Channel\EcrChannel;
use AmwalPay\Ecr\Channel\EcrChannelTimeout;
use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\Channel\TcpEcrChannel;
use AmwalPay\Ecr\Exception\InvalidRequestException;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Failure;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Failure\Unauthenticated;
use AmwalPay\Ecr\Failure\Unreachable;
use AmwalPay\Ecr\Internal\EcrMessage;
use AmwalPay\Ecr\Internal\EcrMessageCodec;
use AmwalPay\Ecr\Internal\LanLog;
use AmwalPay\Ecr\Internal\MerchantReference;
use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Internal\Validate;
use AmwalPay\Ecr\Internal\WireReaders;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonObject;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Result\CloseReceiptFailed;
use AmwalPay\Ecr\Result\EcrInquiry;
use AmwalPay\Ecr\Result\EcrReceipt;
use AmwalPay\Ecr\Result\EcrReceiptClosed;
use AmwalPay\Ecr\Result\EcrResult;
use AmwalPay\Ecr\Result\EcrSettlement;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\ReceiptFailed;
use AmwalPay\Ecr\Result\SettlementFailed;
use AmwalPay\Ecr\Safety\FinancialGuard;

/**
 * A POS terminal reachable over Wi-Fi, or down a USB cable through a
 * {@see \AmwalPay\Ecr\Channel\SerialEcrChannel}: the same signing, messages and
 * answers either way.
 *
 * One instance addresses one terminal and holds no connection between calls,
 * so it is cheap to keep and safe to reuse.
 *
 * ```php
 * $terminal = EcrSessions::lanTerminal('SN-WIFI-0001', $plan);
 * $result = $terminal->sale('1.234', 'ORDER-1001-1');
 * ```
 *
 * Every request is signed when the config has a key, and every answer must
 * then be signed with the same key and echo the request's nonce, or it is
 * discarded as {@see Unauthenticated}: anything on the network that answers on
 * the terminal's port could otherwise claim "approved" for a payment that never
 * happened.
 */
final class EcrTerminal
{
    private readonly EcrChannel $channel;
    private readonly EcrLogger $logger;
    private readonly FinancialGuard $guard;

    /**
     * @param EcrChannel|string $channel The terminal's host or IP address, or a channel that carries the bytes.
     * @param string $serialNumber Identifies the terminal in the message: the serial the operator registered.
     */
    public function __construct(
        EcrChannel|string $channel,
        private readonly string $serialNumber = '',
        private readonly EcrConfig $config = new EcrConfig(),
        ?EcrLogger $logger = null,
        ?FinancialGuard $guard = null,
    ) {
        $this->channel = \is_string($channel)
            ? new TcpEcrChannel($channel, $config->port, $config->connectTimeout)
            : $channel;
        $this->logger = $logger ?? EcrLogger::none();
        $this->guard = $guard ?? FinancialGuard::fromConfig($config, $this->logger);
    }

    /** Identifies this physical terminal to the guard's lock and journal. */
    public function terminalKey(): string
    {
        // A cable has no socket: its channel reports the COM port and port 0.
        $kind = $this->channel->port() === 0 ? 'cable|' : 'wifi|';
        return $kind . strtolower($this->channel->host()) . '|' . $this->channel->port();
    }

    public function guard(): FinancialGuard
    {
        return $this->guard;
    }

    public function config(): EcrConfig
    {
        return $this->config;
    }

    /** Whether the terminal is listening. Proves the port is open, not that the terminal is idle. */
    public function isReachable(): bool
    {
        return $this->probeReachability()->reachable;
    }

    /**
     * Asks the channel whether the terminal is there, before sending anything.
     * Worth calling before a sale: a wrong address becomes an immediate answer
     * rather than a failure the cardholder waits through.
     */
    public function probeReachability(): EcrReachability
    {
        $problem = $this->channel->probe($this->config->probeTimeout);
        if ($problem !== null) {
            $this->logger->debug('Probe failed for ' . $this->channel->endpoint() . ' — ' . $problem);
        }
        return new EcrReachability($problem === null, $this->channel->host(), $this->channel->port(), $problem, $this->channel->endpoint());
    }

    /**
     * Takes a payment. The cardholder presents the card at the terminal.
     *
     * @param string|int|float|Amount $amount Major units, e.g. "1.234".
     * @param string $merchantReference This attempt's own reference. Save it before calling. Blank: generated.
     * @throws InvalidRequestException nothing was sent
     */
    public function sale(string|int|float|Amount $amount, string $merchantReference = ''): EcrResult
    {
        return $this->run(EcrTransactionType::Sale, amount: $amount, merchantReference: $merchantReference);
    }

    /**
     * Cancels an earlier transaction for its full amount, by its receipt number.
     *
     * @param string $originalTerminalId The terminal the original was taken on, when not this one.
     * @param string $merchantReference The void's own reference, not the original's.
     */
    public function void(string $receiptNumber, string $originalTerminalId = '', string $merchantReference = ''): EcrResult
    {
        return $this->run(
            EcrTransactionType::Void,
            originalStan: $receiptNumber,
            originalTerminalId: $originalTerminalId,
            merchantReference: $merchantReference,
        );
    }

    /**
     * Returns money against an earlier transaction, in full or in part.
     *
     * @param string $transactionDate The original's day as `yyyyMMdd`: a receipt number is unique only within a day.
     */
    public function refund(
        string|int|float|Amount $amount,
        string $receiptNumber,
        string $transactionDate,
        string $originalTerminalId = '',
        string $merchantReference = '',
    ): EcrResult {
        return $this->run(
            EcrTransactionType::Refund,
            amount: $amount,
            originalStan: $receiptNumber,
            originalTerminalId: $originalTerminalId,
            originalDate: $transactionDate,
            merchantReference: $merchantReference,
        );
    }

    /**
     * Asks what became of an earlier transaction by its receipt number, without
     * touching it. Safe to repeat; answered even while the terminal is busy.
     *
     * @param string $merchantReference The inquiry's own reference, not a way to look a transaction up.
     */
    public function inquire(
        string $receiptNumber,
        string $transactionDate,
        string $originalTerminalId = '',
        string $merchantReference = '',
    ): EcrInquiry {
        if (Validate::isBlank($receiptNumber)) {
            throw new InvalidRequestException('An inquiry needs a receipt number');
        }
        $message = $this->build(
            EcrTransactionType::Inquiry,
            stan: $receiptNumber,
            originalTerminalId: $originalTerminalId,
            originalDate: $transactionDate,
            merchantReference: $merchantReference,
        );
        $answer = $this->exchange($message);
        return $answer instanceof Failure
            ? new InquiryFailed($message->merchantReference, $answer)
            : WireReaders::toInquiry($answer, $message->merchantReference, $this->config->minorUnitDigits);
    }

    /**
     * Asks what became of the transaction this till sent as `$originalReference`.
     *
     * This is the answer to an outcome you never received: after a timeout or a
     * lost connection there is no receipt number to quote, but the reference
     * you sent the request with is still yours. Never retry instead.
     *
     * @param string $transactionDate Optional: a reference is not scoped to a day. Do not invent "today".
     * @throws InvalidRequestException `$originalReference` is blank or malformed
     */
    public function inquireByReference(
        string $originalReference,
        string $transactionDate = '',
        string $originalTerminalId = '',
        string $merchantReference = '',
    ): EcrInquiry {
        if (Validate::isBlank($originalReference)) {
            throw new InvalidRequestException("An inquiry by reference needs the original's reference");
        }
        $original = MerchantReference::resolve($originalReference);
        $message = $this->build(
            EcrTransactionType::Inquiry,
            originalTerminalId: $originalTerminalId,
            originalDate: $transactionDate,
            originalReference: $original,
            merchantReference: $merchantReference,
        );
        $answer = $this->exchange($message);
        $inquiry = $answer instanceof Failure
            ? new InquiryFailed($message->merchantReference, $answer)
            : WireReaders::toInquiry($answer, $message->merchantReference, $this->config->minorUnitDigits);
        $this->guard->observeInquiry($this->terminalKey(), $original, $inquiry);
        return $inquiry;
    }

    /** Fetches an earlier transaction's e-receipt URL, for a QR code. Non-financial and repeatable. */
    public function receipt(
        string $receiptNumber,
        string $transactionDate,
        string $originalTerminalId = '',
        string $merchantReference = '',
    ): EcrReceipt {
        if (Validate::isBlank($receiptNumber)) {
            throw new InvalidRequestException('A receipt needs a receipt number');
        }
        $message = $this->build(
            EcrTransactionType::Receipt,
            stan: $receiptNumber,
            originalTerminalId: $originalTerminalId,
            originalDate: $transactionDate,
            merchantReference: $merchantReference,
        );
        $answer = $this->exchange($message);
        return $answer instanceof Failure
            ? new ReceiptFailed($message->merchantReference, $answer)
            : WireReaders::toReceipt($answer, $message->merchantReference);
    }

    /**
     * Asks the terminal to put its receipt away and return to its idle screen.
     * Call it from the till's Close receipt action when the cashier is finished,
     * not as a side effect of dismissing a dialog. Firmware too old to know it
     * answers {@see \AmwalPay\Ecr\Result\CloseReceiptRefused}.
     */
    public function closeReceipt(string $merchantReference = ''): EcrReceiptClosed
    {
        $message = $this->build(EcrTransactionType::CloseReceipt, merchantReference: $merchantReference);
        $answer = $this->exchange($message);
        return $answer instanceof Failure
            ? new CloseReceiptFailed($message->merchantReference, $answer)
            : WireReaders::toReceiptClosed($answer, $message->merchantReference);
    }

    /**
     * Closes the current batch. The terminal starts as soon as this arrives:
     * the till confirms with the cashier before calling. Uses the full response
     * timeout, because a close can wait on pending reversals. Safe to repeat
     * when the answer is lost: a batch that closed answers
     * {@see \AmwalPay\Ecr\Result\NothingToSettle} the next time, and one that
     * did not settles on the retry.
     *
     * @param string $merchantReference The till's own reference for the request. Blank: generated.
     */
    public function settlement(string $merchantReference = ''): EcrSettlement
    {
        $message = $this->build(EcrTransactionType::Settlement, merchantReference: $merchantReference);
        return $this->guard->protected(
            $this->config->connectTimeout + $this->config->responseTimeout,
            function () use ($message): EcrSettlement {
                $answer = $this->exchange($message);
                return $answer instanceof Failure
                    ? new SettlementFailed($message->merchantReference, $answer)
                    : WireReaders::toSettlement($answer, $message->merchantReference, $this->config->minorUnitDigits);
            },
        );
    }

    /**
     * Runs any operation that moves money. The typed methods above are the usual
     * way in; this is for a till driving the type from its own form.
     *
     * @throws InvalidRequestException nothing was sent
     */
    public function run(
        EcrTransactionType $type,
        string|int|float|Amount|null $amount = null,
        string $originalStan = '',
        string $originalTerminalId = '',
        string $originalDate = '',
        string $merchantReference = '',
    ): EcrResult {
        if (!$type->movesMoney()) {
            throw new InvalidRequestException($type->displayName() . ' is run with inquire()');
        }
        if ($type->requiresAmount() && $amount === null) {
            throw new InvalidRequestException($type->displayName() . ' needs an amount');
        }
        if ($type->requiresOriginalStan() && Validate::isBlank($originalStan)) {
            throw new InvalidRequestException($type->displayName() . " needs the original's receipt number");
        }
        $money = $amount === null ? null : Amount::of($amount);

        // Built, signed and serialised before the guard records anything, so a
        // request that cannot be built never leaves a "pending" entry behind.
        $message = $this->build(
            $type,
            amount: $money,
            stan: $originalStan,
            originalTerminalId: $originalTerminalId,
            originalDate: $originalDate,
            merchantReference: MerchantReference::resolve($merchantReference),
        );

        $budget = $this->config->connectTimeout + $this->config->responseTimeout;
        if ($this->config->autoInquireOnFailure) {
            $budget *= 2;
        }

        return $this->guard->run(
            $this->terminalKey(),
            $type,
            $message->merchantReference,
            $money === null ? '' : $money->minorUnitsText($this->config->minorUnitDigits),
            $this->config->currencyCode,
            $budget,
            function () use ($message): EcrResult {
                $answer = $this->exchange($message);
                return $answer instanceof Failure
                    ? $this->lost($message->merchantReference, $answer)
                    : WireReaders::toResult($answer, $message->merchantReference, $this->config->minorUnitDigits);
            },
        );
    }

    /**
     * Turns a lost exchange into a result, asking the terminal once, on a fresh
     * connection, what became of the transaction. An inquiry reads and nothing
     * more, so this cannot itself move money however it goes. The finding is
     * attached, not substituted: the exchange still failed.
     */
    private function lost(string $reference, Failure $failure): Failed
    {
        $plain = new Failed($reference, $failure);
        if (!$this->config->autoInquireOnFailure || !$failure->outcomeUnknown() || $reference === '') {
            return $plain;
        }
        $this->logger->debug('Lost the answer for ' . $reference . ' — asking what became of it');
        try {
            $found = $this->inquireByReference($reference);
        } catch (\Throwable) {
            $found = null;
        }
        return $plain->withRecovered($found);
    }

    private function build(
        EcrTransactionType $type,
        ?Amount $amount = null,
        string $stan = '',
        string $originalTerminalId = '',
        string $originalDate = '',
        string $originalReference = '',
        string $merchantReference = '',
    ): EcrMessage {
        $message = EcrMessage::build(
            $type,
            $this->config,
            $this->serialNumber,
            $amount,
            $stan,
            $originalTerminalId,
            $originalDate,
            $originalReference,
            $merchantReference,
        );
        $wire = Json::write($message->json); // refuses text that is not UTF-8, before anything is sent
        $this->logger->debug('Sending ' . $type->messageType() . ' ' . $message->merchantReference . ' to ' . $this->channel->endpoint());
        $this->logger->debug($wire);
        if ($this->config->signsMessages()) {
            $hash = $message->json->str(SecureHash::FIELD);
            if ($hash !== '') {
                LanLog::signing($this->logger, SecureHash::KEY_LABEL, true, SecureHash::compose($message->json), $hash, $message->nonce());
            }
        }
        return $message;
    }

    /** One request, one answer, or why there was none. */
    private function exchange(EcrMessage $message): JsonObject|Failure
    {
        try {
            $reply = $this->channel->exchange(Json::write($message->json), $this->config->responseTimeout);
            $answer = EcrMessageCodec::parse($reply);
            $this->logger->debug('Received ' . $answer->toJson());

            $rejection = $this->untrustworthy($answer, $message);
            if ($rejection !== null) {
                $this->logger->debug('Rejected the answer: ' . $rejection->message);
                return $rejection;
            }
            return $answer;
        } catch (EcrChannelTimeout) {
            // Not a refusal: the terminal may have completed the payment and been unable to say so.
            return new Timeout(sprintf(
                'The terminal did not answer within %s. The transaction may still have completed — inquire before retrying.',
                self::formatDuration($this->config->responseTimeout),
            ));
        } catch (EcrIOException $e) {
            return self::toFailure($e, $this->channel->endpoint());
        }
    }

    /**
     * Why this answer must not be believed, or null when it can be. A rejected
     * answer says nothing about what the terminal did, so it is an unknown
     * outcome, never a decline.
     */
    private function untrustworthy(JsonObject $answer, EcrMessage $sent): ?Failure
    {
        if (!$this->config->signsMessages()) {
            return null;
        }
        $presented = $answer->str(SecureHash::FIELD);
        $verified = SecureHash::verify($answer, $this->config->secureHashKey);
        if ($presented !== '') {
            LanLog::verifyResponse($this->logger, SecureHash::KEY_LABEL, true, SecureHash::compose($answer), $presented, $verified);
        }
        if (!$verified) {
            return new Unauthenticated("The answer was not signed with this terminal's key.");
        }
        // Binds the answer to this request: a correctly signed answer to an
        // earlier transaction, replayed onto this connection, fails here.
        if (!hash_equals($sent->nonce(), $answer->str('nonce'))) {
            return new Unauthenticated(
                'The answer belongs to a different request. The transaction may still have completed — inquire before retrying.',
            );
        }
        return null;
    }

    private static function toFailure(EcrIOException $e, string $endpoint): Failure
    {
        $reason = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
        if (str_contains($reason, 'not valid JSON') || str_contains($reason, 'empty message')) {
            return new Malformed($reason);
        }
        if (str_contains($reason, 'closed the connection')) {
            return new ConnectionLost($reason);
        }
        return new Unreachable($reason . ' (' . $endpoint . ')');
    }

    /** `45s`, `2m`, `1m 30s`. */
    public static function formatDuration(float $seconds): string
    {
        $total = (int) round($seconds);
        if ($total < 60) {
            return $total . 's';
        }
        $minutes = intdiv($total, 60);
        $rest = $total % 60;
        return $rest === 0 ? $minutes . 'm' : $minutes . 'm ' . $rest . 's';
    }
}
