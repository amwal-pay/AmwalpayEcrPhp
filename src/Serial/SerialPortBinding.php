<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Serial;

/**
 * The COM port to open for a saved cable, and the identity to store so the
 * next sale follows the device when Windows assigns a different COM number.
 */
final class SerialPortBinding
{
    /**
     * @param string $port COM port to open now. Empty when that device is unplugged.
     * @param string $remember USB serial when the device has one, otherwise the saved COM name.
     */
    public function __construct(
        public readonly string $port,
        public readonly string $remember,
    ) {
    }
}
