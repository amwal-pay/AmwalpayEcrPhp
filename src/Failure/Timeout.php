<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * The request went out and no answer came in time. The transaction may still
 * have completed: inquire before retrying.
 */
final class Timeout extends Failure
{
    public function kind(): string
    {
        return 'timeout';
    }
}
