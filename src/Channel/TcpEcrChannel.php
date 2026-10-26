<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Channel;

use AmwalPay\Ecr\EcrConfig;

/**
 * The terminal over TCP, on the shop's Wi-Fi or LAN.
 *
 * One connection carries one request and one answer, which is how POS ECR ports
 * behave: connect, send, wait for the cardholder, read the result, disconnect.
 *
 * The connect budget and the answer budget are separate. Connecting is a
 * handshake on the local network and either happens quickly or the address is
 * wrong; nothing has been sent, so a connect failure is "unreachable". The long
 * wait belongs to the answer, which includes a cardholder presenting a card.
 */
final class TcpEcrChannel implements EcrChannel
{
    /** Prefer passing {@see EcrConfig::$connectTimeout}, as {@see \AmwalPay\Ecr\EcrTerminal} does. */
    public const DEFAULT_CONNECT_TIMEOUT = 3.0;

    private readonly float $connectTimeout;

    public function __construct(
        private readonly string $host,
        private readonly int $port = EcrConfig::DEFAULT_PORT,
        ?float $connectTimeout = null,
    ) {
        $this->connectTimeout = $connectTimeout ?? self::DEFAULT_CONNECT_TIMEOUT;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function endpoint(): string
    {
        return $this->host . ':' . $this->port;
    }

    public function probe(float $timeout): ?string
    {
        try {
            $socket = $this->connect($timeout);
        } catch (EcrIOException $e) {
            return $e->getMessage();
        }
        fclose($socket);
        return null;
    }

    public function exchange(string $body, float $timeout): string
    {
        // Framed first: a request too large for a frame is a local error, and
        // must not be reported as a connection the terminal dropped (which would
        // make the outcome look unknown) when nothing was ever sent.
        $packet = EcrFrames::wrap($body);

        $socket = $this->connect($this->connectTimeout);

        // From here the request may reach the terminal, so a failure is no
        // longer "nothing happened".
        $deadline = hrtime(true) + (int) ($timeout * 1e9);
        try {
            $this->writeAll($socket, $packet, $deadline);
            return EcrFrames::readBody(fn (int $count, string $what): string => $this->readExactly($socket, $count, $what, $deadline));
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource
     * @throws EcrIOException nothing was sent
     */
    private function connect(float $timeout)
    {
        $host = str_contains($this->host, ':') && !str_starts_with($this->host, '[') ? '[' . $this->host . ']' : $this->host;
        $context = stream_context_create(['socket' => ['tcp_nodelay' => true]]);
        $started = hrtime(true);
        $errno = 0;
        $errstr = '';
        $warning = null;
        set_error_handler(static function (int $type, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $socket = stream_socket_client(
                'tcp://' . $host . ':' . $this->port,
                $errno,
                $errstr,
                max($timeout, 0.001),
                \STREAM_CLIENT_CONNECT,
                $context,
            );
        } finally {
            restore_error_handler();
        }
        if ($socket === false) {
            $elapsed = (hrtime(true) - $started) / 1e9;
            if (self::isTimeoutCode($errno) || $elapsed >= $timeout * 0.95) {
                throw new EcrIOException(sprintf('Connection timed out after %d ms', (int) round($timeout * 1000)));
            }
            $reason = trim($errstr) !== '' ? trim($errstr) : self::lastReason($warning, 'Could not connect');
            throw new EcrIOException($reason);
        }
        stream_set_blocking($socket, true);
        return $socket;
    }

    /** @param resource $socket */
    private function writeAll($socket, string $packet, int $deadline): void
    {
        $offset = 0;
        $length = \strlen($packet);
        while ($offset < $length) {
            $this->setTimeout($socket, $deadline);
            $warning = null;
            set_error_handler(static function (int $type, string $message) use (&$warning): bool {
                $warning = $message;
                return true;
            });
            try {
                $written = fwrite($socket, substr($packet, $offset));
            } finally {
                restore_error_handler();
            }
            if ($written === false || $written === 0) {
                if (stream_get_meta_data($socket)['timed_out'] || hrtime(true) >= $deadline) {
                    throw new EcrChannelTimeout('the terminal did not answer in time');
                }
                throw new EcrIOException('Terminal closed the connection: ' . self::lastReason($warning, 'write failed'));
            }
            $offset += $written;
        }
        fflush($socket);
    }

    /** @param resource $socket */
    private function readExactly($socket, int $count, string $what, int $deadline): string
    {
        $buffer = '';
        while (\strlen($buffer) < $count) {
            if (hrtime(true) >= $deadline) {
                throw new EcrChannelTimeout('the terminal did not answer in time');
            }
            $this->setTimeout($socket, $deadline);
            $warning = null;
            set_error_handler(static function (int $type, string $message) use (&$warning): bool {
                $warning = $message;
                return true;
            });
            try {
                $chunk = fread($socket, $count - \strlen($buffer));
            } finally {
                restore_error_handler();
            }
            if ($chunk === false || $chunk === '') {
                // A read that ran out of time: on Windows fread() answers false,
                // elsewhere "". The deadline check above decides.
                if (stream_get_meta_data($socket)['timed_out']) {
                    continue;
                }
                // A reset or abort after the request went out: the terminal may
                // have acted on it, so this is a lost connection, not an address
                // that could not be reached.
                if ($chunk === false || $warning !== null) {
                    throw new EcrIOException('Terminal closed the connection: ' . self::lastReason($warning, 'read failed'));
                }
                if (feof($socket)) {
                    throw EcrFrames::closed($what);
                }
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /** @param resource $socket */
    private function setTimeout($socket, int $deadline): void
    {
        $remaining = max($deadline - hrtime(true), 1_000_000); // at least 1 ms
        stream_set_timeout($socket, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1000));
    }

    private static function isTimeoutCode(int $errno): bool
    {
        // WSAETIMEDOUT on Windows, ETIMEDOUT on Linux and macOS.
        return $errno === 10060 || $errno === 110 || $errno === 60;
    }

    /** The useful part of a PHP warning such as "fread(): ... errno=10054 An existing connection was ...". */
    private static function lastReason(?string $warning, string $fallback): string
    {
        if ($warning === null || trim($warning) === '') {
            return $fallback;
        }
        $text = preg_replace('/^[a-z_]+\(\):\s*/i', '', $warning) ?? $warning;
        return trim($text);
    }
}
