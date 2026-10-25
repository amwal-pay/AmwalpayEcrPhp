<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/** No receipt to show: not found, not published, or not offered on this transport. Asking again is safe. */
final class ReceiptUnavailable extends EcrReceipt
{
    public function __construct(string $merchantReference, public readonly string $reason, public readonly string $raw)
    {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'unavailable';
    }

    public function toArray(): array
    {
        return ['outcome' => 'unavailable', 'merchantReference' => $this->merchantReference, 'reason' => $this->reason];
    }
}
