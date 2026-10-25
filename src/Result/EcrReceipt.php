<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * A transaction's e-receipt: {@see ReceiptReady}, {@see ReceiptUnavailable} or
 * {@see ReceiptFailed}. The URL is shown as a QR code, so a till with no printer
 * can still hand one over. Wi-Fi only.
 */
abstract class EcrReceipt
{
    public function __construct(public readonly string $merchantReference)
    {
    }

    /** `ready`, `unavailable` or `failed`. */
    abstract public function outcome(): string;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;
}
