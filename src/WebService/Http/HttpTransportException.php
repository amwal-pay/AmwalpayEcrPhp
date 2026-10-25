<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService\Http;

/**
 * No HTTP answer was read: DNS, TLS, connection refused or reset, or the
 * deadline passed. The request may have reached the Hub, so the SDK reads this
 * as an unknown outcome.
 */
final class HttpTransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
