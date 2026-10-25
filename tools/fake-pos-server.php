<?php

declare(strict_types=1);

/**
 * Stand-in for the POS application's Wi-Fi ECR listener, signed edition.
 *
 * A PHP port of AmwalpayEcrWindowsExample/Windows-Native-Example/tools/
 * fake_pos_server_signed.py, written without the SDK so it checks the SDK
 * rather than agreeing with it:
 *
 *   - verifies the request's `secureHash` and refuses a repeated `nonce`, as the
 *     terminal does;
 *   - answers in the unified envelope { success, responseCode, message, data,
 *     errorList } and echoes the request's `nonce`;
 *   - signs the answer: HMAC-SHA256 over the sorted top-level scalar key=value
 *     pairs joined by '&' (excluding secureHash), keyed with the HEX-DECODED
 *     secret, as uppercase hex.
 *
 * Framing: a 2-byte big-endian length, then that many bytes of UTF-8 JSON.
 *
 *   php tools/fake-pos-server.php --key 0123456789ABCDEF0123456789ABCDEF --port 19100
 *   php tools/fake-pos-server.php --key <hex> --decline 51 --delay 3
 *   php tools/fake-pos-server.php --key <hex> --partial
 *   php tools/fake-pos-server.php --key <hex> --drop-sale                       lost answer, SDK recovers
 *   php tools/fake-pos-server.php --key <hex> --drop-sale --drop-first-inquiry  lost answer, operator inquires
 *   php tools/fake-pos-server.php --key <hex> --not-found
 *   php tools/fake-pos-server.php --key <hex> --stall-sale [--stall-seconds 30] sale not answered in time
 *   php tools/fake-pos-server.php --key <hex> --reset-sale                      TCP reset after the sale
 *   php tools/fake-pos-server.php --key <hex> --garbage-sale                    unreadable answer to the sale
 *   php tools/fake-pos-server.php --key <hex> --wrong-key-sale                  sale answer signed with another key
 *   php tools/fake-pos-server.php --key <hex> --busy                            every sale refused as busy
 *   php tools/fake-pos-server.php --key <hex> --answer-version 2                answers declare a newer format
 *   php tools/fake-pos-server.php --key <hex> --no-close-receipt                old firmware: CLOSE_RECEIPT refused
 *
 * Use a port outside 9100-9199 on a development PC (Flutter tooling listens
 * there). Register the same key as the Wi-Fi secure hash key in the till.
 * Port 0 picks a free port; the first line printed is "LISTENING <host>:<port>".
 */

const MIN_KEY_HEX_CHARS = 16;

$options = getopt('', [
    'key:', 'host:', 'port:', 'decline:', 'delay:', 'not-found', 'partial', 'drop-sale', 'drop-first-inquiry',
    'stall-sale', 'reset-sale', 'garbage-sale', 'wrong-key-sale', 'busy', 'answer-version:', 'no-close-receipt',
    'max-connections:', 'stall-seconds:', 'help',
]);
if (isset($options['help']) || !isset($options['key'])) {
    fwrite(STDERR, "usage: php tools/fake-pos-server.php --key <hex> [--host 127.0.0.1] [--port 19100] [flags]\n");
    fwrite(STDERR, "see the header of this file for the flags\n");
    exit(isset($options['help']) ? 0 : 2);
}
$key = trim((string) $options['key']);
if (strlen($key) < MIN_KEY_HEX_CHARS || strlen($key) % 2 !== 0 || !ctype_xdigit($key)) {
    fwrite(STDERR, "--key must be an even-length hex string of at least 16 characters\n");
    exit(2);
}

$server = new FakeTerminal(
    key: $key,
    declineCode: isset($options['decline']) ? (string) $options['decline'] : null,
    delay: (float) ($options['delay'] ?? 0),
    notFound: isset($options['not-found']),
    partial: isset($options['partial']),
    dropSale: isset($options['drop-sale']),
    inquiryDropsLeft: isset($options['drop-first-inquiry']) ? 1 : 0,
    stallSale: isset($options['stall-sale']),
    resetSale: isset($options['reset-sale']),
    garbageSale: isset($options['garbage-sale']),
    wrongKeySale: isset($options['wrong-key-sale']),
    busy: isset($options['busy']),
    answerVersion: isset($options['answer-version']) ? (int) $options['answer-version'] : null,
    noCloseReceipt: isset($options['no-close-receipt']),
    stallSeconds: (float) ($options['stall-seconds'] ?? 30),
);
$server->serve((string) ($options['host'] ?? '127.0.0.1'), (int) ($options['port'] ?? 19100), (int) ($options['max-connections'] ?? 0));

final class FakeTerminal
{
    /** @var array<string, array<string, mixed>> */
    private array $byReference = [];
    /** @var array<string, true> */
    private array $seenNonces = [];
    private int $nextStan = 24;

    public function __construct(
        private string $key,
        private ?string $declineCode,
        private float $delay,
        private bool $notFound,
        private bool $partial,
        private bool $dropSale,
        private int $inquiryDropsLeft,
        private bool $stallSale,
        private bool $resetSale,
        private bool $garbageSale,
        private bool $wrongKeySale,
        private bool $busy,
        private ?int $answerVersion,
        private bool $noCloseReceipt,
        private float $stallSeconds = 30.0,
    ) {
    }

    public function serve(string $host, int $port, int $maxConnections): void
    {
        $listener = @stream_socket_server('tcp://' . $host . ':' . $port, $errno, $errstr);
        if ($listener === false) {
            fwrite(STDERR, "cannot listen on $host:$port: $errstr\n");
            exit(1);
        }
        $name = (string) stream_socket_get_name($listener, false);
        echo 'LISTENING ', $name, "\n";
        echo "Signing with the key you passed; enter the same key as the Wi-Fi secure hash key in the till.\n";
        flush();

        $served = 0;
        while ($maxConnections === 0 || $served < $maxConnections) {
            $client = @stream_socket_accept($listener, -1);
            if ($client === false) {
                continue;
            }
            $served++;
            try {
                $this->handle($client);
            } catch (Throwable $e) {
                $this->log('!! ' . $e->getMessage());
            }
            if (is_resource($client)) {
                @fclose($client);
            }
        }
    }

    /** @param resource $client */
    private function handle($client): void
    {
        stream_set_timeout($client, 5);
        $header = $this->readExactly($client, 2);
        if ($header === null) {
            return; // a reachability probe connects and leaves without sending anything
        }
        $length = unpack('n', $header)[1];
        $body = $this->readExactly($client, $length);
        if ($body === null) {
            return;
        }
        $request = json_decode($body, true);
        if (!is_array($request)) {
            $this->log('bad request: not a JSON object');
            return;
        }
        $this->log('--> ' . $body);

        if ($this->delay > 0) {
            usleep((int) ($this->delay * 1e6));
        }

        $type = (string) ($request['messageType'] ?? '');
        $problem = $this->checkRequest($request);
        $signingKey = $this->key;

        if ($problem !== null) {
            $this->log('!! ' . $problem);
            $response = self::envelope(false, '96', $problem, null, [$problem]);
        } elseif ($type === 'INQUIRY') {
            if ($this->inquiryDropsLeft > 0) {
                $this->inquiryDropsLeft--;
                $this->log('.. closing without an answer (--drop-first-inquiry)');
                return;
            }
            $response = $this->inquiry($request);
        } elseif ($type === 'RECEIPT') {
            $response = $this->receipt($request);
        } elseif ($type === 'CLOSE_RECEIPT') {
            $response = $this->noCloseReceipt
                ? self::envelope(false, '96', 'Unsupported', null, ['CLOSE_RECEIPT not supported'])
                : self::envelope(true, '00', 'OK', ['merchantReference' => $request['merchantReference'] ?? '']);
        } else {
            if ($this->busy && $type === 'SALE') {
                $response = self::envelope(false, 'BUSY', 'Terminal is busy with another transaction', null, ['Terminal busy']);
            } else {
                $response = $this->transaction($request, $type);
            }
            if ($type === 'SALE') {
                if ($this->dropSale) {
                    $this->log('.. sale recorded, closing without an answer (--drop-sale)');
                    return;
                }
                if ($this->stallSale) {
                    $this->log('.. sale recorded, never answering (--stall-sale)');
                    $this->stallSale = false;
                    usleep((int) ($this->stallSeconds * 1e6));
                    return;
                }
                if ($this->resetSale) {
                    $this->log('.. sale recorded, resetting the connection (--reset-sale)');
                    $this->reset($client);
                    return;
                }
                if ($this->garbageSale) {
                    $this->log('.. sale recorded, answering garbage (--garbage-sale)');
                    $this->send($client, '<html>gateway error</html>');
                    return;
                }
                if ($this->wrongKeySale) {
                    $signingKey = str_repeat('c', 32);
                }
            }
        }

        if ($this->answerVersion !== null) {
            $response['version'] = $this->answerVersion;
        }
        $response['nonce'] = (string) ($request['nonce'] ?? '');
        $response['secureHash'] = self::sign($response, $signingKey);
        $text = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->send($client, (string) $text);
        $this->log('<-- ' . $text);
    }

    /** @param array<string, mixed> $request */
    private function checkRequest(array $request): ?string
    {
        $presented = (string) ($request['secureHash'] ?? '');
        if ($presented === '') {
            return 'request is not signed';
        }
        if (!hash_equals(self::sign($request, $this->key), strtoupper($presented))) {
            return "request signature does not match this terminal's key";
        }
        $nonce = (string) ($request['nonce'] ?? '');
        if (isset($this->seenNonces[$nonce])) {
            return 'nonce already used (replay)';
        }
        $this->seenNonces[$nonce] = true;
        return null;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function transaction(array $request, string $type): array
    {
        $reference = (string) ($request['merchantReference'] ?? '');
        $code = $this->declineCode ?? '00';
        if ($code !== '00') {
            return self::envelope(false, $code, 'Declined', null, ['Declined by the issuer (' . $code . ')']);
        }
        $original = $type !== 'SALE' ? $this->findByStan((string) ($request['stan'] ?? '')) : null;
        $requested = isset($request['amount']) ? self::majorUnits((string) $request['amount']) : ($original['amount'] ?? '0.258');
        $settled = $requested;
        $partial = false;
        if ($this->partial && $type === 'SALE') {
            $settled = self::majorUnits((string) intdiv((int) $request['amount'], 2));
            $partial = true;
        }
        $stan = sprintf('%06d', $this->nextStan++);
        $this->byReference[$reference] = [
            'stan' => $stan, 'type' => ucfirst(strtolower($type)), 'amount' => $requested, 'authorized' => $settled,
            'partial' => $partial, 'time' => date('Y-m-d\TH:i:s'),
        ];
        return self::envelope(true, '00', 'Approved', [
            'merchantReference' => $reference,
            'amount' => $requested,
            'authorizeAmount' => $settled,
            'isPartialApprove' => $partial,
            'rrn' => (string) random_int(10 ** 11, 10 ** 12 - 1),
            'authCode' => $type === 'VOID' ? '000000' : (string) random_int(100000, 999999),
            'cardMask' => '483312******1234',
            'terminalSerial' => (string) ($request['terminalSerial'] ?? ''),
            'transactionType' => ucfirst(strtolower($type)),
        ]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function inquiry(array $request): array
    {
        $transaction = $this->notFound ? null : $this->find($request);
        if ($transaction === null) {
            return self::envelope(false, '25', 'No transactions found for the provided STAN and Terminal', null);
        }
        return self::envelope(true, '00', 'Transaction found', [
            'transactionId' => '0e9a7c00-0000-4000-8000-000000000001',
            'stan' => $transaction['stan'],
            'transactionType' => $transaction['type'],
            'transactionTypeDisplayName' => $transaction['type'],
            'status' => 'Approved',
            'amount' => $transaction['amount'],
            'authorizeAmount' => $transaction['authorized'],
            'isPartialApprove' => $transaction['partial'],
            'totalAmount' => $transaction['amount'],
            'currency' => 'OMR',
            'transactionTime' => $transaction['time'],
            'cardNumber' => '483312******1234',
            'rrn' => '1000000000000000000001',
            'batchId' => '00000003',
            'terminalId' => ($request['originalTerminalId'] ?? '') !== '' ? $request['originalTerminalId'] : '30001',
            'isRefunded' => false,
            'canVoid' => true,
            'canRefund' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function receipt(array $request): array
    {
        if ($this->notFound) {
            return self::envelope(false, '91', 'The receipt could not be generated', null);
        }
        $stan = (string) ($request['stan'] ?? '');
        return self::envelope(true, '00', 'Receipt ready', [
            'receiptUrl' => 'https://example.invalid/receipt?stan=' . $stan,
            'transactionId' => '0e9a7c00-0000-4000-8000-000000000001',
            'stan' => $stan,
        ]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function find(array $request): ?array
    {
        $byReference = (string) ($request['originalMerchantReference'] ?? '');
        if ($byReference !== '') {
            return $this->byReference[$byReference] ?? null;
        }
        $stan = (string) ($request['stan'] ?? '');
        return $this->findByStan($stan) ?? [
            'stan' => $stan, 'type' => 'Purchase', 'amount' => '0.258', 'authorized' => '0.258', 'partial' => false,
            'time' => '2026-08-09T16:58:16.804271',
        ];
    }

    /** @return array<string, mixed>|null */
    private function findByStan(string $stan): ?array
    {
        foreach ($this->byReference as $transaction) {
            if ($transaction['stan'] === $stan) {
                return $transaction;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed>|null $data
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private static function envelope(bool $success, string $code, string $message, ?array $data, array $errors = []): array
    {
        return ['success' => $success, 'responseCode' => $code, 'message' => $message, 'data' => $data, 'errorList' => $errors];
    }

    /** @param array<string, mixed> $object */
    private static function compose(array $object): string
    {
        $pairs = [];
        foreach ($object as $name => $value) {
            if ($name === 'secureHash' || $value === null || is_array($value)) {
                continue;
            }
            $pairs[(string) $name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        uksort($pairs, 'strcmp'); // ordinal, like the SDK
        $parts = [];
        foreach ($pairs as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('&', $parts);
    }

    /** @param array<string, mixed> $object */
    private static function sign(array $object, string $keyHex): string
    {
        return strtoupper(hash_hmac('sha256', self::compose($object), (string) hex2bin($keyHex)));
    }

    private static function majorUnits(string $minor, int $digits = 3): string
    {
        $text = str_pad((string) (int) $minor, $digits + 1, '0', STR_PAD_LEFT);
        return substr($text, 0, -$digits) . '.' . substr($text, -$digits);
    }

    /** @param resource $client */
    private function readExactly($client, int $count): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $count) {
            $chunk = @fread($client, $count - strlen($buffer));
            if ($chunk === false || ($chunk === '' && (feof($client) || stream_get_meta_data($client)['timed_out']))) {
                return null;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /** @param resource $client */
    private function send($client, string $body): void
    {
        fwrite($client, pack('n', strlen($body)) . $body);
        fflush($client);
    }

    /** @param resource $client */
    private function reset($client): void
    {
        if (function_exists('socket_import_stream')) {
            $socket = socket_import_stream($client);
            if ($socket !== false && $socket !== null) {
                socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                socket_close($socket);
                return;
            }
        }
        fclose($client); // no ext-sockets: a plain close, which reads as a dropped connection
    }

    private function log(string $line): void
    {
        echo '[', date('H:i:s'), '] ', $line, "\n";
        flush();
    }
}
