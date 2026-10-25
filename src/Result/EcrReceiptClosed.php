<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * The answer to a request to put the terminal's receipt away:
 * {@see ReceiptClosed}, {@see CloseReceiptRefused} or {@see CloseReceiptFailed}.
 * Success means the terminal is idle again, whether it had a receipt up or not.
 * Safe to repeat.
 */
abstract class EcrReceiptClosed
{
    public function __construct(public readonly string $merchantReference)
    {
    }

    /** `idle`, `refused` or `failed`. */
    abstract public function outcome(): string;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;
}
