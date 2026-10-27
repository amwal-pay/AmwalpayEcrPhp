<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Serial;

use AmwalPay\Ecr\Internal\NetText;

/**
 * One serial device the operating system may assign a COM port. `$name` is the
 * port assigned right now. `$deviceSerial` is the USB serial Plug and Play
 * remembers, and {@see self::selection()} is what the till stores.
 */
final class SerialPortChoice
{
    /** The COM port assigned at this moment, such as `COM3` or `/dev/ttyACM0`. */
    public readonly string $name;

    /** Device Manager text when Windows has it, otherwise the name. */
    public readonly string $label;

    /**
     * USB serial from the Plug and Play instance. Empty when the device has no
     * serial and the COM port follows the USB socket instead.
     */
    public readonly string $deviceSerial;

    public function __construct(string $name, string $label, string $deviceSerial = '')
    {
        $this->name = $name;
        $this->deviceSerial = $deviceSerial;
        $this->label = NetText::isBlank($label) ? $name : $label;
    }

    /** Stable identity to save: the device serial when it has one, otherwise the COM name. */
    public function selection(): string
    {
        return $this->deviceSerial !== '' ? $this->deviceSerial : $this->name;
    }

    /** @return array{name: string, label: string, deviceSerial: string, selection: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'label' => $this->label, 'deviceSerial' => $this->deviceSerial, 'selection' => $this->selection()];
    }
}
