<?php

declare(strict_types=1);

/**
 * Builds the exact package artifact the example and SIT consume:
 *
 *   php tools/build-artifact.php            -> dist/amwalpay-ecr-php-<version>.zip (+ .sha256)
 *
 * The zip holds composer.json with the version stamped in (so Composer's
 * "artifact" repository can install it), src/, docs/ and the top-level
 * documents. Tests, tools and examples are left out. The version comes from
 * AmwalPay\Ecr\EcrSdk::VERSION and must match the CHANGELOG.
 */

$root = dirname(__DIR__);
$sdk = (string) file_get_contents($root . '/src/EcrSdk.php');
if (preg_match("/VERSION = '([^']+)'/", $sdk, $m) !== 1) {
    fwrite(STDERR, "EcrSdk::VERSION not found\n");
    exit(1);
}
$version = $m[1];
if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$/', $version) !== 1) {
    fwrite(STDERR, "EcrSdk::VERSION is not a semantic version: $version\n");
    exit(1);
}
$changelog = (string) @file_get_contents($root . '/CHANGELOG.md');
if (!str_contains($changelog, '## ' . $version)) {
    fwrite(STDERR, "CHANGELOG.md has no entry for $version\n");
    exit(1);
}

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$composer['version'] = $version;
unset($composer['require-dev'], $composer['autoload-dev'], $composer['scripts']);

$dist = $root . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0775, true) && !is_dir($dist)) {
    fwrite(STDERR, "cannot create $dist\n");
    exit(1);
}
$zipPath = $dist . '/amwalpay-ecr-php-' . $version . '.zip';
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "cannot create $zipPath\n");
    exit(1);
}
$zip->addFromString('composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$files = 0;
foreach (['README.md', 'CHANGELOG.md', 'SECURITY.md', 'LICENSE'] as $document) {
    if (is_file($root . '/' . $document)) {
        $zip->addFile($root . '/' . $document, $document);
        $files++;
    }
}
foreach (['src', 'docs'] as $folder) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $zip->addFile($file->getPathname(), $relative);
        $zip->setCompressionName($relative, ZipArchive::CM_DEFLATE);
        $files++;
    }
}
// A fixed timestamp keeps the archive reproducible from the same sources.
for ($i = 0; $i < $zip->numFiles; $i++) {
    $zip->setMtimeIndex($i, mktime(0, 0, 0, 1, 1, 2026));
}
$zip->close();

$sha256 = hash_file('sha256', $zipPath);
file_put_contents($zipPath . '.sha256', $sha256 . '  ' . basename($zipPath) . "\n");

echo "Built ", basename($zipPath), " ($files files, ", filesize($zipPath), " bytes)\n";
echo "sha256 $sha256\n";
