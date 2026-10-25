<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Link;

/**
 * How this till reaches a POS terminal: {@see LanLink} (Wi-Fi/LAN) or
 * {@see WebServiceLink} (the Amwal Hub over HTTPS).
 *
 * The app supplies addressing and backend IDs; the SDK owns protocol choice,
 * signing and transport. USB cable is not offered by the PHP SDK.
 */
abstract class EcrLink
{
}
