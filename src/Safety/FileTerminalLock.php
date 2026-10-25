<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Safety;

/**
 * A terminal lock as an exclusive `flock` on a file. The operating system
 * releases it if the process dies, so a crashed request never leaves a
 * terminal locked. Works on Windows (LockFileEx) and Unix.
 */
final class FileTerminalLock implements TerminalLock
{
    public function __construct(private readonly string $directory)
    {
    }

    public function tryAcquire(string $terminalKey): ?callable
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The ECR state folder cannot be created: ' . $this->directory);
        }
        $path = $this->directory . \DIRECTORY_SEPARATOR . 'terminal-' . substr(hash('sha256', $terminalKey), 0, 32) . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('The ECR terminal lock cannot be opened: ' . $path);
        }
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);
            return null;
        }
        $released = false;
        return static function () use ($handle, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            flock($handle, \LOCK_UN);
            fclose($handle);
        };
    }
}
