<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Exception\InvalidRequestException;

/**
 * Argument rules checked before anything is built or sent.
 *
 * Stricter than the Windows SDK, which strips stray characters from a receipt
 * number and sends a date as given: a PHP till usually takes these from a web
 * form, and a mistyped value should be refused, not reshaped into a request for
 * some other transaction.
 *
 * @internal
 */
final class Validate
{
    /** Width the terminal stores and looks up receipt numbers by. */
    public const STAN_DIGITS = 6;

    /**
     * A receipt number (STAN) as the terminal stores it: digits, padded to six.
     * An operator types "24"; the terminal looks up "000024".
     *
     * @throws InvalidRequestException
     */
    public static function receiptNumber(string $receiptNumber, string $operation): string
    {
        $text = trim($receiptNumber);
        if ($text === '') {
            throw new InvalidRequestException($operation . " needs the original's receipt number");
        }
        if (preg_match('/^[0-9]+$/', $text) !== 1) {
            throw new InvalidRequestException(sprintf('A receipt number is digits only: was "%s"', $receiptNumber));
        }
        $digits = ltrim($text, '0');
        if ($digits === '' || \strlen($digits) > self::STAN_DIGITS) {
            throw new InvalidRequestException(sprintf(
                'A receipt number is 1 to %d digits and not zero: was "%s"',
                self::STAN_DIGITS,
                $receiptNumber,
            ));
        }
        return str_pad($digits, self::STAN_DIGITS, '0', \STR_PAD_LEFT);
    }

    /**
     * A transaction date as `yyyyMMdd`, or "" when it is optional and absent.
     *
     * @throws InvalidRequestException
     */
    public static function transactionDate(string $date, bool $required, string $operation): string
    {
        $text = trim($date);
        if ($text === '') {
            if ($required) {
                throw new InvalidRequestException($operation . " needs the original transaction's date as yyyyMMdd");
            }
            return '';
        }
        if (
            preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/', $text, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw new InvalidRequestException(sprintf('A transaction date is a real day written yyyyMMdd: was "%s"', $date));
        }
        return $text;
    }

    /** An optional terminal ID naming where the original was taken: printable text, trimmed. */
    public static function originalTerminalId(string $terminalId): string
    {
        $text = trim($terminalId);
        if ($text !== '' && preg_match('/^[\x21-\x7E]{1,32}$/', $text) !== 1) {
            throw new InvalidRequestException(sprintf('An original terminal ID is up to 32 printable characters: was "%s"', $terminalId));
        }
        return $text;
    }

    /** Pads a receipt number the way the terminal stores it, or "" when it has no digits. Lenient, as the Windows SDK. */
    public static function paddedStan(string $stan): string
    {
        $digits = ltrim(preg_replace('/[^0-9]/', '', $stan) ?? '', '0');
        return $digits === '' ? '' : str_pad($digits, self::STAN_DIGITS, '0', \STR_PAD_LEFT);
    }
}
