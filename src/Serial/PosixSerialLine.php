<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Serial;

use AmwalPay\Ecr\Channel\EcrFrames;
use AmwalPay\Ecr\Channel\EcrIOException;

/**
 * A serial device on Linux or macOS (`/dev/ttyACM0`, `/dev/cu.usbmodem1`),
 * set to raw 8N1 with `stty` on the open descriptor, so the setting lasts as
 * long as the port is open.
 */
final class PosixSerialLine implements SerialLine
{
    private const WRITE_TIMEOUT = 3.0;

    /** @var resource|null */
    private $stream;

    /** @param resource $stream */
    private function __construct($stream, private readonly string $port)
    {
        $this->stream = $stream;
    }

    /** @throws EcrIOException */
    public static function open(string $port, int $baudRate = 115200): self
    {
        $stream = @fopen($port, 'r+b');
        if ($stream === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new EcrIOException('Could not open ' . $port . ': ' . $error);
        }
        $line = new self($stream, $port);
        try {
            $line->configure($baudRate);
            stream_set_blocking($stream, false);
            stream_set_write_buffer($stream, 0);
            stream_set_read_buffer($stream, 0);
        } catch (EcrIOException $e) {
            $line->close();
            throw $e;
        }
        return $line;
    }

    public function write(string $bytes): void
    {
        $stream = $this->stream();
        $deadline = microtime(true) + self::WRITE_TIMEOUT;
        while ($bytes !== '') {
            $remaining = $deadline - microtime(true);
            $write = [$stream];
            $none = null;
            if ($remaining <= 0 || @stream_select($none, $write, $none, 0, (int) ($remaining * 1e6)) !== 1) {
                throw new EcrIOException('The terminal did not take the request on ' . $this->port);
            }
            $sent = @fwrite($stream, $bytes);
            if ($sent === false) {
                throw new EcrIOException('Could not write to ' . $this->port);
            }
            $bytes = (string) substr($bytes, $sent);
        }
    }

    public function read(int $maxBytes, float $timeout): string
    {
        $stream = $this->stream();
        $read = [$stream];
        $none = null;
        $seconds = (int) floor(max(0.0, $timeout));
        $micros = (int) round((max(0.0, $timeout) - $seconds) * 1e6);
        $ready = @stream_select($read, $none, $none, $seconds, $micros);
        if ($ready === false) {
            throw EcrFrames::closed('response');
        }
        if ($ready === 0) {
            return '';
        }
        $chunk = @fread($stream, $maxBytes);
        if ($chunk === false || ($chunk === '' && feof($stream))) {
            // The request may already be with the terminal: the outcome is unknown.
            throw EcrFrames::closed('response');
        }
        return $chunk;
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return resource */
    private function stream()
    {
        if (!\is_resource($this->stream)) {
            throw new EcrIOException($this->port . ' is closed');
        }
        return $this->stream;
    }

    /** `stty` reads the port it sets from its standard input: the descriptor already open. */
    private function configure(int $baudRate): void
    {
        $process = @proc_open(
            ['stty', (string) $baudRate, 'cs8', '-cstopb', '-parenb', '-crtscts', '-ixon', '-ixoff', 'clocal', 'raw', '-echo'],
            [0 => $this->stream(), 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            throw new EcrIOException('Could not run stty to set ' . $this->port . ' to ' . $baudRate . ' 8N1');
        }
        fclose($pipes[1]);
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new EcrIOException('Could not set ' . $this->port . ' to ' . $baudRate . ' 8N1' . ($error !== '' ? ': ' . $error : ''));
        }
    }
}
