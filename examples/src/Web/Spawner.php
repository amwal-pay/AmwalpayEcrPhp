<?php

declare(strict_types=1);

namespace AmwalExample\Web;

/**
 * Starts the transaction worker (`web/bin/worker.php`) as a detached PHP CLI
 * process, so a payment is not tied to the HTTP request that started it: a
 * closed tab, a proxy that cuts the request, or the web server's own timeout
 * cannot stop it. The PHP counterpart of the web sample's `Task.Run`.
 *
 * The CLI binary is `AMWAL_ECR_EXAMPLE_PHP` when set; otherwise the running
 * binary under the CLI or built-in server, or `php.exe`/`php` next to
 * php-cgi under IIS/Apache.
 */
class Spawner
{
    public function __construct(private readonly DataPaths $paths)
    {
    }

    /** @param 'run'|'receipt' $mode */
    public function start(string $runId, int $generation, string $mode): void
    {
        $arguments = [self::php(), \dirname(__DIR__, 2) . '/web/bin/worker.php', $this->paths->root, $runId, (string) $generation, $mode];
        if (\PHP_OS_FAMILY === 'Windows') {
            $command = 'start "" /B ' . implode(' ', array_map(self::quoteWindows(...), $arguments)) . ' > NUL 2>&1';
            $handle = popen($command, 'r');
            if ($handle === false) {
                throw new \RuntimeException('The transaction worker could not be started');
            }
            pclose($handle);
            return;
        }
        exec(implode(' ', array_map('escapeshellarg', $arguments)) . ' > /dev/null 2>&1 &', $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException('The transaction worker could not be started');
        }
    }

    public static function php(): string
    {
        $configured = getenv('AMWAL_ECR_EXAMPLE_PHP');
        if ($configured !== false && $configured !== '') {
            return $configured;
        }
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'cli-server') {
            return \PHP_BINARY;
        }
        $beside = \dirname(\PHP_BINARY) . \DIRECTORY_SEPARATOR . (\PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        return is_file($beside) ? $beside : 'php';
    }

    private static function quoteWindows(string $argument): string
    {
        if (preg_match('/["%!^&|<>]/', $argument) === 1) {
            throw new \RuntimeException('Unsupported character in a worker argument: ' . $argument);
        }
        return '"' . $argument . '"';
    }
}
