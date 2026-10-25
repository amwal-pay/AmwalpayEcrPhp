<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Json\JsonObject;

/**
 * Refuses an answer written in a newer message format than this SDK reads.
 *
 * Today's answers carry no `version`, or `version` 1. An answer that declares a
 * higher version may mean something this SDK would misread, so it is not read
 * at all: a sale, void or refund becomes an unknown outcome (inquire, never
 * resend) and the error names both versions.
 *
 * @internal
 */
final class ProtocolVersion
{
    public const SUPPORTED = EcrMessage::PROTOCOL_VERSION;

    /** Why `$answer` cannot be read, or null when it can. */
    public static function problem(JsonObject $answer): ?string
    {
        $declared = $answer->str('version');
        if ($declared === '' || preg_match('/^[0-9]{1,6}$/', $declared) !== 1) {
            return null;
        }
        if ((int) $declared <= self::SUPPORTED) {
            return null;
        }
        return sprintf(
            'The answer uses ECR message version %s; this SDK reads version %d. Upgrade amwalpay/ecr-php. '
            . 'The transaction may still have completed — inquire before retrying.',
            $declared,
            self::SUPPORTED,
        );
    }
}
