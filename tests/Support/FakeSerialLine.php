<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Support;

use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\Serial\SerialLine;

/**
 * A scripted serial port. Before the request is written it hands out `$stale`
 * (bytes an earlier exchange left behind); after it, the script's answer for
 * the bytes written, `$chunkBytes` at a time, as a USB VCOM delivers them. An
 * answer that is a Throwable is thrown from the read instead.
 */
final class FakeSerialLine implements SerialLine
{
    public string $written = '';
    public int $writes = 0;
    public bool $closed = false;
    private string $pending;
    private bool $answered = false;

    /** @param \Closure(string): (string|\Throwable) $answer */
    public function __construct(
        private readonly \Closure $answer,
        string $stale = '',
        private readonly int $chunkBytes = 3,
    ) {
        $this->pending = $stale;
    }

    public function write(string $bytes): void
    {
        $this->written .= $bytes;
        $this->writes++;
    }

    public function read(int $maxBytes, float $timeout): string
    {
        if ($this->writes > 0 && !$this->answered) {
            $this->answered = true;
            $reply = ($this->answer)($this->written);
            if ($reply instanceof \Throwable) {
                throw $reply;
            }
            $this->pending .= $reply;
        }
        if ($this->pending === '') {
            usleep((int) (min($timeout, 0.01) * 1e6));
            return '';
        }
        $chunk = substr($this->pending, 0, min($maxBytes, $this->chunkBytes));
        $this->pending = (string) substr($this->pending, \strlen($chunk));
        return $chunk;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public static function failingOpen(string $message): \Closure
    {
        return static fn () => throw new EcrIOException($message);
    }
}
