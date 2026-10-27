<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Link;

/**
 * How this till reaches a POS terminal: {@see LanLink} (Wi-Fi),
 * {@see UsbCableLink} (a USB cable, as a serial port) or {@see WebServiceLink}
 * (the Amwal Hub over HTTPS).
 *
 * The app supplies addressing and backend IDs; the SDK owns protocol choice,
 * signing and transport.
 */
abstract class EcrLink
{
}
