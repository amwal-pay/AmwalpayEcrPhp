<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Example;

use AmwalPay\Ecr\Tests\Support\BackgroundProcess;
use PHPUnit\Framework\TestCase;

/**
 * Hosts the example web till for real: PHP's built-in server on a temp data
 * folder, transactions in real worker processes, against the signed fake
 * terminal in tools/. Each test gets its own server, folder and terminal, and
 * one registered Wi-Fi terminal with the key saved through the app's own API,
 * like the web sample's Harness.
 */
abstract class WebExampleTestCase extends TestCase
{
    protected const KEY = '0123456789ABCDEF0123456789ABCDEF';
    protected const SERIAL = 'TW1234567890';

    protected string $dataDir = '';
    protected ?BackgroundProcess $server = null;
    /** @var list<BackgroundProcess> */
    protected array $terminals = [];
    protected string $base = '';

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'ecr-example-' . bin2hex(random_bytes(5));
        $port = BackgroundProcess::freePort();
        $root = \dirname(__DIR__, 2);
        $this->server = new BackgroundProcess(
            ['-S', '127.0.0.1:' . $port, '-t', $root . '/examples/web/public', $root . '/examples/web/public/index.php'],
            15.0,
            [
                'AMWAL_ECR_EXAMPLE_DATA' => $this->dataDir,
                'AMWAL_ECR_EXAMPLE_AUTOLOAD' => $root . '/vendor/autoload.php',
                'AMWAL_ECR_EXAMPLE_PHP' => \PHP_BINARY,
            ],
        );
        $this->base = 'http://127.0.0.1:' . $port;
    }

    protected function tearDown(): void
    {
        foreach ($this->terminals as $terminal) {
            $terminal->stop();
        }
        $this->server?->stop();
        // Workers may still be finishing; give them a moment before the folder goes.
        usleep(200_000);
        self::removeTree($this->dataDir);
    }

    /** Starts a signed fake terminal and returns its port. @param list<string> $flags */
    protected function fakeTerminal(array $flags = []): int
    {
        $terminal = new BackgroundProcess(array_merge([\dirname(__DIR__, 2) . '/tools/fake-pos-server.php', '--key', self::KEY, '--port', '0'], $flags));
        $this->terminals[] = $terminal;
        return $terminal->port;
    }

    /** Registers a Wi-Fi terminal on a fresh fake and saves the Wi-Fi key. @param list<string> $flags */
    protected function registerWifi(string $serial = self::SERIAL, array $flags = []): int
    {
        $port = $this->fakeTerminal($flags);
        $response = $this->call('POST', '/api/terminals', ['name' => 'Demo', 'serialNumber' => $serial, 'ecrMode' => 2, 'ipAddress' => '127.0.0.1', 'port' => (string) $port]);
        self::assertSame(200, $response['status'], json_encode($response['json']));
        $key = $this->call('PUT', '/api/settings/keys/wifi', ['value' => self::KEY]);
        self::assertSame(200, $key['status']);
        return $port;
    }

    /**
     * What the browser sends: JSON on every mutating call.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{status: int, json: mixed, body: string, headers: array<string, string>}
     */
    protected function call(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $mutating = $method !== 'GET';
        $headers += $mutating ? ['Content-Type' => 'application/json'] : [];
        $payload = $mutating ? json_encode($body ?? new \stdClass()) : null;
        return $this->raw($method, $path, $payload, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, json: mixed, body: string, headers: array<string, string>}
     */
    protected function raw(string $method, string $path, ?string $payload, array $headers = []): array
    {
        $handle = curl_init($this->base . $path);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];
        curl_setopt_array($handle, [
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_TIMEOUT => 30,
            \CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return \strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($handle, \CURLOPT_POSTFIELDS, $payload);
        }
        $text = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ['status' => $status, 'json' => json_decode($text, true), 'body' => $text, 'headers' => $received];
    }

    /** @return array<string, mixed> the 202 view of a started run */
    protected function start(array $form, string $serial = self::SERIAL): array
    {
        $response = $this->call('POST', '/api/transactions', ['terminalSerial' => $serial] + $form);
        self::assertSame(202, $response['status'], (string) $response['body']);
        return $response['json'];
    }

    /** Polls until the run is no longer busy (or fetching a receipt). @return array<string, mixed> */
    protected function settle(string $id, float $timeout = 30.0): array
    {
        $deadline = microtime(true) + $timeout;
        do {
            $view = $this->call('GET', '/api/transactions/' . $id)['json'];
            if (!$view['busy'] && !($view['dialog']['receipt']['fetching'] ?? false)) {
                return $view;
            }
            usleep(150_000);
        } while (microtime(true) < $deadline);
        self::fail('The run did not finish: ' . json_encode($view));
    }

    protected function log(): string
    {
        return (string) $this->call('GET', '/api/log')['json']['text'];
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($directory);
    }
}
