<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * The request could not be built: a configuration problem found before
 * anything was sent, such as non-numeric Web Service IDs. Nothing was sent.
 */
final class Invalid extends Failure
{
    public function outcomeUnknown(): bool
    {
        return false;
    }

    public function kind(): string
    {
        return 'invalid';
    }
}
