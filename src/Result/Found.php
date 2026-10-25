<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/** The transaction was found. {@see EcrTransaction::$status} says what became of it. */
final class Found extends EcrInquiry
{
    public function __construct(
        string $merchantReference,
        public readonly EcrTransaction $transaction,
        public readonly string $raw,
    ) {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'found';
    }

    public function toArray(): array
    {
        return [
            'outcome' => 'found',
            'merchantReference' => $this->merchantReference,
            'transaction' => $this->transaction->toArray(),
        ];
    }
}
