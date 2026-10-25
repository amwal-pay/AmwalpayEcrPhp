<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

use AmwalPay\Ecr\Failure\Failure;

/** The lookup itself failed, so nothing was learned. Harmless to repeat: an inquiry moves no money. */
final class InquiryFailed extends EcrInquiry
{
    public function __construct(string $merchantReference, public readonly Failure $failure)
    {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'failed';
    }

    public function toArray(): array
    {
        return [
            'outcome' => 'failed',
            'merchantReference' => $this->merchantReference,
            'failure' => $this->failure->toArray(),
        ];
    }
}
