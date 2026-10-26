<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

/**
 * Argument shaping shared by the Wi-Fi and Web Service messages, as the
 * Windows SDK does it.
 *
 * @internal
 */
final class Validate
{
    /** Width the terminal stores and looks up receipt numbers by. */
    public const STAN_DIGITS = 6;

    /**
     * The terminal stores receipt numbers padded; an operator types "24". Every
     * character that is not a digit is dropped, as in the Windows SDK; "" when
     * no digit other than zero is left.
     */
    public static function paddedStan(string $stan): string
    {
        // `char.IsDigit`: any decimal digit, not only 0-9 (ASCII when not UTF-8).
        $kept = preg_replace('/[^\p{Nd}]/u', '', $stan) ?? preg_replace('/[^0-9]/', '', $stan) ?? '';
        $digits = ltrim($kept, '0');
        return $digits === '' ? '' : str_repeat('0', max(0, self::STAN_DIGITS - NetText::length($digits))) . $digits;
    }

    /** `string.IsNullOrWhiteSpace`. */
    public static function isBlank(string $text): bool
    {
        return NetText::isBlank($text);
    }
}
