<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/** The receipt is published. Put {@see $url} in a QR code as it stands. */
final class ReceiptReady extends EcrReceipt
{
    public function __construct(string $merchantReference, public readonly string $url, public readonly string $raw)
    {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'ready';
    }

    public function toArray(): array
    {
        return ['outcome' => 'ready', 'merchantReference' => $this->merchantReference, 'url' => $this->url];
    }
}
