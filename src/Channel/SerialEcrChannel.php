<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Channel;

use AmwalPay\Ecr\Internal\NetText;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Serial\SerialLine;
use AmwalPay\Ecr\Serial\SerialLines;
use AmwalPay\Ecr\Serial\SerialPorts;

/**
 * The terminal on the USB virtual serial port the operating system assigned to
 * the cable.
 *
 * The line is 115200 baud, 8 data bits, no parity, 1 stop bit, which is what
 * "MediaTek USB VCOM (Android)" enumerates as. The bytes are the same framed
 * messages as Wi-Fi. The port is opened for each exchange and closed after it,
 * as the Windows SDK does.
 *
 * On Windows this needs PHP's FFI extension; see {@see \AmwalPay\Ecr\Serial\WindowsSerialLine}.
 */
final class SerialEcrChannel implements EcrChannel
{
    public const BAUD_RATE = 115200;

    private const NOT_ASSIGNED = 'Windows has not assigned a COM port to this terminal. Plug it in. The COM number follows the device serial and can change.';

    private readonly string $portName;
    private readonly \Closure $open;
    private readonly \Closure $presentNames;

    /**
     * @param string $portName `COM8`, or a device path such as `/dev/ttyACM0`.
     * @param (\Closure(string): SerialLine)|null $open Opens the port. Null: {@see SerialLines::open()}.
     * @param (\Closure(): list<string>)|null $presentNames The port names plugged in now. Null: {@see SerialPorts::presentNames()}.
     */
    public function __construct(string $portName, ?\Closure $open = null, ?\Closure $presentNames = null)
    {
        $this->portName = NetText::trim($portName);
        $this->open = $open ?? static fn (string $port): SerialLine => SerialLines::open($port, self::BAUD_RATE);
        $this->presentNames = $presentNames ?? static fn (): array => SerialPorts::presentNames();
    }

    public function endpoint(): string
    {
        return $this->portName !== '' ? $this->portName : 'USB cable';
    }

    public function host(): string
    {
        return $this->endpoint();
    }

    public function port(): int
    {
        return 0;
    }

    public function probe(float $timeout): ?string
    {
        if ($this->portName === '') {
            return self::NOT_ASSIGNED;
        }

        // Do not open the COM port here. Opening it and closing it drops DTR,
        // and the terminal's USB gadget treats that as a hangup. The sale that
        // follows is then written while the terminal has stopped reading.
        // exchange() opens the port and keeps it open for the write and the answer.
        try {
            $names = ($this->presentNames)();
        } catch (\Throwable) {
            return null;
        }
        foreach ($names as $name) {
            if (strcasecmp($name, $this->portName) === 0) {
                return null;
            }
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        return 'Windows is not listing ' . $this->portName . '. Plug the terminal in. The COM number follows the device serial and can change.';
    }

    public function exchange(string $body, float $timeout): string
    {
        if ($this->portName === '') {
            throw new EcrIOException(self::NOT_ASSIGNED);
        }
        // Framed first: a request too large for a frame is a local error, and
        // must not be reported as an answer that never came.
        $packet = EcrFrames::wrap($body);

        $line = ($this->open)($this->portName);
        try {
            try {
                self::discardStale($line);
            } catch (EcrIOException) {
                // Nothing has been written yet: the terminal cannot have acted,
                // so this must not read as a lost answer.
                throw new EcrIOException($this->portName . ' stopped answering before the request was sent');
            }
            $line->write($packet);
            return self::readFrame($line, $timeout, self::nonceOf($body));
        } finally {
            $line->close();
        }
    }

    /** Drops whatever an earlier exchange left in the port, so it cannot be read as this answer. */
    private static function discardStale(SerialLine $line): void
    {
        $until = microtime(true) + 1.0;
        while (microtime(true) < $until && $line->read(256, 0.05) !== '') {
        }
    }

    /**
     * Reads frames until one answers this request. A frame carrying another
     * request's nonce is an answer left over from an earlier exchange: skipped,
     * not believed.
     */
    private static function readFrame(SerialLine $line, float $timeout, string $expectedNonce): string
    {
        $pending = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new EcrChannelTimeout('the terminal did not answer in time');
            }
            $chunk = $line->read(4096, $remaining);
            if ($chunk === '') {
                continue;
            }
            $pending .= $chunk;

            while (\strlen($pending) >= EcrFrames::HEADER_BYTES) {
                $length = EcrFrames::bodyLength($pending);
                if ($length <= 0) {
                    throw new EcrIOException('Terminal returned an empty message');
                }
                if (\strlen($pending) < EcrFrames::HEADER_BYTES + $length) {
                    break;
                }
                $frame = substr($pending, EcrFrames::HEADER_BYTES, $length);
                $pending = (string) substr($pending, EcrFrames::HEADER_BYTES + $length);
                if ($expectedNonce === '') {
                    return $frame;
                }
                $answered = self::nonceOf($frame);
                if ($answered === '' || $answered === $expectedNonce) {
                    return $frame;
                }
            }
        }
    }

    private static function nonceOf(string $body): string
    {
        if ($body === '') {
            return '';
        }
        try {
            $nonce = Json::parseObject($body)->get('nonce');
        } catch (\Throwable) {
            return '';
        }
        return \is_string($nonce) ? $nonce : '';
    }
}
