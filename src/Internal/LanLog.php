<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Log\EcrLogger;

/**
 * Signing and verification lines for Wi-Fi, in the Windows SDK's wording, so
 * an operator can confirm the Wi-Fi key (not the Web Service key) is in use.
 * The secret appears only as "configured" or "not configured".
 *
 * @internal
 */
final class LanLog
{
    private const PREFIX = '[Wi-Fi/CABLE]';

    public static function signing(EcrLogger $logger, string $keyLabel, bool $keyConfigured, string $payload, string $hash, string $nonce): void
    {
        $logger->debug(self::PREFIX . ' sign: HMAC-SHA256(sorted key=value payload, Hex.parse(secret)) → uppercase hex');
        $logger->debug(self::PREFIX . ' signing key (' . $keyLabel . '): ' . self::fingerprint($keyConfigured));
        $logger->debug(self::PREFIX . ' payload to sign (excl. secureHash): ' . $payload);
        if ($nonce !== '') {
            $logger->debug(self::PREFIX . ' nonce: ' . $nonce);
        }
        $logger->debug(self::PREFIX . ' secureHash: ' . $hash);
    }

    public static function verifyResponse(EcrLogger $logger, string $keyLabel, bool $keyConfigured, string $payload, string $hash, bool $verified): void
    {
        $logger->debug(self::PREFIX . ' verify: HMAC-SHA256(sorted key=value payload, Hex.parse(secret)) → uppercase hex');
        $logger->debug(self::PREFIX . ' verify key (' . $keyLabel . '): ' . self::fingerprint($keyConfigured));
        $logger->debug(self::PREFIX . ' payload to verify (excl. secureHash): ' . $payload);
        $logger->debug(self::PREFIX . ' secureHash: ' . $hash);
        $logger->debug($verified ? self::PREFIX . ' ✓ response signature valid' : self::PREFIX . ' ✗ response signature invalid');
    }

    public static function fingerprint(bool $configured): string
    {
        return $configured ? 'configured' : 'not configured';
    }
}
