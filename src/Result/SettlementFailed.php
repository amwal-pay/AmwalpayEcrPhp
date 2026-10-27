<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

use AmwalPay\Ecr\Failure\Failure;

/**
 * The exchange failed. The batch may or may not have closed; repeating the
 * call is safe. (`EcrSettlement.Failed` in the Windows SDK.)
 */
final class SettlementFailed extends EcrSettlement
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
