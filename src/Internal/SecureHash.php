<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonObject;

/**
 * Message authentication for the Wi-Fi ECR link.
 *
 * A shared secret proves that a request came from a till that holds the key
 * and that nothing on the network altered it. Without it any device on the
 * shop Wi-Fi could open the terminal's port and issue a refund.
 *
 * Construction, identical to the POS and the Windows SDK: take every top-level
 * string, number or boolean except `secureHash`, sort by key byte for byte, join
 * as `key=value&key=value`, HMAC-SHA256 under the hex-decoded key, uppercase hex.
 *
 * @internal
 */
final class SecureHash
{
    /** Field carrying the signature. Never part of what is signed. */
    public const FIELD = 'secureHash';

    /** Log label for the Wi-Fi signing key (distinct from Web Service). */
    public const KEY_LABEL = 'Wi-Fi/CABLE secure hash key';

    private const NONCE_BYTES = 16;

    /** The exact text both sides agree to sign. */
    public static function compose(JsonObject $json): string
    {
        return Json::compose($json, self::FIELD);
    }

    /** HMAC-SHA256 of `$message` under the hex-decoded `$key`, as uppercase hex. */
    public static function sign(string $message, #[\SensitiveParameter] string $key, string $name = 'The ECR secret'): string
    {
        return strtoupper(hash_hmac('sha256', $message, self::decodeKey($key, $name)));
    }

    /** Whether `$json` carries a `secureHash` that matches `$key`. */
    public static function verify(JsonObject $json, #[\SensitiveParameter] string $key): bool
    {
        $presented = $json->str(self::FIELD);
        if ($presented === '') {
            return false;
        }
        // Constant time, case-insensitive hex: the terminal may write either case.
        return hash_equals(self::sign(self::compose($json), $key), strtoupper($presented));
    }

    /** A fresh 128-bit nonce, as uppercase hex. */
    public static function newNonce(): string
    {
        return strtoupper(bin2hex(random_bytes(self::NONCE_BYTES)));
    }

    /**
     * The key as bytes. Hex, matching the POS convention: a 32-character key is
     * 16 bytes, not 32. A key that is not hex is refused rather than read as
     * text, because the two sides would then disagree about the bytes and every
     * message would fail to verify with nothing to show why.
     */
    public static function decodeKey(#[\SensitiveParameter] string $key, string $name = 'The ECR secret'): string
    {
        if (\strlen($key) < 2 || \strlen($key) % 2 !== 0 || !ctype_xdigit($key)) {
            throw new \InvalidArgumentException($name . ' must be an even-length hex string');
        }
        return (string) hex2bin($key);
    }
}
