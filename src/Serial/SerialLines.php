<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Serial;

use AmwalPay\Ecr\Channel\EcrIOException;

/** Opens a serial port at 115200 baud, 8 data bits, no parity, 1 stop bit, with DTR and RTS on. */
final class SerialLines
{
    /**
     * `COM8` on Windows (through PHP's FFI extension), a device path such as
     * `/dev/ttyACM0` elsewhere.
     *
     * @throws EcrIOException the port could not be opened; nothing was sent
     */
    public static function open(string $port, int $baudRate = 115200): SerialLine
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? WindowsSerialLine::open($port, $baudRate)
            : PosixSerialLine::open($port, $baudRate);
    }
}
