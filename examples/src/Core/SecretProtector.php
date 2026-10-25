<?php

declare(strict_types=1);

namespace AmwalExample\Core;

/**
 * Encrypts the settings file at rest: AES-256-GCM under a random 256-bit key in
 * `<DataDir>/keys/settings.key`. The PHP counterpart of the web sample's ASP.NET
 * Core Data Protection key ring.
 *
 * The key file is not itself encrypted: what protects it is the data folder's
 * permissions (NTFS ACLs on Windows, 0700/0600 elsewhere). Anyone who can read
 * the folder can decrypt the settings. Back up `keys/` together with
 * `settings.dat`, or accept re-entering the keys.
 */
final class SecretProtector
{
    private const MAGIC = "AEX1";
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $keyDirectory)
    {
    }

    public function protect(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(true), \OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('The settings could not be encrypted');
        }
        return self::MAGIC . $iv . $tag . $cipher;
    }

    /** @throws \RuntimeException when the data was not encrypted with this folder's key */
    public function unprotect(string $data): string
    {
        if (!str_starts_with($data, self::MAGIC) || \strlen($data) < 32) {
            throw new \RuntimeException('The file is not in the expected format.');
        }
        $key = $this->key(false);
        if ($key === null) {
            throw new \RuntimeException('The key in ' . $this->keyDirectory . ' is missing.');
        }
        $plain = openssl_decrypt(substr($data, 32), self::CIPHER, $key, \OPENSSL_RAW_DATA, substr($data, 4, 12), substr($data, 16, 16));
        if ($plain === false) {
            throw new \RuntimeException('The key in ' . $this->keyDirectory . ' does not decrypt it.');
        }
        return $plain;
    }

    private function key(bool $create): ?string
    {
        $path = $this->keyDirectory . \DIRECTORY_SEPARATOR . 'settings.key';
        if (is_file($path)) {
            $key = @file_get_contents($path);
            if ($key !== false && \strlen($key) === 32) {
                return $key;
            }
        }
        if (!$create) {
            return null;
        }
        if (!is_dir($this->keyDirectory) && !@mkdir($this->keyDirectory, 0700, true) && !is_dir($this->keyDirectory)) {
            throw new \RuntimeException('Could not create ' . $this->keyDirectory);
        }
        @chmod($this->keyDirectory, 0700);
        $key = random_bytes(32);
        if (@file_put_contents($path, $key, \LOCK_EX) === false) {
            throw new \RuntimeException('Could not write ' . $path);
        }
        @chmod($path, 0600);
        return $key;
    }
}
