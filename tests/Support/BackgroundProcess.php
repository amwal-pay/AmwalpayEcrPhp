<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Support;

/**
 * A PHP script run in the background for a test: a fake terminal, a scripted
 * TCP server, a fake Hub. Waits for its "LISTENING host:port" line (or PHP's
 * built-in server banner).
 *
 * Output goes to a temporary file rather than a pipe: PHP cannot make a pipe
 * non-blocking on Windows, and a blocked read would hang the test run.
 */
final class BackgroundProcess
{
    /** @var resource|null */
    private $process;
    private readonly string $outputFile;
    public readonly string $host;
    public readonly int $port;

    /**
     * @param list<string> $arguments
     * @param array<string, string>|null $env
     */
    public function __construct(array $arguments, float $startTimeout = 15.0, ?array $env = null)
    {
        $this->outputFile = (string) tempnam(sys_get_temp_dir(), 'ecr-bg-');
        $command = array_merge([\PHP_BINARY, '-d', 'display_errors=stderr'], $arguments);
        $environment = $env === null ? null : array_merge(getenv(), $env);
        $process = proc_open(
            $command,
            [0 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $this->outputFile, 'a'], 2 => ['file', $this->outputFile, 'a']],
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start ' . implode(' ', $arguments));
        }
        $this->process = $process;

        $deadline = microtime(true) + $startTimeout;
        while (microtime(true) < $deadline) {
            $output = $this->output();
            if (preg_match('/LISTENING (\S+):([0-9]+)|Development Server \(http:\/\/([^:]+):([0-9]+)\) started/', $output, $m) === 1) {
                $this->host = ($m[1] ?? '') !== '' ? $m[1] : $m[3];
                $this->port = (int) (($m[1] ?? '') !== '' ? $m[2] : $m[4]);
                return;
            }
            if (!proc_get_status($this->process)['running']) {
                break;
            }
            usleep(20_000);
        }
        $output = $this->output();
        $this->stop();
        throw new \RuntimeException('The background process did not start: ' . $output);
    }

    /** A free loopback port, for a server that cannot pick its own. */
    public static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('No free port');
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** Everything the process printed so far. */
    public function output(): string
    {
        clearstatcache(true, $this->outputFile);
        return is_file($this->outputFile) ? (string) @file_get_contents($this->outputFile) : '';
    }

    public function stop(): void
    {
        if (!\is_resource($this->process)) {
            return;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            if (\PHP_OS_FAMILY === 'Windows') {
                exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
            } else {
                proc_terminate($this->process, 9);
            }
        }
        proc_close($this->process);
        $this->process = null;
        @unlink($this->outputFile);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
