<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Exception\InvalidRequestException;

/**
 * Money, in exact decimal text: the PHP stand-in for the Windows SDK's
 * `decimal`. Amounts enter as strings (`"1.234"`), integers (`5`) or floats
 * (`1.234`, read through their shortest exact form), and are rounded half away
 * from zero to the currency's decimal places, then sent as minor units:
 * `"1.234"` OMR travels as `000000001234`.
 *
 * As in the Windows SDK, the amount itself is not judged: zero and negative
 * amounts are sent as they are, and the terminal or the Hub answers them.
 */
final class Amount
{
    private function __construct(public readonly string $decimal)
    {
    }

    /** An amount in major units, e.g. `Amount::of('1.234')`. */
    public static function of(string|int|float|self $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }
        if (\is_int($amount)) {
            return new self((string) $amount);
        }
        if (\is_float($amount)) {
            if (!is_finite($amount)) {
                throw new InvalidRequestException('An amount must be a finite number');
            }
            // The shortest text that reads back as the same float: 1.005 stays 1.005.
            $amount = json_encode($amount, \JSON_PRESERVE_ZERO_FRACTION);
        }
        $text = self::plain(trim($amount));
        if ($text === null) {
            throw new InvalidRequestException(sprintf('An amount is a decimal in major units, such as "1.234": was "%s"', $amount));
        }
        return new self($text);
    }

    /** An amount already in minor units: `Amount::fromMinorUnits(1234, 3)` is 1.234. */
    public static function fromMinorUnits(int $minorUnits, int $digits): self
    {
        return new self(self::majorUnits((string) $minorUnits, $digits));
    }

    /**
     * Minor units for a currency with `$digits` decimal places, rounded half away
     * from zero, as the Web Service carries them (a 64-bit integer).
     *
     * @throws \OverflowException the amount does not fit 64 bits, as .NET's `checked` cast
     */
    public function toMinorUnits(int $digits): int
    {
        $text = $this->minorUnitsText($digits);
        $value = filter_var($text, \FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \OverflowException('Arithmetic operation resulted in an overflow.');
        }
        return $value;
    }

    /**
     * Minor units padded to 12 characters, as the Wi-Fi message carries them
     * (ISO 8583 field 4). Like the Windows SDK, longer or negative values are
     * padded as text and not refused.
     */
    public function toWire(int $digits): string
    {
        return str_pad($this->minorUnitsText($digits), 12, '0', \STR_PAD_LEFT);
    }

    /** The amount rounded to `$digits` places, e.g. `1.234`. */
    public function format(int $digits): string
    {
        return self::majorUnits($this->minorUnitsText($digits), $digits);
    }

    public function __toString(): string
    {
        return $this->decimal;
    }

    /**
     * Minor units off the wire, read as major units: `000000000365` is `0.365`
     * with three decimals. Text that is not an integer is returned as it is: a
     * terminal that sends `1.234` is already reporting major units.
     */
    public static function majorUnits(string $minorUnits, int $digits): string
    {
        if ($minorUnits === '') {
            return '';
        }
        if (preg_match('/^([+-]?)([0-9]+)$/', $minorUnits, $m) !== 1) {
            return $minorUnits;
        }
        $negative = $m[1] === '-';
        $text = ltrim($m[2], '0');
        $isZero = $text === '';
        $text = $isZero ? '0' : $text;
        if ($digits > 0) {
            $text = str_pad($text, $digits + 1, '0', \STR_PAD_LEFT);
            $text = substr($text, 0, -$digits) . '.' . substr($text, -$digits);
        }
        return $negative && !$isZero ? '-' . $text : $text;
    }

    /**
     * `Math.Round(amount, digits, MidpointRounding.AwayFromZero) * 10^digits`,
     * truncated and written as an integer: `-1234`, `0`, `1234567890123`.
     */
    public function minorUnitsText(int $digits): string
    {
        $negative = str_starts_with($this->decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($this->decimal, '-'), 2), 2, '');
        $kept = str_pad(substr($fraction, 0, $digits), $digits, '0');
        $roundUp = \strlen($fraction) > $digits && $fraction[$digits] >= '5';

        $text = ltrim($whole . $kept, '0');
        if ($roundUp) {
            $text = self::increment($text === '' ? '0' : $text);
        }
        if ($text === '') {
            return '0';
        }
        return $negative ? '-' . $text : $text;
    }

    /** `[-]digits[.digits]`, without a leading `+` or exponent, or null when `$text` is not a number. */
    private static function plain(string $text): ?string
    {
        if (preg_match('/^([+-]?)([0-9]*)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]{1,4}))?$/', $text, $m) !== 1 || ($m[2] ?? '') . ($m[3] ?? '') === '') {
            return null;
        }
        $whole = $m[2];
        $fraction = $m[3] ?? '';
        $exponent = (int) ($m[4] ?? 0);
        if ($exponent > 0) {
            $whole .= str_pad(substr($fraction, 0, $exponent), $exponent, '0');
            $fraction = (string) substr($fraction, $exponent);
        } elseif ($exponent < 0) {
            $whole = str_pad($whole, -$exponent, '0', \STR_PAD_LEFT);
            $fraction = substr($whole, $exponent) . $fraction;
            $whole = substr($whole, 0, $exponent);
        }
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');
        $result = ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
        return $m[1] === '-' && $result !== '0' ? '-' . $result : $result;
    }

    /** Adds one to a string of decimal digits. */
    private static function increment(string $digits): string
    {
        $i = \strlen($digits) - 1;
        while ($i >= 0 && $digits[$i] === '9') {
            $digits[$i] = '0';
            $i--;
        }
        if ($i < 0) {
            return '1' . $digits;
        }
        $digits[$i] = (string) ((int) $digits[$i] + 1);
        return $digits;
    }
}
