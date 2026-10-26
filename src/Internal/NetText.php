<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

/**
 * .NET's string rules, for the few places where PHP's byte-oriented ones would
 * answer differently from the Windows SDK: `string.Trim()`,
 * `string.IsNullOrWhiteSpace`, `string.Length` (UTF-16 code units) and
 * `Encoding.UTF8.GetString` (each invalid sequence becomes U+FFFD).
 *
 * @internal
 */
final class NetText
{
    /** `char.IsWhiteSpace`: the Unicode white space .NET trims. */
    private const WHITE = '\x{9}-\x{D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    private const REPLACEMENT = "\u{FFFD}";

    /** `string.Trim()`. */
    public static function trim(string $text): string
    {
        $trimmed = preg_replace('/^[' . self::WHITE . ']+|[' . self::WHITE . ']+$/u', '', $text);
        // Not UTF-8: only the ASCII part of the set can be recognised.
        return $trimmed ?? trim($text, " \t\n\v\f\r");
    }

    /** `string.IsNullOrWhiteSpace`. */
    public static function isBlank(string $text): bool
    {
        return self::trim($text) === '';
    }

    /** `string.Length`: UTF-16 code units, so a character outside the BMP counts two. */
    public static function length(string $text): int
    {
        if (preg_match('//u', $text) !== 1) {
            return \strlen($text);
        }
        return (int) preg_match_all('/[\x{0}-\x{FFFF}]/u', $text) + 2 * (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
    }

    /** `Encoding.UTF8.GetString`: each maximal invalid subsequence becomes U+FFFD. */
    public static function scrubUtf8(string $bytes): string
    {
        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }
        $out = '';
        $n = \strlen($bytes);
        $i = 0;
        while ($i < $n) {
            $byte = \ord($bytes[$i]);
            [$need, $low, $high] = match (true) {
                $byte < 0x80 => [0, 0, 0],
                $byte >= 0xC2 && $byte <= 0xDF => [1, 0x80, 0xBF],
                $byte === 0xE0 => [2, 0xA0, 0xBF],
                $byte === 0xED => [2, 0x80, 0x9F],
                $byte >= 0xE1 && $byte <= 0xEF => [2, 0x80, 0xBF],
                $byte === 0xF0 => [3, 0x90, 0xBF],
                $byte === 0xF4 => [3, 0x80, 0x8F],
                $byte >= 0xF1 && $byte <= 0xF3 => [3, 0x80, 0xBF],
                default => [-1, 0, 0],
            };
            if ($need === 0) {
                $out .= $bytes[$i++];
                continue;
            }
            if ($need < 0) {
                $out .= self::REPLACEMENT;
                $i++;
                continue;
            }
            // Count the continuation bytes that keep the sequence well formed.
            $j = 1;
            while ($j <= $need && $i + $j < $n) {
                $next = \ord($bytes[$i + $j]);
                if ($next < ($j === 1 ? $low : 0x80) || $next > ($j === 1 ? $high : 0xBF)) {
                    break;
                }
                $j++;
            }
            if ($j === $need + 1) {
                $out .= substr($bytes, $i, $j);
            } else {
                $out .= self::REPLACEMENT;
            }
            $i += $j;
        }
        return $out;
    }
}
