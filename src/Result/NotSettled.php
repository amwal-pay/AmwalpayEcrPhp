<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * The batch is still open: the counts did not match, the session had expired,
 * the terminal was busy, or this transport cannot settle. `$status` is the
 * terminal's `settlementStatus`, empty when it refused before the settlement
 * started. (`EcrSettlement.NotSettled` in the Windows SDK.)
 */
final class NotSettled extends EcrSettlement
{
    public function __construct(
        string $merchantReference,
        public readonly string $responseCode,
        public readonly string $status,
        public readonly string $reason,
        public readonly ?int $uploadedTransactions,
        public readonly string $raw,
    ) {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'not_settled';
    }

    public function toArray(): array
    {
        return [
            'outcome' => 'not_settled',
            'merchantReference' => $this->merchantReference,
            'responseCode' => $this->responseCode,
            'status' => $this->status,
            'reason' => $this->reason,
            'uploadedTransactions' => $this->uploadedTransactions,
        ];
    }
}
