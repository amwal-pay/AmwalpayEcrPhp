<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * The answer to "what became of this transaction": {@see Found},
 * {@see NotFound} or {@see InquiryFailed}.
 *
 * Kept apart from {@see EcrResult} because it reports on a transaction rather
 * than performing one: an inquiry that succeeds says nothing about whether money
 * moved. That is {@see EcrTransaction::$status}.
 */
abstract class EcrInquiry
{
    /** @param string $merchantReference The reference the inquiry was sent with, or the answer's own. */
    public function __construct(public readonly string $merchantReference)
    {
    }

    /** `found`, `not_found` or `failed`. */
    abstract public function outcome(): string;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;
}
