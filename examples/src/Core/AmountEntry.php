<?php

declare(strict_types=1);

namespace AmwalExample\Core;

/**
 * POS-style amount entry, as the merchant app and the Windows samples do it:
 * the field shows three decimals and keyed digits shift in from the right, so
 * 1 gives 0.001 and 1234 gives 1.234. The keyed digits are mills.
 * Also holds the amount bounds of the POS purchase form.
 */
final class AmountEntry
{
    /** POS: minAmount = 10 / 1000. */
    public const MIN_MILLS = 10;

    /** POS: amountLimit / 1000. Change it to match the limit configured for your terminals. */
    public const MAX_MILLS = 100_000_000;

    public const FRACTION_DIGITS = 3;

    /** Digits the field accepts: enough to key the maximum and no more. */
    public static function maxDigits(): int
    {
        return \strlen((string) self::MAX_MILLS);
    }

    /** ASCII digits only, no leading zeros, capped. */
    public static function keyedDigits(string $text): string
    {
        $digits = ltrim(preg_replace('/[^0-9]/', '', $text) ?? '', '0');
        return substr($digits, 0, self::maxDigits());
    }

    public static function mills(string $digits): int
    {
        return $digits === '' ? 0 : (int) $digits;
    }

    /** Keyed digits as 0.000. */
    public static function format(string $digits): string
    {
        $padded = str_pad($digits === '' ? '0' : $digits, self::FRACTION_DIGITS + 1, '0', \STR_PAD_LEFT);
        $whole = ltrim(substr($padded, 0, -self::FRACTION_DIGITS), '0');
        return ($whole === '' ? '0' : $whole) . '.' . substr($padded, -self::FRACTION_DIGITS);
    }

    public static function formatMills(int $mills): string
    {
        return self::format((string) $mills);
    }
}
