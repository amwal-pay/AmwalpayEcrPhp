<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/** The terminal is on its idle screen. (`EcrReceiptClosed.Idle` in the Windows SDK.) */
final class ReceiptClosed extends EcrReceiptClosed
{
    public function __construct(string $merchantReference, public readonly string $raw)
    {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'idle';
    }

    public function toArray(): array
    {
        return ['outcome' => 'idle', 'merchantReference' => $this->merchantReference];
    }
}
