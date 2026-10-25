<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/** A transaction as the terminal's backend records it. */
final class EcrTransaction
{
    /**
     * @param string $transactionId The backend's identifier, unique across terminals and days.
     * @param string $stan The receipt number, unique only within one terminal's day.
     * @param string $type `Purchase`, `Void`, `Refund`: as the backend names it.
     * @param string $status What became of it: `Approved`, `Declined`, `Voided`... Compare ignoring case.
     * @param string $amount In major units. What was requested.
     * @param string $totalAmount Including tips and fees, where there were any.
     * @param string $transactionTime As recorded, e.g. `2026-08-09T12:41:07`.
     * @param string $maskedPan Masked, never the full number. May be empty.
     * @param bool $canVoid Whether the terminal would accept a void for it now.
     * @param bool $canRefund Whether the terminal would accept a refund against it.
     * @param bool $partialApproval The issuer approved less than asked: {@see $authorizedAmount} is what was charged.
     * @param string $authorizedAmount What was actually authorised. Equal to the amount unless partially approved.
     */
    public function __construct(
        public readonly string $transactionId,
        public readonly string $stan,
        public readonly string $type,
        public readonly string $status,
        public readonly string $amount,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly string $transactionTime,
        public readonly string $maskedPan,
        public readonly string $cardHolderName,
        public readonly string $rrn,
        public readonly string $authCode,
        public readonly string $batchId,
        public readonly string $terminalId,
        public readonly bool $isRefunded,
        public readonly bool $canVoid,
        public readonly bool $canRefund,
        public readonly bool $partialApproval = false,
        public readonly string $authorizedAmount = '',
    ) {
    }

    /** Whether {@see $status} reads `Approved`, ignoring case. */
    public function isApproved(): bool
    {
        return strcasecmp(trim($this->status), 'Approved') === 0;
    }

    /** Whether {@see $status} reads `Declined`, ignoring case. */
    public function isDeclined(): bool
    {
        return strcasecmp(trim($this->status), 'Declined') === 0;
    }

    /** The day of {@see $transactionTime} as `yyyyMMdd`, or "" when it has none. */
    public function day(): string
    {
        $digits = preg_replace('/[^0-9]/', '', $this->transactionTime) ?? '';
        return \strlen($digits) >= 8 ? substr($digits, 0, 8) : '';
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
