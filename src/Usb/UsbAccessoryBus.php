<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Usb;

use AmwalPay\Ecr\Channel\EcrIOException;

/** The USB bus of this machine, as {@see \AmwalPay\Ecr\Channel\UsbAccessoryEcrChannel} needs it. */
interface UsbAccessoryBus
{
    /** Why this process cannot open a cable, or null when it can try. */
    public function unavailable(): ?string;

    /** What to tell the operator when {@see self::list()} is empty. */
    public function nothingPluggedIn(): string;

    /**
     * @return list<UsbPlug>
     * @throws EcrIOException
     */
    public function list(): array;

    /** @throws EcrIOException */
    public function open(UsbPlug $plug): UsbPipe;
}
