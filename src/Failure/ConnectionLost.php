<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * The connection broke after the request went out. The transaction may still
 * have completed: inquire before retrying.
 */
final class ConnectionLost extends Failure
{
    public function kind(): string
    {
        return 'connection_lost';
    }
}
