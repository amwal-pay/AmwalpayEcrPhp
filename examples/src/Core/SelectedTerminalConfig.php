<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\EcrSessionPlan;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Link\EcrLink;
use AmwalPay\Ecr\Link\LanLink;
use AmwalPay\Ecr\Link\UsbCableLink;
use AmwalPay\Ecr\Link\WebServiceLink;
use AmwalPay\Ecr\Serial\SerialPorts;

/**
 * What the SDK plan makes of a registered terminal, before anyone pays.
 * Transport rules live in {@see EcrSessions::plan()}; this maps the stored
 * record to a link and supplies the key for its mode.
 */
final class SelectedTerminalConfig
{
    /** @param list<string> $issues */
    private function __construct(
        public readonly Terminal $terminal,
        public readonly EcrSessionPlan $plan,
        private readonly array $issues,
    ) {
    }

    public static function resolve(
        Terminal $terminal,
        EcrEnvironment $environment,
        #[\SensitiveParameter] string $secureHashKey,
        ?string $stateDirectory = null,
    ): self {
        $issues = [];
        if (!$terminal->mode()->isSupportedInExample()) {
            $issues[] = $terminal->mode()->label() . ' is not supported in this sample';
        }
        $cablePort = '';
        if ($terminal->mode()->isUsbCable()) {
            $cablePort = SerialPorts::portFor($terminal->comPort)->port;
            if (trim($terminal->comPort) === '') {
                $issues[] = 'Choose the USB device this cable uses';
            } elseif ($cablePort === '') {
                $issues[] = 'Plug in device ' . trim($terminal->comPort) . '. Windows assigns its COM port from that serial, and the number can change.';
            }
        }
        $trimmed = trim($secureHashKey);
        $config = new EcrConfig(
            secureHashKey: EcrConfig::isValidSecureHashKey($trimmed) ? $trimmed : '',
            merchantId: $terminal->merchantId,
            terminalId: $terminal->terminalId,
            environment: $environment,
            port: self::portOrDefault($terminal->port),
            stateDirectory: $stateDirectory,
        );
        $plan = EcrSessions::plan(self::toLink($terminal, $cablePort), $config, $trimmed);
        return new self($terminal, $plan, array_values(array_merge($plan->issues(), $issues)));
    }

    public function mode(): EcrMode
    {
        return $this->terminal->mode();
    }

    public function modeLabel(): string
    {
        return $this->mode()->label();
    }

    public function ecrConfig(): EcrConfig
    {
        return $this->plan->config;
    }

    /** @return list<string> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function usesWebService(): bool
    {
        return $this->plan->usesWebService();
    }

    public function usesLan(): bool
    {
        return $this->plan->usesLan();
    }

    public function isSupported(): bool
    {
        return $this->mode()->isSupportedInExample();
    }

    public function isReady(): bool
    {
        return $this->isSupported() && $this->issues === [];
    }

    public function hashKeyLabel(): string
    {
        return $this->plan->signingKeyLabel();
    }

    public function hashKeyConfigured(): bool
    {
        return $this->plan->signingKeyConfigured();
    }

    public function connectionSummary(): string
    {
        return $this->plan->connectionSummary();
    }

    /** "TCP Wi-Fi" / "HTTPS Web Service...", as the config card shows it. */
    public function transportLabel(): string
    {
        return match (true) {
            $this->usesWebService() => 'HTTPS Web Service (JSON HMAC)',
            $this->usesLan() && $this->isSupported() => 'TCP Wi-Fi (sorted key=value HMAC)',
            default => $this->mode()->label(),
        };
    }

    private static function toLink(Terminal $terminal, string $cablePort): EcrLink
    {
        return match ($terminal->mode()) {
            EcrMode::WebService => new WebServiceLink($terminal->merchantId, $terminal->terminalId),
            EcrMode::UsbCable => new UsbCableLink($cablePort),
            EcrMode::Wifi => new LanLink($terminal->ipAddress, self::portOrDefault($terminal->port)),
            default => new LanLink($terminal->ipAddress, EcrConfig::DEFAULT_PORT),
        };
    }

    private static function portOrDefault(int $port): int
    {
        return $port >= 1 && $port <= 65535 ? $port : EcrConfig::DEFAULT_PORT;
    }
}
