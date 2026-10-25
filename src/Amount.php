<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Exception\InvalidRequestException;

/**
 * Money, in exact decimal text. PHP has no decimal type and a float cannot
 * hold 1.005 exactly, so amounts enter the SDK as strings (`"1.234"`) or whole
 * numbers of major units (`5`), never as floats.
 *
 * The SDK rounds half away from zero to the currency's decimal places, then
 * sends minor units: `"1.234"` OMR travels as `000000001234`.
 */
final class Amount
{
    /** The wire carries 12 digits of minor units (ISO 8583 field 4). */
    public const MAX_MINOR_UNITS = 999_999_999_999;

    private function __construct(public readonly string $decimal)
    {
    }

    /** An amount in major units, e.g. `Amount::of('1.234')`. */
    public static function of(string|int|self $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }
        if (\is_int($amount)) {
            if ($amount <= 0) {
                throw new InvalidRequestException('An amount must be greater than zero');
            }
            return new self((string) $amount);
        }
        $text = trim($amount);
        if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $text) !== 1 && preg_match('/^\.[0-9]+$/', $text) !== 1) {
            throw new InvalidRequestException(sprintf(
                'An amount is a positive decimal in major units, such as "1.234", with no sign, exponent or separators: was "%s"',
                $amount,
            ));
        }
        return new self(str_starts_with($text, '.') ? '0' . $text : $text);
    }

    /** An amount already in minor units: `Amount::fromMinorUnits(1234, 3)` is 1.234. */
    public static function fromMinorUnits(int $minorUnits, int $digits): self
    {
        if ($minorUnits <= 0) {
            throw new InvalidRequestException('An amount must be greater than zero');
        }
        return new self(self::majorUnits((string) $minorUnits, $digits));
    }

    /**
     * Minor units for a currency with `$digits` decimal places, rounded half away
     * from zero. Refuses anything that would round to nothing or not fit the wire.
     *
     * @throws InvalidRequestException
     */
    public function toMinorUnits(int $digits): int
    {
        [$whole, $fraction] = array_pad(explode('.', $this->decimal, 2), 2, '');
        $whole = ltrim($whole, '0');
        $kept = str_pad(substr($fraction, 0, $digits), $digits, '0');
        $roundUp = \strlen($fraction) > $digits && $fraction[$digits] >= '5';

        $digitsText = ltrim($whole . $kept, '0');
        if ($roundUp) {
            $digitsText = self::increment($digitsText === '' ? '0' : $digitsText);
        }
        $digitsText = $digitsText === '' ? '0' : $digitsText;

        if (\strlen($digitsText) > 12) {
            throw new InvalidRequestException(sprintf('The amount %s does not fit the 12 digits the terminal carries', $this->decimal));
        }
        $minor = (int) $digitsText;
        if ($minor < 1) {
            throw new InvalidRequestException(sprintf(
                'The amount %s is less than the smallest unit of a currency with %d decimal places',
                $this->decimal,
                $digits,
            ));
        }
        return $minor;
    }

    /** Twelve zero-padded digits of minor units, as the Wi-Fi and Web Service messages carry them. */
    public function toWire(int $digits): string
    {
        return str_pad((string) $this->toMinorUnits($digits), 12, '0', \STR_PAD_LEFT);
    }

    /** The amount rounded to `$digits` places, e.g. `1.234`. */
    public function format(int $digits): string
    {
        return self::majorUnits((string) $this->toMinorUnits($digits), $digits);
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
