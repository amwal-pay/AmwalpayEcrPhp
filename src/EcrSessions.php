<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Channel\EcrChannel;
use AmwalPay\Ecr\Internal\MerchantReference;
use AmwalPay\Ecr\Internal\Validate;
use AmwalPay\Ecr\Link\EcrLink;
use AmwalPay\Ecr\Link\LanLink;
use AmwalPay\Ecr\Link\UsbCableLink;
use AmwalPay\Ecr\Link\WebServiceLink;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Safety\FinancialGuard;
use AmwalPay\Ecr\WebService\Http\HttpTransport;

/**
 * The way in: validate a link and its credentials into a plan, then open one
 * session for it.
 *
 * ```php
 * $plan = EcrSessions::plan(new LanLink('192.168.1.50'), new EcrConfig(secureHashKey: $wifiKey));
 * if (!$plan->isReady()) {
 *     exit(implode("\n", $plan->issues()));
 * }
 * $session = EcrSessions::open('SN-WIFI-0001', $plan);
 * ```
 */
final class EcrSessions
{
    /**
     * Validates `$link` and `$config` without opening a connection. Returns the
     * issues rather than throwing, so a settings screen can show what is missing;
     * it throws only for a link class it does not know.
     *
     * @param string|null $rawSecureHashKey The key as the operator typed it; checked and trimmed here.
     *        Defaults to the config's key.
     */
    public static function plan(EcrLink $link, EcrConfig $config, #[\SensitiveParameter] ?string $rawSecureHashKey = null): EcrSessionPlan
    {
        $issues = [];

        $key = trim($rawSecureHashKey ?? $config->secureHashKey);
        $keyValid = EcrConfig::isValidSecureHashKey($key);
        if ($key !== '' && !$keyValid) {
            $issues[] = 'Secure hash key must be an even-length hex string of at least 16 characters';
        }
        $resolved = $config->with(secureHashKey: $key !== '' && $keyValid ? $key : '');

        if ($link instanceof LanLink) {
            if (Validate::isBlank($link->host)) {
                $issues[] = 'IP address is required for Wi-Fi ECR';
            }
            if ($link->port < 1 || $link->port > 65535) {
                $issues[] = 'Port must be between 1 and 65535 (default ' . EcrConfig::DEFAULT_PORT . ')';
            } else {
                // The link's port wins: it is what the terminal shows on its screen.
                $resolved = $resolved->with(port: $link->port);
            }
        } elseif ($link instanceof WebServiceLink) {
            $merchantId = $link->merchantId;
            $terminalId = $link->terminalId;
            if (Validate::isBlank($merchantId)) {
                $issues[] = 'Merchant ID is required for Web Service';
            }
            if (Validate::isBlank($terminalId)) {
                $issues[] = 'Terminal ID is required for Web Service';
            }
            if (!Validate::isBlank($merchantId) && EcrWebServiceTerminal::id($merchantId) === null) {
                $issues[] = 'Merchant ID must be numeric';
            }
            if (!Validate::isBlank($terminalId) && EcrWebServiceTerminal::id($terminalId) === null) {
                $issues[] = 'Terminal ID must be numeric';
            }
            // Web Service HTTP read timeout: 130 s unless the caller already
            // overrode the Wi-Fi default (120 s).
            $responseTimeout = $resolved->responseTimeout === EcrConfig::DEFAULT_RESPONSE_TIMEOUT
                ? EcrConfig::WEB_SERVICE_RESPONSE_TIMEOUT
                : $resolved->responseTimeout;
            $resolved = $resolved->with(merchantId: $merchantId, terminalId: $terminalId, responseTimeout: $responseTimeout);
        } elseif ($link instanceof UsbCableLink) {
            // Nothing to validate: a cable has no address to get wrong. Whether
            // one is plugged in is answered by the probe, not by configuration.
        } else {
            throw new \InvalidArgumentException('Unsupported ECR link: ' . $link::class);
        }

        if ($resolved->secureHashKey === '') {
            $issues[] = EcrSessionPlan::keyLabelFor($link) . ' is not configured';
        }

        return new EcrSessionPlan($link, $resolved, array_values(array_unique($issues)));
    }

    /**
     * Opens the transport `$plan` describes. One dispatch for Wi-Fi, USB cable
     * and Web Service, so sale, inquiry and recovery cannot diverge on which
     * client they build.
     *
     * @param string $terminalSerial The terminal's serial number, as registered.
     * @param HttpTransport|null $httpTransport Web Service only: how the HTTPS post is made. Null: cURL.
     * @param FinancialGuard|null $guard The payment-safety guard. Null: file-based, from the config. Pass one
     *        built on a shared journal and lock when several servers drive the same terminal.
     * @param (\Closure(): EcrChannel)|null $usbChannel Required for a USB cable plan, ignored otherwise:
     *        builds the channel, e.g. `fn () => new SerialEcrChannel('COM8')`.
     * @throws \InvalidArgumentException the plan has issues
     * @throws \LogicException a USB cable plan without `$usbChannel`
     */
    public static function open(
        string $terminalSerial,
        EcrSessionPlan $plan,
        ?EcrLogger $logger = null,
        ?HttpTransport $httpTransport = null,
        ?FinancialGuard $guard = null,
        ?\Closure $usbChannel = null,
    ): EcrOpenedSession {
        if (!$plan->isReady()) {
            throw new \InvalidArgumentException('Cannot open a session with unresolved issues: ' . implode(', ', $plan->issues()));
        }
        $logger ??= EcrLogger::none();
        if ($plan->usesUsbCable()) {
            $channel = $usbChannel !== null ? $usbChannel() : null;
            if (!$channel instanceof EcrChannel) {
                throw new \LogicException('USB cable sessions require a channel factory');
            }
            return new EcrOpenedSession($plan, self::usbCableTerminal($terminalSerial, $plan, $channel, $logger, $guard), null);
        }
        if ($plan->usesLan()) {
            return new EcrOpenedSession($plan, self::lanTerminal($terminalSerial, $plan, $logger, $guard), null);
        }
        if ($plan->usesWebService()) {
            return new EcrOpenedSession($plan, null, self::webServiceTerminal($terminalSerial, $plan, $logger, $httpTransport, $guard));
        }
        throw new \InvalidArgumentException('Unsupported ECR link: ' . $plan->link::class);
    }

    public static function lanTerminal(string $terminalSerial, EcrSessionPlan $plan, ?EcrLogger $logger = null, ?FinancialGuard $guard = null): EcrTerminal
    {
        if (!$plan->link instanceof LanLink) {
            throw new \InvalidArgumentException('The plan is not for Wi-Fi ECR');
        }
        return new EcrTerminal($plan->link->host, $terminalSerial, $plan->config, $logger, $guard);
    }

    /**
     * A terminal reached down a USB cable.
     *
     * The channel comes from the caller because finding a cable is a platform
     * job. Everything above the channel is identical to {@see self::lanTerminal()}:
     * the same signing, the same messages, the same answers.
     */
    public static function usbCableTerminal(
        string $terminalSerial,
        EcrSessionPlan $plan,
        EcrChannel $channel,
        ?EcrLogger $logger = null,
        ?FinancialGuard $guard = null,
    ): EcrTerminal {
        if (!$plan->usesUsbCable()) {
            throw new \InvalidArgumentException('The plan is not for USB cable ECR');
        }
        return new EcrTerminal($channel, $terminalSerial, $plan->config, $logger, $guard);
    }

    public static function webServiceTerminal(
        string $terminalSerial,
        EcrSessionPlan $plan,
        ?EcrLogger $logger = null,
        ?HttpTransport $httpTransport = null,
        ?FinancialGuard $guard = null,
    ): EcrWebServiceTerminal {
        if (!$plan->usesWebService()) {
            throw new \InvalidArgumentException('The plan is not for Web Service ECR');
        }
        return new EcrWebServiceTerminal($terminalSerial, $plan->config, $logger, $httpTransport, $guard);
    }

    /**
     * A fresh merchant reference in the SDK's own shape (12 uppercase hex
     * characters). Make it, save it with the order, then send it.
     */
    public static function newMerchantReference(): string
    {
        return MerchantReference::generate();
    }
}
