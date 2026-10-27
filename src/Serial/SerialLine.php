<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Serial;

use AmwalPay\Ecr\Channel\EcrIOException;

/**
 * An open serial port: raw bytes in and out, nothing else. Framing, the answer
 * deadline and the nonce match belong to
 * {@see \AmwalPay\Ecr\Channel\SerialEcrChannel}, so a test can stand in for the
 * port with a script.
 */
interface SerialLine
{
    /**
     * Writes every byte of `$bytes`, or throws.
     *
     * @throws EcrIOException the port would not take the bytes
     */
    public function write(string $bytes): void;

    /**
     * Returns the bytes available, up to `$maxBytes`, waiting at most `$timeout`
     * seconds for the first one. Empty when nothing arrived in time.
     *
     * @throws EcrIOException the port went away
     */
    public function read(int $maxBytes, float $timeout): string;

    /** Closes the port. Safe to call twice. */
    public function close(): void;
}
