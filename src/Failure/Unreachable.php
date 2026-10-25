<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * Nothing is listening: wrong address, terminal off, or a different network.
 * Nothing was sent.
 */
final class Unreachable extends Failure
{
    public function outcomeUnknown(): bool
    {
        return false;
    }

    public function kind(): string
    {
        return 'unreachable';
    }
}
