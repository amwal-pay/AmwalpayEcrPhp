<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * The answer could not be read. The transaction may still have completed:
 * inquire before retrying.
 */
final class Malformed extends Failure
{
    public function kind(): string
    {
        return 'malformed';
    }
}
