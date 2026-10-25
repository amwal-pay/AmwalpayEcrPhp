<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\Log\Redactor;

/**
 * The last {@see CAPACITY} lines of SDK diagnostics, shared by the web requests
 * and the transaction workers through one file. Each line is stamped
 * `HH:mm:ss.fff` in server local time, then a tag (`Ecr`, `EcrWebService`).
 */
final class LogBuffer
{
    public const CAPACITY = 500;

    public function __construct(private readonly string $path)
    {
    }

    public function append(string $tag, string $message): void
    {
        $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)))
            ?: new \DateTimeImmutable();
        $now = $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        // One line per entry: a multi-line message keeps its lines together under one stamp.
        $line = $now->format('H:i:s.v') . ' ' . $tag . ' ' . str_replace(["\r\n", "\n", "\r"], ' ', Redactor::line($message));
        $this->withLock(function () use ($line): void {
            $lines = $this->read();
            $lines[] = $line;
            if (\count($lines) > self::CAPACITY) {
                $lines = \array_slice($lines, -self::CAPACITY);
            }
            @file_put_contents($this->path, implode("\n", $lines) . "\n");
        });
    }

    public function clear(): void
    {
        $this->withLock(fn () => @file_put_contents($this->path, ''));
    }

    public function snapshot(): string
    {
        $lines = $this->read();
        return $lines === [] ? '' : implode(\PHP_EOL, $lines) . \PHP_EOL;
    }

    public function count(): int
    {
        return \count($this->read());
    }

    /** @return list<string> */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $text = @file_get_contents($this->path);
        if ($text === false || $text === '') {
            return [];
        }
        return array_values(array_filter(explode("\n", $text), static fn (string $l): bool => $l !== ''));
    }

    private function withLock(callable $work): void
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            return; // a log that cannot be written must never break a payment
        }
        try {
            flock($lock, \LOCK_EX);
            $work();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }
}
