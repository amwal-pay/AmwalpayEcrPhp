<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/** The terminal is idle and the batch had nothing in it. (`EcrSettlement.NothingToSettle` in the Windows SDK.) */
final class NothingToSettle extends EcrSettlement
{
    public function __construct(
        string $merchantReference,
        public readonly string $message,
        public readonly string $raw,
    ) {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'nothing_to_settle';
    }

    public function toArray(): array
    {
        return ['outcome' => 'nothing_to_settle', 'merchantReference' => $this->merchantReference, 'message' => $this->message];
    }
}
