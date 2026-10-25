<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService;

use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonObject;

/**
 * Web Service request signing: the platform's `calcHash(obj, secret)`.
 *
 * `obj` is not the raw JSON body. It is the sorted `key=value&key=value` text
 * of every top-level scalar except `secureHashValue`, the same rules as Wi-Fi,
 * HMAC-SHA256 under the hex-decoded secret, uppercase hex:
 *
 * ```js
 * function calcHash(obj, secret) {
 *   const crypted = CryptoJS.HmacSHA256(obj, CryptoJS.enc.Hex.parse(secret))
 *   return CryptoJS.enc.Hex.stringify(crypted).toUpperCase();
 * }
 * ```
 */
final class WebServiceSecureHash
{
    /** Field added to signed Web Service request bodies. */
    public const FIELD = 'secureHashValue';

    /** Log label for the Web Service signing key (distinct from Wi-Fi). */
    public const KEY_LABEL = 'Web Service secure hash key';

    /** `calcHash`: HMAC-SHA256 under the hex-decoded secret as uppercase hex, or "" on any error. */
    public static function calcHash(string $message, #[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        try {
            return SecureHash::sign($message, $secret, 'The Web Service secret');
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    /** The sorted `key=value&...` signing text, excluding `secureHashValue`. */
    public static function compose(JsonObject $json): string
    {
        return Json::compose($json, self::FIELD);
    }

    public static function sign(JsonObject $body, #[\SensitiveParameter] string $secret): string
    {
        return self::calcHash(self::compose($body), $secret);
    }

    /**
     * A copy of `$body` with `secureHashValue` set. The input is left alone; an
     * earlier hash is replaced. An empty secret returns the body unchanged.
     *
     * @throws \InvalidArgumentException the secret cannot sign: never sends an unsigned body by mistake
     */
    public static function withHash(JsonObject $body, #[\SensitiveParameter] string $secret): JsonObject
    {
        if ($secret === '') {
            return $body;
        }
        $hash = self::calcHash(self::compose($body), $secret);
        if ($hash === '') {
            throw new \InvalidArgumentException('Web Service secureHashValue could not be computed — check the secure hash key');
        }
        $signed = clone $body;
        $signed->remove(self::FIELD);
        $signed->set(self::FIELD, $hash);
        return $signed;
    }
}
