<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Support;

/** A private state folder per test, removed afterwards. */
trait TempState
{
    private ?string $stateDir = null;

    protected function stateDir(): string
    {
        if ($this->stateDir === null) {
            $this->stateDir = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'amwalpay-ecr-test-' . bin2hex(random_bytes(6));
            mkdir($this->stateDir, 0700, true);
        }
        return $this->stateDir;
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function removeStateDir(): void
    {
        if ($this->stateDir === null || !is_dir($this->stateDir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->stateDir);
        $this->stateDir = null;
    }
}
