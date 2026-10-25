<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * The terminal answered and refused, e.g. firmware too old for `CLOSE_RECEIPT`.
 * Treat it as "cannot be asked" and carry on; the receipt stays up.
 */
final class CloseReceiptRefused extends EcrReceiptClosed
{
    public function __construct(
        string $merchantReference,
        public readonly string $responseCode,
        public readonly string $reason,
        public readonly string $raw,
    ) {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'refused';
    }

    public function toArray(): array
    {
        return [
            'outcome' => 'refused',
            'merchantReference' => $this->merchantReference,
            'responseCode' => $this->responseCode,
            'reason' => $this->reason,
        ];
    }
}
