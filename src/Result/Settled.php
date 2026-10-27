<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * The batch closed. Amounts are major units. `$totals` always has `SALE`,
 * `REFUND`, `VOID`, `PRE_AUTH` and `CAPTURE`, in that order, with a zero row
 * where the terminal omitted one. (`EcrSettlement.Settled` in the Windows SDK.)
 */
final class Settled extends EcrSettlement
{
    /** @param array<string, EcrSettlementTotal> $totals keyed by transaction type */
    public function __construct(
        string $merchantReference,
        public readonly string $batchId,
        public readonly string $currency,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly int $totalTransactions,
        public readonly string $netAmount,
        public readonly array $totals,
        public readonly string $raw,
    ) {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'settled';
    }

    public function toArray(): array
    {
        return [
            'outcome' => 'settled',
            'merchantReference' => $this->merchantReference,
            'batchId' => $this->batchId,
            'currency' => $this->currency,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'totalTransactions' => $this->totalTransactions,
            'netAmount' => $this->netAmount,
            'totals' => array_map(static fn (EcrSettlementTotal $total): array => $total->toArray(), $this->totals),
        ];
    }
}
