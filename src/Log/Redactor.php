<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Log;

/**
 * Masks what must never reach a log, applied to every line the SDK writes.
 *
 * The terminal only ever sends masked card numbers, and the SDK never logs its
 * signing secret. This is the second line of defence, for the day a terminal,
 * a Hub or a caller's own text carries something it should not:
 *
 * - a run of 13 to 19 digits that passes the Luhn check is masked to its first
 *   six and last four digits;
 * - the values of fields named like a PIN, CVV, track data, token, password or
 *   secret are replaced with `***`.
 */
final class Redactor
{
    private const SENSITIVE_FIELDS = 'pin|pinBlock|pinblock|cvv|cvv2|cvc|cvc2|track1|track2|track2Data|trackData|'
        . 'token|accessToken|refreshToken|password|secret|secretKey|secureHashKey|apiKey|key';

    public static function line(string $line): string
    {
        $line = preg_replace_callback(
            '/"(' . self::SENSITIVE_FIELDS . ')"(\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|-?[0-9][0-9.eE+-]*)/i',
            static fn (array $m): string => '"' . $m[1] . '"' . $m[2] . '"***"',
            $line,
        ) ?? $line;

        return preg_replace_callback(
            '/(?<![0-9])[0-9]{13,19}(?![0-9])/',
            static fn (array $m): string => self::luhn($m[0]) ? self::mask($m[0]) : $m[0],
            $line,
        ) ?? $line;
    }

    /** `5431731234565785` becomes `543173******5785`. */
    public static function mask(string $pan): string
    {
        return substr($pan, 0, 6) . str_repeat('*', \strlen($pan) - 10) . substr($pan, -4);
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = \strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }
}
