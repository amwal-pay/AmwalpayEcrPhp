<?php

declare(strict_types=1);

/**
 * Loads the example's dependencies: the amwalpay/ecr-php package installed from
 * its artifact (examples/vendor). `AMWAL_ECR_EXAMPLE_AUTOLOAD` points elsewhere
 * only for the SDK's own test suite, which drives this example against the SDK
 * source.
 *
 * Also sets the time zone when php.ini does not: the original-transaction date
 * defaults to "today", which must be the till's today.
 */

$autoload = getenv('AMWAL_ECR_EXAMPLE_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    fwrite(STDERR, "Run `composer install` in examples/ first: it installs amwalpay/ecr-php from ../dist.\n");
    exit(1);
}
require $autoload;

if (ini_get('date.timezone') === '' || ini_get('date.timezone') === false) {
    date_default_timezone_set(getenv('AMWAL_ECR_EXAMPLE_TIMEZONE') ?: 'Asia/Muscat');
}
