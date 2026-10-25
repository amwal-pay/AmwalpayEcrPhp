<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Log;

/** Hands each redacted line to a callable. */
final class CallableEcrLogger extends EcrLogger
{
    /** @var callable(string): mixed */
    private $sink;

    public function __construct(callable $sink)
    {
        $this->sink = $sink;
    }

    protected function write(string $line): void
    {
        ($this->sink)($line);
    }
}
