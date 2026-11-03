<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Usb;

/** One USB device or interface on the bus. `$id` is what the bus opens it by. */
final class UsbPlug
{
    public function __construct(
        public readonly string $id,
        public readonly int $vendorId,
        public readonly int $productId,
    ) {
    }
}
