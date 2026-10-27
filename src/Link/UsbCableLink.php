<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Link;

/**
 * A USB cable straight from this till to the terminal.
 *
 * `$comPort` is the virtual serial port the operating system assigned to that
 * cable, such as `COM8` ("MediaTek USB VCOM (Android)") or `/dev/ttyACM0`. The
 * line is 115200 8N1. Empty means the operator has not chosen a port yet. The
 * signing key is the Wi-Fi key.
 */
final class UsbCableLink extends EcrLink
{
    public function __construct(public readonly string $comPort = '')
    {
    }
}
