<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

/**
 * Deployment environment for Web Service ECR.
 *
 * The SDK resolves the Amwal Hub address internally; an integrator passes only
 * this value in {@see EcrConfig::$environment}.
 */
enum EcrEnvironment: string
{
    case SIT = 'SIT';
    case UAT = 'UAT';
    case PROD = 'PROD';

    public const DEFAULT = self::SIT;

    /** Reads an environment by name, ignoring case and surrounding space. Unknown names read as SIT. */
    public static function fromName(?string $name): self
    {
        $trimmed = strtoupper(Internal\NetText::trim((string) $name));
        return self::tryFrom($trimmed) ?? self::DEFAULT;
    }
}
