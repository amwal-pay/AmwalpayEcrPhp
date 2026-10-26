<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Integration;

use AmwalPay\Ecr\Channel\EcrChannelTimeout;
use AmwalPay\Ecr\Channel\EcrFrames;
use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\Channel\TcpEcrChannel;
use AmwalPay\Ecr\EcrTerminal;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Failure\Unreachable;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Tests\Support\BackgroundProcess;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use PHPUnit\Framework\TestCase;

/**
 * The TCP channel against a real socket on the loopback interface: the one
 * place the operating system's TCP stack is on the other end. Includes the two
 * deliberate deviations from the Kotlin SDK the Windows SDK pins: a connect
 * failure is unreachable (nothing sent), a reset after sending is a lost
 * connection (outcome unknown).
 */
final class TcpChannelTest extends TestCase
{
    use TempState;

    private ?string $record = null;

    private function server(string $modes, ?string $answer = null): BackgroundProcess
    {
        $this->record = $this->stateDir() . '/requests.log';
        $arguments = [__DIR__ . '/../Support/scripted-tcp-server.php', '--modes=' . $modes, '--record=' . $this->record];
        if ($answer !== null) {
            $arguments[] = '--answer=' . base64_encode($answer);
        }
        return new BackgroundProcess($arguments);
    }

    /** @return list<string> */
    private function rawRequests(): array
    {
        if ($this->record === null || !is_file($this->record)) {
            return [];
        }
        return array_map(static fn (string $line) => (string) base64_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($this->record)))));
    }

    private static function closedPort(): int
    {
        return BackgroundProcess::freePort();
    }

    public function testARequestIsFramedOnTheWireAndTheAnswerBodyIsReturned(): void
    {
        $server = $this->server('answer', '{"responseCode":"00"}');

        $body = (new TcpEcrChannel('127.0.0.1', $server->port))->exchange('{"messageType":"SALE"}', 10);

        self::assertSame('{"responseCode":"00"}', $body);
        $raw = $this->rawRequests()[0];
        self::assertSame("\x00\x16", substr($raw, 0, 2));
        self::assertSame('{"messageType":"SALE"}', substr($raw, 2));
    }

    public function testLongAndNonAsciiMessagesRoundTripBothWays(): void
    {
        $answer = '{"message":"تمت الموافقة — café €","pad":"' . str_repeat('y', 700) . '"}';
        $server = $this->server('answer', $answer);
        $request = '{"pad":"' . str_repeat('x', 400) . '","t":"Café ☕"}';

        self::assertSame($answer, (new TcpEcrChannel('127.0.0.1', $server->port))->exchange($request, 10));
        self::assertSame($request, substr($this->rawRequests()[0], 2));
    }

    public function testAnAnswerArrivingInPiecesIsAssembled(): void
    {
        $server = $this->server('pieces', '{"responseCode":"00","approved":true}');

        self::assertSame('{"responseCode":"00","approved":true}', (new TcpEcrChannel('127.0.0.1', $server->port))->exchange('{}', 10));
    }

    public function testEachExchangeUsesItsOwnConnection(): void
    {
        $server = $this->server('answer');
        $channel = new TcpEcrChannel('127.0.0.1', $server->port);

        $channel->exchange('{"n":1}', 10);
        $channel->exchange('{"n":2}', 10);

        self::assertSame(['{"n":1}', '{"n":2}'], array_map(static fn (string $r) => substr($r, 2), $this->rawRequests()));
    }

    public function testARequestTooLargeForAFrameIsRefusedBeforeConnecting(): void
    {
        $server = $this->server('answer');

        try {
            (new TcpEcrChannel('127.0.0.1', $server->port))->exchange(str_repeat('x', EcrFrames::MAX_BODY_BYTES + 1), 10);
            self::fail('sent an oversized frame');
        } catch (EcrIOException $e) {
            self::assertStringContainsString('does not fit a frame', $e->getMessage());
            self::assertStringNotContainsString('closed the connection', $e->getMessage());
        }
        self::assertSame([], $this->rawRequests());
    }

    public function testAnAnswerThatNeverComesIsAChannelTimeoutWithinItsBudget(): void
    {
        $server = $this->server('stall');
        $started = microtime(true);

        try {
            (new TcpEcrChannel('127.0.0.1', $server->port))->exchange('{}', 0.4);
            self::fail('expected a timeout');
        } catch (EcrChannelTimeout $e) {
            $elapsed = microtime(true) - $started;
            self::assertStringContainsString('did not answer in time', $e->getMessage());
            self::assertGreaterThanOrEqual(0.35, $elapsed, 'gave up early');
            self::assertLessThan(5, $elapsed, 'gave up late');
        }
    }

    public function testTheAnswerBudgetIsSeparateFromTheConnectBudget(): void
    {
        $server = $this->server('slow');

        self::assertNotSame('', (new TcpEcrChannel('127.0.0.1', $server->port, 0.2))->exchange('{}', 10));
    }

    public function testARefusedConnectionIsAnIoErrorNotATimeout(): void
    {
        $channel = new TcpEcrChannel('127.0.0.1', self::closedPort(), 3);

        try {
            $channel->exchange('{}', 10);
            self::fail('connected to a closed port');
        } catch (EcrIOException $e) {
            self::assertNotInstanceOf(EcrChannelTimeout::class, $e);
            self::assertNotSame('', trim($e->getMessage()));
        }
        self::assertNotNull($channel->probe(2), 'a refused probe says why');
    }

    public function testAConnectToAnUnroutableAddressIsUnreachableWithinItsBudget(): void
    {
        // 192.0.2.0/24 is TEST-NET-1 (RFC 5737): never routable.
        $started = microtime(true);
        $terminal = new EcrTerminal(new TcpEcrChannel('192.0.2.1', 9100, 0.3), 'TW1', TestKeys::unsigned($this->stateDir(), autoInquireOnFailure: true));

        $failed = $terminal->sale('1.234', 'ORD-1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Unreachable::class, $failed->failure);
        self::assertFalse($failed->failure->outcomeUnknown());
        self::assertNull($failed->recovered, 'nothing was sent, so nothing is asked twice');
        self::assertLessThan(5, microtime(true) - $started);

        $inquiry = (new EcrTerminal(new TcpEcrChannel('192.0.2.1', 9100, 0.3), 'TW1', TestKeys::unsigned($this->stateDir())))->inquireByReference('ORD-1');
        self::assertInstanceOf(InquiryFailed::class, $inquiry);
        self::assertNotInstanceOf(Timeout::class, $inquiry->failure, 'a connect timeout is never a response timeout');
    }

    public function testAServerThatClosesBeforeOrDuringTheAnswerIsAClosedConnection(): void
    {
        $drop = $this->server('drop');
        try {
            (new TcpEcrChannel('127.0.0.1', $drop->port))->exchange('{}', 10);
            self::fail('expected a closed connection');
        } catch (EcrIOException $e) {
            self::assertStringContainsString('closed the connection', $e->getMessage());
            self::assertStringContainsString('response header', $e->getMessage());
        }
        $drop->stop();

        $truncated = $this->server('truncated');
        try {
            (new TcpEcrChannel('127.0.0.1', $truncated->port))->exchange('{}', 10);
            self::fail('expected a closed connection');
        } catch (EcrIOException $e) {
            self::assertStringContainsString('response body', $e->getMessage());
        }
    }

    public function testAZeroLengthAnswerIsAnEmptyMessage(): void
    {
        $server = $this->server('zero');

        $this->expectExceptionMessage('empty message');
        (new TcpEcrChannel('127.0.0.1', $server->port))->exchange('{}', 10);
    }

    public function testAResetAfterTheRequestIsALostConnectionNotAnAddressProblem(): void
    {
        if (!\function_exists('socket_import_stream')) {
            self::markTestSkipped('ext-sockets is needed to send a TCP reset');
        }
        $server = $this->server('reset');

        try {
            (new TcpEcrChannel('127.0.0.1', $server->port))->exchange('{}', 10);
            self::fail('expected a lost connection');
        } catch (EcrIOException $e) {
            self::assertNotInstanceOf(EcrChannelTimeout::class, $e);
            self::assertStringStartsWith('Terminal closed the connection', $e->getMessage());
        }
    }

    public function testOverRealTcpAResetIsRecoveredByInquiryAndGarbageIsMalformed(): void
    {
        if (!\function_exists('socket_import_stream')) {
            self::markTestSkipped('ext-sockets is needed to send a TCP reset');
        }
        $server = $this->server('reset,answer', Wire::FOUND);
        $terminal = new EcrTerminal('127.0.0.1', 'TW1', TestKeys::unsigned($this->stateDir(), port: $server->port, autoInquireOnFailure: true));

        $failed = $terminal->sale('1.234', 'ORD-77');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(ConnectionLost::class, $failed->failure);
        self::assertStringStartsWith('Terminal closed the connection', $failed->failure->message);
        self::assertInstanceOf(Found::class, $failed->recovered);
        self::assertTrue($failed->settled());
        $requests = $this->rawRequests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('"messageType":"SALE"', $requests[0]);
        self::assertStringContainsString('"originalMerchantReference":"ORD-77"', $requests[1]);
        $server->stop();

        $garbage = $this->server('answer', 'this is not json');
        $malformed = (new EcrTerminal('127.0.0.1', 'TW1', TestKeys::unsigned($this->stateDir() . '/g', port: $garbage->port)))->sale('1', 'ORD-1');
        self::assertInstanceOf(Failed::class, $malformed);
        self::assertInstanceOf(Malformed::class, $malformed->failure);
    }

    public function testAProbeSendsNothingAndASaleRoundTrips(): void
    {
        $server = $this->server('answer', '{"success":true,"responseCode":"00","message":"Approved","data":{"merchantReference":"ORD-1","amount":"1.234","rrn":"1","authCode":"2"}}');
        $terminal = new EcrTerminal('127.0.0.1', 'TW1', TestKeys::unsigned($this->stateDir(), port: $server->port));

        self::assertTrue($terminal->isReachable());
        $approved = $terminal->sale('1.234', 'ORD-1');

        self::assertInstanceOf(Approved::class, $approved);
        self::assertSame('1.234', $approved->amount);
        self::assertCount(1, $this->rawRequests(), 'the probe sent nothing');
    }

    public function testTheChannelDescribesItsEndpoint(): void
    {
        $channel = new TcpEcrChannel('10.0.0.5', 9200);

        self::assertSame('10.0.0.5', $channel->host());
        self::assertSame(9200, $channel->port());
        self::assertSame('10.0.0.5:9200', $channel->endpoint());
        self::assertSame(9100, (new TcpEcrChannel('10.0.0.5'))->port());
        // As the Windows SDK: an empty host is not refused here; connecting fails instead.
        self::assertSame('', (new TcpEcrChannel(''))->host());
    }
}
