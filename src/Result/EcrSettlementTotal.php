<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/** One row of a settlement total: how many, and their amount in major units. */
final class EcrSettlementTotal
{
    public function __construct(
        public readonly int $count,
        public readonly string $amount,
    ) {
    }

    /** @return array{count: int, amount: string} */
    public function toArray(): array
    {
        return ['count' => $this->count, 'amount' => $this->amount];
    }
}
