<?php

declare(strict_types=1);

namespace AmwalExample\Web;

/**
 * The data folder: `AMWAL_ECR_EXAMPLE_DATA`, or the per-user app-data folder
 * `AmwalECR-PHP-Example` (`%LOCALAPPDATA%` on Windows, `~/.local/share` elsewhere).
 *
 * | Path             | What                                                     |
 * |------------------|----------------------------------------------------------|
 * | `terminals.json` | Terminal list, keyed by serial                           |
 * | `settings.dat`   | Environment and both signing keys, encrypted             |
 * | `keys/`          | The key that decrypts settings.dat                       |
 * | `runs/`          | Server-owned transactions the page polls                 |
 * | `log.txt`        | The last 500 SDK log lines                               |
 * | `sdk-state/`     | The SDK's terminal lock and merchant-reference journal   |
 */
final class DataPaths
{
    public readonly string $root;

    public function __construct(?string $root = null)
    {
        $root ??= getenv('AMWAL_ECR_EXAMPLE_DATA') ?: null;
        if ($root === null || $root === '') {
            $base = getenv('LOCALAPPDATA') ?: (getenv('XDG_DATA_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/.local/share'));
            $root = rtrim($base, '\\/') . \DIRECTORY_SEPARATOR . 'AmwalECR-PHP-Example';
        }
        $this->root = rtrim($root, '\\/');
    }

    public function terminals(): string
    {
        return $this->root . \DIRECTORY_SEPARATOR . 'terminals.json';
    }

    public function settings(): string
    {
        return $this->root . \DIRECTORY_SEPARATOR . 'settings.dat';
    }

    public function keys(): string
    {
        return $this->root . \DIRECTORY_SEPARATOR . 'keys';
    }

    public function runs(): string
    {
        return $this->root . \DIRECTORY_SEPARATOR . 'runs';
    }

    public function log(): string
    {
        return $this->root . \DIRECTORY_SEPARATOR . 'log.txt';
    }

    public function sdkState(): string
    {
        return $this->root . \DIRECTORY_SEPARATOR . 'sdk-state';
    }

    /** Creates the folders. A root created here is private (0700 on Unix); `keys` always is. */
    public function ensure(): void
    {
        foreach ([$this->root, $this->keys(), $this->runs(), $this->sdkState()] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('The data folder cannot be created: ' . $directory);
            }
        }
        @chmod($this->keys(), 0700);
    }
}
