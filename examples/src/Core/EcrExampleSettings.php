<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\EcrEnvironment;

/**
 * App-owned signing and Web Service settings: the environment and the two
 * signing keys, stored encrypted in `settings.dat`. The SDK does not store or
 * choose keys; {@see secureHashKeyFor()} picks one for the selected terminal.
 */
final class EcrExampleSettings
{
    /** Set when the file existed but could not be decrypted (another folder's key, or damage). */
    public ?string $loadWarning = null;

    /** The last error from writing the file, or null. Cleared by the next good write. */
    public ?string $saveError = null;

    /** @var array{environment: string, wifiSecureHashKey: string, webServiceSecureHashKey: string} */
    private array $values = ['environment' => 'SIT', 'wifiSecureHashKey' => '', 'webServiceSecureHashKey' => ''];

    public function __construct(private readonly string $path, private readonly SecretProtector $protector)
    {
        $this->load();
    }

    public function environment(): EcrEnvironment
    {
        return EcrEnvironment::fromName($this->values['environment']);
    }

    public function setEnvironment(EcrEnvironment $environment): void
    {
        $this->update('environment', $environment->value);
    }

    /** Signing key for Wi-Fi (USB cable and Bluetooth share it). */
    public function wifiSecureHashKey(): string
    {
        return $this->values['wifiSecureHashKey'];
    }

    public function setWifiSecureHashKey(#[\SensitiveParameter] string $key): void
    {
        $this->update('wifiSecureHashKey', trim($key));
    }

    /** Web Service signing key: a different secret from the Wi-Fi one. */
    public function webServiceSecureHashKey(): string
    {
        return $this->values['webServiceSecureHashKey'];
    }

    public function setWebServiceSecureHashKey(#[\SensitiveParameter] string $key): void
    {
        $this->update('webServiceSecureHashKey', trim($key));
    }

    /** The secret to put on the SDK config for the selected terminal mode. */
    public function secureHashKeyFor(EcrMode $mode): string
    {
        return $mode === EcrMode::WebService ? $this->webServiceSecureHashKey() : $this->wifiSecureHashKey();
    }

    public function __debugInfo(): array
    {
        return ['environment' => $this->values['environment'], 'keys' => '<redacted>'];
    }

    private function update(string $name, string $value): void
    {
        $next = $this->values;
        $next[$name] = $value;
        $this->values = $next;
        try {
            $this->write($next);
            $this->saveError = null;
            // The file that could not be read has just been replaced by a good one.
            $this->loadWarning = null;
        } catch (\RuntimeException | \JsonException $e) {
            // The value stays in memory for this request, but say so: a key that
            // silently fails to persist is a support call on the next request.
            $this->saveError = 'Could not save settings: ' . $e->getMessage();
        }
    }

    private function load(): void
    {
        if (!is_file($this->path)) {
            return;
        }
        try {
            $data = @file_get_contents($this->path);
            if ($data === false) {
                throw new \RuntimeException('The file could not be read.');
            }
            $values = json_decode($this->protector->unprotect($data), true, 4, \JSON_THROW_ON_ERROR);
            if (!\is_array($values)) {
                throw new \RuntimeException('The file is not in the expected format.');
            }
            foreach (['environment', 'wifiSecureHashKey', 'webServiceSecureHashKey'] as $name) {
                if (isset($values[$name]) && \is_string($values[$name])) {
                    $this->values[$name] = $values[$name];
                }
            }
        } catch (\RuntimeException | \JsonException $e) {
            $this->loadWarning = 'The saved settings could not be read (they are encrypted with the key in the data folder): '
                . $e->getMessage() . ' Enter the signing keys again.';
            $this->values = ['environment' => 'SIT', 'wifiSecureHashKey' => '', 'webServiceSecureHashKey' => ''];
        }
    }

    /** @param array<string, string> $values */
    private function write(array $values): void
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create ' . $directory);
        }
        $bytes = $this->protector->protect(json_encode($values, \JSON_THROW_ON_ERROR));
        $temp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $bytes, \LOCK_EX) === false || !@rename($temp, $this->path)) {
            @unlink($temp);
            throw new \RuntimeException('The settings file could not be written.');
        }
    }
}
