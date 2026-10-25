<?php

declare(strict_types=1);

namespace AmwalExample\Core;

/**
 * A registered POS terminal. The serial number is the unique identifier: the
 * transaction screen stores only the serial and looks connection details up
 * from here, so a terminal that moves to a new IP needs no change elsewhere.
 * Holds addresses only, never a key.
 */
final class Terminal
{
    public function __construct(
        public readonly string $serialNumber = '',
        public readonly string $name = '',
        public readonly int $ecrMode = 2,
        public readonly string $ipAddress = '',
        public readonly int $port = 0,
        public readonly string $terminalId = '',
        public readonly string $merchantId = '',
    ) {
    }

    public function mode(): EcrMode
    {
        return EcrMode::fromValue($this->ecrMode) ?? EcrMode::DEFAULT;
    }

    public function modeLabel(): string
    {
        return $this->mode()->label();
    }

    /** Label used in the terminal dropdown on the transaction screen. */
    public function displayName(): string
    {
        return $this->name . ' (' . $this->serialNumber . ')';
    }

    /** One-line connection summary for the terminals list. */
    public function connectionSummary(): string
    {
        switch ($this->mode()) {
            case EcrMode::WebService:
                $parts = [];
                if ($this->merchantId !== '') {
                    $parts[] = 'Merchant ' . $this->merchantId;
                }
                if ($this->terminalId !== '') {
                    $parts[] = 'Terminal ' . $this->terminalId;
                }
                return $parts === [] ? 'Web Service' : implode(' · ', $parts);
            case EcrMode::Bluetooth:
                return 'Bluetooth';
            case EcrMode::UsbCable:
                return 'USB cable';
            default:
                if ($this->ipAddress !== '' && $this->port > 0) {
                    return $this->ipAddress . ':' . $this->port;
                }
                return $this->ipAddress !== '' ? $this->ipAddress : $this->mode()->label();
        }
    }

    /** @return array<string, string|int> the stored shape, camelCase like the Windows samples' terminals.json */
    public function toArray(): array
    {
        return [
            'serialNumber' => $this->serialNumber,
            'name' => $this->name,
            'ecrMode' => $this->ecrMode,
            'ipAddress' => $this->ipAddress,
            'port' => $this->port,
            'terminalId' => $this->terminalId,
            'merchantId' => $this->merchantId,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $key): string => isset($data[$key]) && \is_scalar($data[$key]) ? (string) $data[$key] : '';
        return new self(
            $text('serialNumber'),
            $text('name'),
            (int) ($data['ecrMode'] ?? EcrMode::DEFAULT->value),
            $text('ipAddress'),
            (int) ($data['port'] ?? 0),
            $text('terminalId'),
            $text('merchantId'),
        );
    }
}
