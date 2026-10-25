<?php

declare(strict_types=1);

/**
 * Proves the example runs on the built package artifact, not on the SDK's
 * source tree: prints the installed version, where it was installed from, and
 * the artifact's checksum, and fails if the package resolves into ../src.
 *
 *   composer verify-artifact
 */

require __DIR__ . '/../vendor/autoload.php';

use AmwalPay\Ecr\EcrSdk;
use Composer\InstalledVersions;

$package = 'amwalpay/ecr-php';
$installed = realpath((string) InstalledVersions::getInstallPath($package)) ?: '';
$source = realpath((new ReflectionClass(EcrSdk::class))->getFileName() ?: '') ?: '';
$sdkSource = realpath(__DIR__ . '/../../src') ?: '<none>';

echo 'Package:      ', $package, ' ', InstalledVersions::getPrettyVersion($package), "\n";
echo 'Reference:    ', InstalledVersions::getReference($package) ?? '-', "\n";
echo 'Installed in: ', $installed, "\n";
echo 'EcrSdk from:  ', $source, "\n";
echo 'EcrSdk says:  ', EcrSdk::VERSION, ' (', EcrSdk::PARITY_WITH, ")\n";

foreach (glob(__DIR__ . '/../../dist/amwalpay-ecr-php-' . EcrSdk::VERSION . '.zip.sha256') ?: [] as $checksum) {
    echo 'Artifact:     ', trim((string) file_get_contents($checksum)), "\n";
}

if (str_starts_with($source, $sdkSource)) {
    fwrite(STDERR, "FAIL: the SDK is loaded from its source tree, not from the artifact\n");
    exit(1);
}
if (InstalledVersions::getPrettyVersion($package) !== EcrSdk::VERSION) {
    fwrite(STDERR, "FAIL: installed version and EcrSdk::VERSION differ\n");
    exit(1);
}
echo "OK: the example runs on the built artifact\n";
