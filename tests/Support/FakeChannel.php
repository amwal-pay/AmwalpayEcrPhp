<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Support;

use AmwalPay\Ecr\Channel\EcrChannel;
use AmwalPay\Ecr\Channel\EcrIOException;

/**
 * A scripted channel, so terminal logic runs without a socket.
 *
 * The script sees each request as text plus the zero-based number of the
 * exchange and returns the answer body. Returning null is a connection dropped
 * without a reply, which is how a Wi-Fi failure looks from the till; the script
 * may also throw anything a real channel could.
 */
final class FakeChannel implements EcrChannel
{
    public const DROPPED = 'Terminal closed the connection while sending the response header';

    /** @var list<string> */
    public array $requests = [];
    /** @var list<float> */
    public array $timeouts = [];
    public ?string $probeError = null;

    /** @param \Closure(string, int): ?string $script */
    public function __construct(private readonly \Closure $script)
    {
    }

    public static function always(?string $answer): self
    {
        return new self(static fn (): ?string => $answer);
    }

    public function endpoint(): string
    {
        return 'fake:9100';
    }

    public function host(): string
    {
        return 'fake';
    }

    public function port(): int
    {
        return 9100;
    }

    public function probe(float $timeout): ?string
    {
        return $this->probeError;
    }

    public function exchange(string $body, float $timeout): string
    {
        $connection = \count($this->requests);
        $this->requests[] = $body;
        $this->timeouts[] = $timeout;
        $reply = ($this->script)($body, $connection);
        if ($reply === null) {
            throw new EcrIOException(self::DROPPED);
        }
        return $reply;
    }
}
