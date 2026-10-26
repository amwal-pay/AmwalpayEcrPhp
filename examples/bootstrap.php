<?php

declare(strict_types=1);

/**
 * Loads the example's dependencies: the amwalpay/ecr-php package installed from
 * its artifact (examples/vendor). `AMWAL_ECR_EXAMPLE_AUTOLOAD` points elsewhere
 * only for the SDK's own test suite, which drives this example against the SDK
 * source.
 *
 * Also sets PHP's time zone to the machine's, as the Windows sample's
 * `DateTime.Today` and `DateTime.Now`: the original-transaction date defaults to
 * "today", and log lines are stamped in local time. php.ini's `date.timezone`
 * (Europe/Berlin under XAMPP) is not the till's zone.
 */

$autoload = getenv('AMWAL_ECR_EXAMPLE_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    fwrite(STDERR, "Run `composer install` in examples/ first: it installs amwalpay/ecr-php from ../dist.\n");
    exit(1);
}
require $autoload;

$exampleZone = getenv('AMWAL_ECR_EXAMPLE_TIMEZONE') ?: \AmwalPay\Ecr\EcrSdk::localTimeZone()->getName();
if (in_array($exampleZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
    date_default_timezone_set($exampleZone);
}
