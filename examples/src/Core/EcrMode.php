<?php

declare(strict_types=1);

namespace AmwalExample\Core;

/**
 * How an ECR reaches the POS terminal: TMS `ecrMode` values
 * 1 USB cable, 2 Wi-Fi, 3 Bluetooth, 4 Web Service.
 *
 * All four stay registrable so a terminal list matches the Android and Windows
 * samples; the PHP SDK opens Wi-Fi and Web Service only.
 */
enum EcrMode: int
{
    case UsbCable = 1;
    case Wifi = 2;
    case Bluetooth = 3;
    case WebService = 4;

    public const DEFAULT = self::Wifi;

    /** @return list<self> */
    public static function all(): array
    {
        return [self::UsbCable, self::Wifi, self::Bluetooth, self::WebService];
    }

    public function label(): string
    {
        return match ($this) {
            self::UsbCable => 'USB Cable',
            self::Wifi => "Wi\u{2011}Fi",
            self::Bluetooth => 'Bluetooth',
            self::WebService => 'Web Service',
        };
    }

    /** Whether the terminal is reached over a socket. Wi-Fi alone: a USB cable carries no IP. */
    public function isIpBased(): bool
    {
        return $this === self::Wifi;
    }

    /** Whether this sample can run transactions over the mode. */
    public function isSupportedInExample(): bool
    {
        return $this === self::Wifi || $this === self::WebService;
    }

    public static function fromValue(int $value): ?self
    {
        return self::tryFrom($value);
    }
}
