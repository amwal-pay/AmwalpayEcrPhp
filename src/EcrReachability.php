<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

/**
 * Whether the terminal's ECR port answers a connection. It proves the port is
 * open, not that the terminal is idle: a terminal already taking a payment
 * answers too.
 */
final class EcrReachability
{
    public readonly string $endpoint;

    public function __construct(
        public readonly bool $reachable,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $error = null,
        ?string $endpoint = null,
    ) {
        $this->endpoint = $endpoint ?? ($port > 0 ? $host . ':' . $port : $host);
    }

    /** @return array{reachable: bool, host: string, port: int, error: ?string, endpoint: string} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
