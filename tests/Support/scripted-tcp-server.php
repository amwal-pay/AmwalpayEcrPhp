<?php

declare(strict_types=1);

/**
 * A TCP server that misbehaves on purpose, one behaviour per connection, for
 * the channel tests. Prints "LISTENING host:port", then serves.
 *
 *   --modes=answer,reset   behaviour for connection 0, 1, ...; the last repeats
 *   --answer=<base64>      the JSON body for "answer" / "pieces" / "slow"
 *   --record=<file>        appends each raw request (base64) as a line
 *
 * Modes: answer, pieces (header and body in separate writes), slow (answers
 * after 600 ms), stall (never answers), drop (closes without answering),
 * truncated (half a body), zero (a zero-length frame), reset (TCP RST).
 */

$options = getopt('', ['modes:', 'answer::', 'record::']);
$modes = explode(',', (string) ($options['modes'] ?? 'answer'));
$answer = base64_decode((string) ($options['answer'] ?? base64_encode('{"responseCode":"00"}')), true) ?: '{}';
$record = $options['record'] ?? null;

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($listener === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}
echo 'LISTENING ', stream_socket_get_name($listener, false), "\n";
flush();

$held = [];
for ($connection = 0; ; $connection++) {
    $client = @stream_socket_accept($listener, -1);
    if ($client === false) {
        continue;
    }
    $mode = $modes[min($connection, count($modes) - 1)];
    stream_set_timeout($client, 2);
    $header = read($client, 2);
    $request = $header === null ? null : read($client, unpack('n', $header)[1]);
    if ($record !== null && $header !== null) {
        file_put_contents($record, base64_encode($header . ($request ?? '')) . "\n", FILE_APPEND | LOCK_EX);
    }
    if ($header === null) {
        fclose($client); // a probe
        continue;
    }
    switch ($mode) {
        case 'answer':
            fwrite($client, pack('n', strlen($answer)) . $answer);
            break;
        case 'pieces':
            $framed = pack('n', strlen($answer)) . $answer;
            foreach ([substr($framed, 0, 1), substr($framed, 1, 1), substr($framed, 2, 10), substr($framed, 12)] as $piece) {
                fwrite($client, $piece);
                fflush($client);
                usleep(50_000);
            }
            break;
        case 'slow':
            usleep(600_000);
            fwrite($client, pack('n', strlen($answer)) . $answer);
            break;
        case 'stall':
            $held[] = $client; // keep it open, never answer
            continue 2;
        case 'drop':
            break;
        case 'truncated':
            fwrite($client, pack('n', 64) . '{"responseCode":');
            break;
        case 'zero':
            fwrite($client, "\x00\x00");
            break;
        case 'reset':
            if (function_exists('socket_import_stream')) {
                $socket = socket_import_stream($client);
                socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                socket_close($socket);
                continue 2;
            }
            break;
    }
    @fclose($client);
}

function read($client, int $count): ?string
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
