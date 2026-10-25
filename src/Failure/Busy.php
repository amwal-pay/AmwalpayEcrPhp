<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * Another sale, void or refund is already running on this terminal, from this
 * process or another. Nothing was sent. Added by the PHP SDK: a PHP server
 * runs many requests at once.
 */
final class Busy extends Failure
{
    public function outcomeUnknown(): bool
    {
        return false;
    }

    public function kind(): string
    {
        return 'busy';
    }
}
