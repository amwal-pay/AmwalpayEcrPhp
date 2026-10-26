<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

/** Facts about this build of the SDK, for logs and support tickets. */
final class EcrSdk
{
    /** The package version. Kept in step with the Git tag and CHANGELOG.md. */
    public const VERSION = '1.0.0-rc.1';

    /** The ECR message format this SDK writes and reads (`version` on the wire). */
    public const PROTOCOL_VERSION = Internal\EcrMessage::PROTOCOL_VERSION;

    /** The Windows SDK release whose behaviour this one matches. */
    public const PARITY_WITH = 'AmwalPay.Ecr 1.0.2 (.NET)';

    public const MINIMUM_PHP = '8.1.0';

    /**
     * The operating system's time zone, which `transactionDateTime` is stamped in
     * unless {@see EcrConfig::$timezone} is set (the Windows SDK's `DateTime.Now`).
     * PHP's own default, `date.timezone`, is used only when it cannot be read.
     */
    public static function localTimeZone(): \DateTimeZone
    {
        return Internal\LocalTime::zone();
    }
}
