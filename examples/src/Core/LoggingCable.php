<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\Channel\EcrChannel;
use AmwalPay\Ecr\Log\EcrLogger;

/**
 * Writes the cable steps the serial port itself does not: which COM port was
 * opened, how many bytes went out, and whether any came back.
 */
final class LoggingCable implements EcrChannel
{
    public function __construct(private readonly EcrChannel $inner, private readonly EcrLogger $logger)
    {
    }

    public function endpoint(): string
    {
        return $this->inner->endpoint();
    }

    public function host(): string
    {
        return $this->inner->host();
    }

    public function port(): int
    {
        return $this->inner->port();
    }

    public function probe(float $timeout): ?string
    {
        $problem = $this->inner->probe($timeout);
        $this->logger->debug($problem === null
            ? '[USB] ' . $this->inner->endpoint() . ' is listed. The COM port stays closed until the sale is written.'
            : '[USB] probe: ' . $problem);
        return $problem;
    }

    public function exchange(string $body, float $timeout): string
    {
        $this->logger->debug('[USB] writing ' . \strlen($body) . ' bytes to ' . $this->inner->endpoint());
        try {
            $answer = $this->inner->exchange($body, $timeout);
            $this->logger->debug('[USB] read ' . \strlen($answer) . ' bytes from ' . $this->inner->endpoint());
            return $answer;
        } catch (\Throwable $e) {
            $this->logger->debug('[USB] ' . $this->inner->endpoint() . ' — ' . $e::class . ': ' . $e->getMessage());
            throw $e;
        }
    }
}
