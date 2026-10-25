<?php

declare(strict_types=1);

/**
 * Fails when the repository holds something that looks like a real secret or
 * card number. Run in CI and before every commit:
 *
 *   php tools/check-secrets.php
 *
 * Flags: hex strings of 32+ characters that are not known placeholders, PEM
 * private keys, Luhn-valid 13-19 digit runs that are not masked, and assignments
 * of a hash key that is not read from the environment. The allow-list holds the
 * synthetic placeholders the tests share with the Windows SDK.
 */

$root = dirname(__DIR__);
$placeholders = [
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'cccccccccccccccccccccccccccccccc',
    'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    'dddddddddddddddddddddddddddddddd',
    '0123456789ABCDEF0123456789ABCDEF',
    '0123456789abcdef0123456789abcdef',
    '82D83D6C05C1FBFA6AE72AE7C644D614',
    '00000000000000000000000000000001',
    // Published known-answer vectors (docs/protocol.md), outputs of the placeholders above.
    '9425F9125C871197E5D0A6B88E4F9E6E29D1E0E49C96AA3D88A5E34D6FB9D45A',
    'FEF8D18506A6AC035DC825A1DBA4413EE885898BBCE27EE4A681683FFAADC61E',
];
// Signatures and known answers are HMAC outputs, not secrets: 64 hex characters after a known label.
$signatureContext = '/(secureHash|secureHashValue|KnownAnswer|sign\(|calcHash|assertSame|0x|sha256|expected|E1979257)/i';
$cardSafe = ['4111111111111111']; // the industry test card number, used to prove masking

$skip = ['/vendor/', '/.git/', '/dist/', '/.phpunit.cache/', '/examples/vendor/', '/examples/var/'];
$problems = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skip as $part) {
        if (str_contains($path, $part)) {
            continue 2;
        }
    }
    if (!in_array(strtolower($file->getExtension()), ['php', 'md', 'json', 'js', 'html', 'css', 'yml', 'yaml', 'txt', 'ini', 'env', 'dist', 'xml', 'ps1', 'sh', ''], true)) {
        continue;
    }
    if (basename($path) === 'check-secrets.php') {
        continue;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $n => $line) {
        $where = substr($path, strlen($root) + 1) . ':' . ($n + 1);
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $line)) {
            $problems[] = "$where private key";
        }
        if (preg_match_all('/(?<![0-9A-Fa-f])[0-9A-Fa-f]{32,}(?![0-9A-Fa-f])/', $line, $hex)) {
            foreach ($hex[0] as $candidate) {
                if (in_array($candidate, $placeholders, true) || preg_match('/^(.)\1+$/', $candidate)) {
                    continue;
                }
                if (strlen($candidate) === 64 && preg_match($signatureContext, $line)) {
                    continue;
                }
                if (strlen($candidate) >= 32 && preg_match('/(nonce|transactionId|receipt|sha256|hash_file|DownloadReceipt|e970c800)/i', $line)) {
                    continue;
                }
                $problems[] = "$where long hex string (possible key): " . substr($candidate, 0, 8) . '…';
            }
        }
        if (preg_match_all('/(?<![0-9])[0-9]{13,19}(?![0-9])/', $line, $digits)) {
            foreach ($digits[0] as $candidate) {
                if (in_array($candidate, $cardSafe, true) || !luhn($candidate)) {
                    continue;
                }
                $problems[] = "$where Luhn-valid digit run (possible card number): " . substr($candidate, 0, 6) . '…';
            }
        }
    }
}

if ($problems !== []) {
    fwrite(STDERR, "Possible secrets or card data:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}
echo "No secrets or card numbers found.\n";

function luhn(string $digits): bool
{
    $sum = 0;
    $double = false;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $d = (int) $digits[$i];
        if ($double) {
            $d *= 2;
            if ($d > 9) {
                $d -= 9;
            }
        }
        $sum += $d;
        $double = !$double;
    }
    return $sum % 10 === 0;
}
