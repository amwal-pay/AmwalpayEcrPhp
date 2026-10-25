<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Support;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrEnvironment;

/**
 * Synthetic placeholder secrets that happen to be valid hex, the same ones the
 * Windows SDK's tests use, so the known answers carry across. Never real keys.
 */
final class TestKeys
{
    public const WIFI = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const WIFI_OTHER = 'cccccccccccccccccccccccccccccccc';
    public const WEB_SERVICE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function lan(string $stateDirectory, mixed ...$changes): EcrConfig
    {
        return (new EcrConfig(secureHashKey: self::WIFI, stateDirectory: $stateDirectory))->with(...$changes);
    }

    public static function unsigned(string $stateDirectory, mixed ...$changes): EcrConfig
    {
        return (new EcrConfig(stateDirectory: $stateDirectory, autoInquireOnFailure: false))->with(...$changes);
    }

    public static function webService(string $stateDirectory, mixed ...$changes): EcrConfig
    {
        return (new EcrConfig(
            secureHashKey: self::WEB_SERVICE,
            merchantId: '10001',
            terminalId: '20001',
            environment: EcrEnvironment::UAT,
            stateDirectory: $stateDirectory,
        ))->with(...$changes);
    }
}
