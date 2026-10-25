<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * An earlier sale, void or refund on this terminal has an unknown outcome and
 * must be reconciled by inquiry before a new one is sent. Nothing was sent.
 * Added by the PHP SDK.
 */
final class Unresolved extends Failure
{
    public function outcomeUnknown(): bool
    {
        return false;
    }

    public function kind(): string
    {
        return 'unresolved';
    }
}
