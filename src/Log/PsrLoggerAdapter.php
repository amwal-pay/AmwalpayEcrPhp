<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Log;

/**
 * Sends each redacted line to a PSR-3 logger at debug level, without making
 * `psr/log` a dependency of the SDK.
 */
final class PsrLoggerAdapter extends EcrLogger
{
    public function __construct(private readonly object $logger)
    {
        if (!method_exists($logger, 'debug')) {
            throw new \InvalidArgumentException('The logger must have a debug(string $message, array $context) method');
        }
    }

    protected function write(string $line): void
    {
        $this->logger->debug($line, ['channel' => 'amwalpay-ecr']);
    }
}
