<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Log;

/**
 * Where the SDK writes its diagnostics. Off until a logger is passed.
 *
 * Lines carry request and response payloads: masked card number, amount,
 * reference. The signing secret is never written; card numbers and sensitive
 * fields are masked by {@see Redactor} before a line reaches the logger. Treat
 * the lines as transaction records.
 *
 * ```php
 * $logger = EcrLogger::from(fn (string $line) => error_log($line));
 * ```
 */
abstract class EcrLogger
{
    abstract protected function write(string $line): void;

    /** Writes one diagnostic line, redacted. */
    final public function debug(string $message): void
    {
        $this->write(Redactor::line($message));
    }

    /** Discards everything. The default. */
    public static function none(): self
    {
        return new NullEcrLogger();
    }

    /** Wraps a callable that takes one line. An exception thrown by it escapes the SDK call. */
    public static function from(callable $sink): self
    {
        return new CallableEcrLogger($sink);
    }

    /** Wraps a PSR-3 logger (anything with a `debug(string, array)` method). */
    public static function psr(object $logger): self
    {
        return new PsrLoggerAdapter($logger);
    }
}
