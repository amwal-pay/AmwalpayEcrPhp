<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Link;

use AmwalPay\Ecr\EcrConfig;

/**
 * Wi-Fi/LAN: TCP to the IP address and port the terminal shows on its idle
 * screen. The till and the terminal must be on the same network.
 */
final class LanLink extends EcrLink
{
    public function __construct(
        public readonly string $host,
        public readonly int $port = EcrConfig::DEFAULT_PORT,
    ) {
    }
}
