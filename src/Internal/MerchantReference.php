<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Exception\InvalidRequestException;

/**
 * The rules for a merchant reference: at most 32 characters of printable ASCII,
 * with space, `&` and `=` excluded. Surrounding space is trimmed.
 *
 * `&` and `=` are the separators of a signed message's input, so a reference
 * holding one could make two different messages hash alike. A space is
 * invisible in logs and on receipts, where it turns two references into one.
 *
 * @internal
 */
final class MerchantReference
{
    public const MAX_LENGTH = 32;

    /**
     * The reference to put on the message: the caller's own, or a new one when
     * the caller gave none.
     *
     * @throws InvalidRequestException the reference is too long or carries characters the wire cannot represent
     */
    public static function resolve(?string $supplied): string
    {
        $trimmed = trim((string) $supplied, " \t\n\r\0\x0B");
        if ($trimmed === '') {
            return self::generate();
        }
        if (\strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidRequestException(sprintf(
                'A merchant reference is at most %d characters, was %d',
                self::MAX_LENGTH,
                \strlen($trimmed),
            ));
        }
        // 0x21..0x7E is printable ASCII without the space.
        if (preg_match('/^[\x21-\x7E]+$/', $trimmed) !== 1 || strpbrk($trimmed, '&=') !== false) {
            throw new InvalidRequestException(sprintf(
                'A merchant reference takes printable ASCII without spaces, \'&\' or \'=\': was "%s"',
                addcslashes($trimmed, "\0..\37\177..\377"),
            ));
        }
        return $trimmed;
    }

    /** Same shape the Windows SDK generates: 12 uppercase hex characters. */
    public static function generate(): string
    {
        return strtoupper(bin2hex(random_bytes(6)));
    }
}
