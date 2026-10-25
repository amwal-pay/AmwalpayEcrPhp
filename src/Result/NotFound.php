<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * This lookup returned no record.
 *
 * After a lost answer that is not proof nothing was charged: the same answer
 * follows a terminal reset or a record that has aged out. Confirm on the
 * terminal, or with Amwal, before taking payment again.
 */
final class NotFound extends EcrInquiry
{
    public function __construct(
        string $merchantReference,
        public readonly string $reason,
        public readonly string $raw,
    ) {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'not_found';
    }

    public function toArray(): array
    {
        return ['outcome' => 'not_found', 'merchantReference' => $this->merchantReference, 'reason' => $this->reason];
    }
}
