<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Integration;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\EcrOpenedSession;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Failure\Unresolved;
use AmwalPay\Ecr\Link\WebServiceLink;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Result\ReceiptClosed;
use AmwalPay\Ecr\Tests\Support\BackgroundProcess;
use AmwalPay\Ecr\Tests\Support\RewritingTransport;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\WebService\Http\CurlHttpTransport;
use AmwalPay\Ecr\WebService\WebServiceSecureHash;
use PHPUnit\Framework\TestCase;

/**
 * Web Service through the real cURL transport to a local fake Hub: real HTTP,
 * real status codes and deadlines, only the host changed.
 */
final class WebServiceHttpTest extends TestCase
{
    use TempState;

    private static ?BackgroundProcess $hub = null;
    private static string $record = '';

    public static function setUpBeforeClass(): void
    {
        self::$record = (string) tempnam(sys_get_temp_dir(), 'ecr-hub-');
        $port = BackgroundProcess::freePort();
        self::$hub = new BackgroundProcess(
            ['-S', '127.0.0.1:' . $port, __DIR__ . '/../Support/fake-hub.php'],
            15.0,
            ['AMWAL_FAKE_HUB_RECORD' => self::$record],
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$hub?->stop();
        @unlink(self::$record);
    }

    private ?RewritingTransport $http = null;

    private function session(string $mode, mixed ...$config): EcrOpenedSession
    {
        file_put_contents(self::$record, '');
        $this->http = new RewritingTransport(new CurlHttpTransport(), 'http://127.0.0.1:' . self::$hub?->port, $mode);
        $plan = EcrSessions::plan(
            new WebServiceLink('10001', '20002'),
            new EcrConfig(...array_merge(['secureHashKey' => TestKeys::WEB_SERVICE, 'environment' => EcrEnvironment::UAT, 'stateDirectory' => $this->stateDir()], $config)),
        );
        return EcrSessions::open('SN-WS-0001', $plan, null, $this->http);
    }

    /** @return list<array{path: string, contentType: string, body: string}> */
    private static function received(): array
    {
        return array_map(static fn (string $l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents(self::$record)))));
    }

    public function testAnApprovedSaleIsPostedSignedToTheSaleEndpoint(): void
    {
        $approved = $this->session('approve')->sale('1.234', 'WS-ORDER-1');

        self::assertInstanceOf(Approved::class, $approved);
        self::assertSame('1.234', $approved->amount);
        self::assertSame('https://test.amwalpg.com:25453/Ecr/Sale', $this->http?->urls[0], 'the SDK chose the UAT Hub');
        $received = self::received()[0];
        self::assertSame('/Ecr/Sale', $received['path']);
        self::assertStringStartsWith('application/json', $received['contentType']);
        $body = \AmwalPay\Ecr\Json\Json::parseObject($received['body']);
        self::assertSame(WebServiceSecureHash::sign($body, TestKeys::WEB_SERVICE), $body->str('secureHashValue'));
        self::assertSame('WS-ORDER-1', $body->str('merchantReference'));
    }

    public function testADeclineAndARejectedSignatureAreDeclines(): void
    {
        $declined = $this->session('decline')->sale('1.000', 'WS-D1');
        $rejected = $this->session('unauthorized')->sale('1.000', 'WS-D2');

        self::assertInstanceOf(Declined::class, $declined);
        self::assertSame('Insufficient funds', $declined->reason);
        self::assertInstanceOf(Declined::class, $rejected);
        self::assertSame('secureHashValue mismatch', $rejected->reason);
    }

    public function testEveryKindOfMissingAnswerIsAnUnknownOutcomeThatBlocksTheTerminal(): void
    {
        foreach (['hub-timeout', 'error-500', 'error-503-explained', 'empty', 'html'] as $i => $mode) {
            $session = $this->session($mode, stateDirectory: $this->stateDir() . '/' . $i);
            $failed = $session->sale('1.000', 'WS-U' . $i);
            self::assertInstanceOf(Failed::class, $failed, $mode);
            self::assertTrue($failed->failure->outcomeUnknown(), $mode);
            self::assertNull($failed->recovered, 'Web Service never inquires by itself');

            $blocked = $session->sale('1.000', 'WS-NEXT' . $i);
            self::assertInstanceOf(Failed::class, $blocked);
            self::assertInstanceOf(Unresolved::class, $blocked->failure, $mode);
        }
    }

    public function testTheClientDeadlineEndsTheWaitAsATimeout(): void
    {
        $started = microtime(true);
        $failed = $this->session('slow', connectTimeout: 1.0, responseTimeout: 1.0)->sale('1.000', 'WS-SLOW');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Timeout::class, $failed->failure);
        self::assertStringContainsString('Timed out after 2s', $failed->failure->message);
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function testInquiryByReferenceSettlesAnUnknownOutcome(): void
    {
        $session = $this->session('hub-timeout');
        self::assertInstanceOf(Failed::class, $session->sale('1.234', 'WS-R1'));
        self::assertCount(1, $session->pendingOutcomes());

        $this->http?->mode('approve');
        $found = $session->inquireByReference('WS-R1');

        self::assertInstanceOf(Found::class, $found);
        self::assertSame('000215', $found->transaction->stan);
        self::assertSame([], $session->pendingOutcomes());
        self::assertStringEndsWith('/Ecr/Inquiry', $this->http?->urls[1] ?? '');
    }

    public function testNotFoundAndCloseReceipt(): void
    {
        self::assertInstanceOf(NotFound::class, $this->session('not-found')->inquire('215', '20261025'));
        self::assertInstanceOf(ReceiptClosed::class, $this->session('approve')->closeReceipt(originalMerchantReference: 'WS-ORDER-1'));
        self::assertSame('/Ecr/closeReceipt', self::received()[0]['path']);
    }
}
