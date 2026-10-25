<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService;

use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\Internal\LanLog;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Log\EcrLogger;

/**
 * Request and response lines for Web Service calls, in the Windows SDK's
 * wording. The secret appears only as "configured".
 *
 * @internal
 */
final class WebServiceHttpLog
{
    private const PREFIX = '[Web Service]';

    public static function request(EcrLogger $logger, WebServiceOperation $operation, string $url, EcrEnvironment $environment, string $payload): void
    {
        $logger->debug(self::PREFIX . ' ========== REQUEST ==========');
        $logger->debug(sprintf('%s %s POST %s (env=%s)', self::PREFIX, strtoupper($operation->label()), $url, $environment->value));
        self::payload($logger, 'REQUEST PAYLOAD', $payload);
    }

    public static function signing(EcrLogger $logger, string $payloadToSign, string $hash): void
    {
        $logger->debug(self::PREFIX . ' calcHash: HMAC-SHA256(sorted key=value payload, Hex.parse(secret)) → uppercase hex');
        $logger->debug(self::PREFIX . ' signing key (' . WebServiceSecureHash::KEY_LABEL . '): ' . LanLog::fingerprint(true));
        $logger->debug(self::PREFIX . ' payload to sign (excl. secureHashValue): ' . $payloadToSign);
        $logger->debug(self::PREFIX . ' secureHashValue: ' . $hash);
    }

    public static function response(EcrLogger $logger, WebServiceOperation $operation, int $status, int $elapsedMs, string $body): void
    {
        $logger->debug(self::PREFIX . ' ========== RESPONSE ==========');
        $logger->debug(sprintf('%s %s HTTP %d (%dms)', self::PREFIX, strtoupper($operation->label()), $status, $elapsedMs));
        self::payload($logger, 'RESPONSE PAYLOAD', $body);
    }

    public static function error(EcrLogger $logger, WebServiceOperation $operation, string $message, ?int $elapsedMs = null): void
    {
        $timing = $elapsedMs === null ? '' : ' (' . $elapsedMs . 'ms)';
        $logger->debug(self::PREFIX . ' ✗ ' . strtoupper($operation->label()) . $timing . ' — ' . $message);
    }

    public static function operation(EcrLogger $logger, string $messageType, string $reference, string $terminalSerial, int $merchantId, int $terminalId): void
    {
        $logger->debug(sprintf(
            '%s sending %s ref=%s terminal=%s merchantId=%d terminalId=%d',
            self::PREFIX,
            $messageType,
            $reference,
            $terminalSerial,
            $merchantId,
            $terminalId,
        ));
    }

    private static function payload(EcrLogger $logger, string $label, string $raw): void
    {
        $body = trim($raw) === '' ? '<empty>' : $raw;
        if ($body !== '<empty>') {
            try {
                $body = Json::pretty(Json::parse($body));
            } catch (\Throwable) {
                // Logged as it came.
            }
        }
        $logger->debug(self::PREFIX . ' ' . $label . ':');
        foreach (preg_split('/\r\n|\n/', $body) ?: [$body] as $line) {
            $logger->debug(self::PREFIX . '   ' . $line);
        }
    }
}
