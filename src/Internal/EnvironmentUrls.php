<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\EcrEnvironment;

/**
 * Amwal Hub base URLs per environment. SDK-internal: integrators choose SIT,
 * UAT or PROD and never type an address. Paths are appended by
 * {@see \AmwalPay\Ecr\WebService\WebServiceRoutes}.
 *
 * Same hosts as AmwalPay.Ecr 1.0.2.
 *
 * @internal
 */
final class EnvironmentUrls
{
    private const SIT = 'https://test.amwalpg.com:25452';
    private const UAT = 'https://test.amwalpg.com:25453';
    private const PROD = 'https://pos.amwalpg.com';

    public static function hub(EcrEnvironment $environment): string
    {
        return rtrim(match ($environment) {
            EcrEnvironment::SIT => self::SIT,
            EcrEnvironment::UAT => self::UAT,
            EcrEnvironment::PROD => self::PROD,
        }, '/');
    }
}
