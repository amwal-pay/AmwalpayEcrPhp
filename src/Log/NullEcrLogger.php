<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Log;

/** Discards every line. */
final class NullEcrLogger extends EcrLogger
{
    protected function write(string $line): void
    {
    }
}
