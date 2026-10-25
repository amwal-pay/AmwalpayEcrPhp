<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\EcrWebServiceTerminal;
use AmwalPay\Ecr\EcrWireResponse;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Invalid;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\CloseReceiptFailed;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\NextStep;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Result\ReceiptClosed;
use AmwalPay\Ecr\Tests\Support\FakeHttpTransport;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use AmwalPay\Ecr\WebService\Http\HttpResponse;
use AmwalPay\Ecr\WebService\Http\HttpTransportException;
use AmwalPay\Ecr\WebService\WebServiceApi;
use AmwalPay\Ecr\WebService\WebServiceOperation;
use AmwalPay\Ecr\WebService\WebServiceRequests;
use AmwalPay\Ecr\WebService\WebServiceRoutes;
use AmwalPay\Ecr\WebService\WebServiceSecureHash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Web Service against a scripted transport: what is posted where, and how
 * every outcome, including no outcome, becomes one shape. A transport failure
 * read naively is a decline or "not found", both of which invite a till to
 * charge again.
 */
final class WebServiceTest extends TestCase
{
    use TempState;

    private function terminal(FakeHttpTransport $http, ?EcrConfig $config = null, ?EcrLogger $logger = null): EcrWebServiceTerminal
    {
        return new EcrWebServiceTerminal('TW1', $config ?? TestKeys::webService($this->stateDir()), $logger, $http);
    }

    private static function throws(string $message, bool $timedOut = false): FakeHttpTransport
    {
        return new FakeHttpTransport(static fn () => throw new HttpTransportException($message, $timedOut));
    }

    // ── Routes ────────────────────────────────────────────────────────

    public function testEachEnvironmentResolvesItsHubAndOperationsHaveFixedPaths(): void
    {
        $sit = new WebServiceRoutes(EcrEnvironment::SIT);
        $uat = new WebServiceRoutes(EcrEnvironment::UAT);
        $prod = new WebServiceRoutes(EcrEnvironment::PROD);

        self::assertSame('https://test.amwalpg.com:25452', $sit->hub());
        self::assertSame('https://test.amwalpg.com:25453/Ecr/Sale', $uat->url(WebServiceOperation::Sale));
        self::assertSame('https://test.amwalpg.com:25453/Ecr/Void', $uat->url(WebServiceOperation::Void));
        self::assertSame('https://test.amwalpg.com:25453/Ecr/Refund', $uat->url(WebServiceOperation::Refund));
        self::assertSame('https://test.amwalpg.com:25453/Ecr/Inquiry', $uat->url(WebServiceOperation::TransactionStatus));
        self::assertSame('https://test.amwalpg.com:25453/Ecr/closeReceipt', $uat->url(WebServiceOperation::CloseReceipt));
        self::assertSame('https://pos.amwalpg.com/Ecr/Sale', $prod->url(WebServiceOperation::Sale));
        self::assertNull($sit->pathFor(EcrTransactionType::Receipt));
        self::assertSame('Transaction status', WebServiceOperation::TransactionStatus->label());
    }

    public function testEnvironmentNamesAreReadIgnoringCaseAndUnknownOnesFallBackToSit(): void
    {
        self::assertSame(EcrEnvironment::SIT, EcrEnvironment::fromName(null));
        self::assertSame(EcrEnvironment::UAT, EcrEnvironment::fromName('uat'));
        self::assertSame(EcrEnvironment::PROD, EcrEnvironment::fromName('  prod  '));
        self::assertSame(EcrEnvironment::SIT, EcrEnvironment::fromName('production'));
        self::assertSame(EcrEnvironment::SIT, EcrEnvironment::fromName(''));
    }

    // ── The HTTP layer ────────────────────────────────────────────────

    public function testASaleIsPostedAsJsonToTheEnvironmentsUrlWithTheConfiguredDeadline(): void
    {
        $http = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00"}');
        $config = TestKeys::webService($this->stateDir(), connectTimeout: 10, responseTimeout: 150);
        $api = new WebServiceApi($config, EcrLogger::none(), $http);
        $body = WebServiceRequests::sale(10001, 20001, 'TW1', 111, 'REF-1', transactionDateTime: '20260826115847');

        $answer = $api->postJson(WebServiceOperation::Sale, $body);

        self::assertTrue($answer->flag('success'));
        self::assertCount(1, $http->posts);
        self::assertSame('https://test.amwalpg.com:25453/Ecr/Sale', $http->posts[0]['url']);
        self::assertSame($body->toJson(), $http->posts[0]['body']);
        self::assertSame('application/json; charset=utf-8', $http->posts[0]['headers']['Content-Type']);
        self::assertSame('application/json', $http->posts[0]['headers']['Accept']);
        self::assertSame(10.0, $http->posts[0]['connect']);
        self::assertSame(160.0, $http->posts[0]['total'], 'the client deadline is connect + answer');
        self::assertStringStartsWith('{', $http->posts[0]['body'], 'no byte order mark');
    }

    public function testAnHttpErrorCarryingAnEnvelopeIsThatEnvelope(): void
    {
        $api = new WebServiceApi(TestKeys::webService($this->stateDir()), EcrLogger::none(), FakeHttpTransport::reply(
            400,
            '{"success":false,"responseCode":"400","message":"Invalid signature","errorList":["secureHashValue mismatch"]}',
        ));

        $wire = EcrWireResponse::parse($api->postJson(WebServiceOperation::Sale, WebServiceRequests::sale(1, 2, 'S', 1, 'R')));

        self::assertFalse($wire->success);
        self::assertSame('400', $wire->responseCode);
        self::assertSame('secureHashValue mismatch', $wire->displayMessage());
    }

    /** @return iterable<string, array{FakeHttpTransport, string, string}> */
    public static function noUsableAnswer(): iterable
    {
        yield 'network error' => [self::throws('Could not resolve host: test.amwalpg.com'), 'NO_RESPONSE', 'Could not resolve host'];
        yield 'deadline' => [self::throws('Operation timed out', true), 'NO_RESPONSE', 'Timed out after 2m 40s'];
        yield 'empty body' => [FakeHttpTransport::reply(200, '  '), '200', 'Empty response'];
        yield 'html' => [FakeHttpTransport::reply(200, '<html>bad gateway</html>'), '200', 'not valid JSON'];
        yield 'null' => [FakeHttpTransport::reply(200, 'null'), '200', 'not valid JSON'];
        yield 'array' => [FakeHttpTransport::reply(200, '[1,2]'), '200', 'not valid JSON'];
        yield 'repeated key' => [FakeHttpTransport::reply(200, '{"success":false,"success":true}'), '200', 'not valid JSON'];
        yield '5xx with nothing readable' => [FakeHttpTransport::reply(500, '{}', 'Server Error'), '500', 'Server Error'];
    }

    #[DataProvider('noUsableAnswer')]
    public function testNoUsableAnswerBecomesAnUnknownOutcomeEnvelopeNeverACrash(FakeHttpTransport $http, string $code, string $message): void
    {
        $api = new WebServiceApi(TestKeys::webService($this->stateDir(), responseTimeout: 150), EcrLogger::none(), $http);

        $answer = $api->postJson(WebServiceOperation::Sale, WebServiceRequests::sale(1, 2, 'S', 1, 'R'));
        $wire = EcrWireResponse::parse($answer);

        self::assertFalse($wire->success);
        self::assertSame($code, $wire->responseCode);
        self::assertStringContainsString($message, $wire->message);
        self::assertSame(WebServiceApi::UNKNOWN_OUTCOME_STEP, $answer->str('nextStep'));
    }

    // ── Sale, void, refund outcomes ──────────────────────────────────

    #[DataProvider('noUsableAnswer')]
    public function testASaleThatGotNoUsableAnswerIsAnUnknownOutcomeNotADecline(FakeHttpTransport $http): void
    {
        $result = $this->terminal($http)->sale('1.000', 'ORD-1');

        self::assertInstanceOf(Failed::class, $result);
        self::assertTrue($result->failure->outcomeUnknown());
        self::assertSame(NextStep::InquireByMerchantReference, $result->nextStep());
        self::assertNull($result->recovered, 'Web Service never inquires by itself');
        self::assertSame('ORD-1', $result->merchantReference);
    }

    public function testANetworkTimeoutIsATimeout(): void
    {
        $result = $this->terminal(self::throws('Operation timed out', true))->sale('1.000');

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(Timeout::class, $result->failure);
    }

    public function testA5xxThatExplainsItselfIsStillAnUnknownOutcome(): void
    {
        $result = $this->terminal(FakeHttpTransport::reply(502, '{"success":false,"responseCode":"502","message":"Terminal did not respond"}'))->sale('1.000');

        self::assertInstanceOf(Failed::class, $result);
        self::assertTrue($result->failure->outcomeUnknown());
    }

    public function testARealDeclineAndARejectedRequestAreDeclinesWithNoFollowUp(): void
    {
        $declined = $this->terminal(FakeHttpTransport::reply(200, '{"success":false,"responseCode":"51","message":"Insufficient funds"}'))->sale('1.000');
        $rejected = $this->terminal(FakeHttpTransport::reply(401, '{}'), TestKeys::webService($this->stateDir() . '/b'))->sale('1.000');

        self::assertInstanceOf(Declined::class, $declined);
        self::assertSame('51', $declined->responseCode);
        self::assertSame(NextStep::None, $declined->nextStep);
        self::assertInstanceOf(Declined::class, $rejected, 'the Hub refused the request outright: it did not act on it');
    }

    public function testAnApprovalIsReadAndTheBodyIsSignedWithTheWebServiceKey(): void
    {
        $http = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00","message":"Approved","data":{"merchantReference":"ORD-7","amount":"1.234","rrn":"623807390109","authCode":"713984","cardMask":"543173xxxx5785"}}');

        $approved = $this->terminal($http)->sale('1.234', 'ORD-7');

        self::assertInstanceOf(Approved::class, $approved);
        self::assertSame('1.234', $approved->amount);
        $sent = Wire::obj($http->posts[0]['body']);
        self::assertSame('SALE', $sent->str('messageType'));
        self::assertSame('10001', $sent->str('merchantId'));
        self::assertSame('000000001234', $sent->str('amount'));
        self::assertSame($sent->str('transactionDateTime'), $sent->str('requestDateTime'));
        self::assertSame(WebServiceSecureHash::sign($sent, TestKeys::WEB_SERVICE), $sent->str('secureHashValue'));
    }

    public function testVoidAndRefundGoToTheirOwnPathsWithTheirOwnFields(): void
    {
        $http = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00","data":{"amount":"0.500"}}');
        $terminal = $this->terminal($http);

        $terminal->void('8', 'VOID-1');
        $terminal->refund('0.5', '11', '20260826', 'REF-1');

        self::assertStringEndsWith('/Ecr/Void', $http->posts[0]['url']);
        self::assertStringEndsWith('/Ecr/Refund', $http->posts[1]['url']);
        $void = Wire::obj($http->posts[0]['body']);
        $refund = Wire::obj($http->posts[1]['body']);
        self::assertSame('000008', $void->str('stan'));
        self::assertSame('VOID-1', $void->str('originalMerchantReference'));
        self::assertSame('20001', $void->str('originalTerminalId'));
        self::assertSame('000000000500', $refund->str('amount'));
        self::assertSame('20260826', $refund->str('originalTransactionDate'));
    }

    public function testAnswersFromANewerProtocolAreUnknownOutcomes(): void
    {
        $result = $this->terminal(FakeHttpTransport::reply(200, '{"version":3,"success":true,"responseCode":"00"}'))->sale('1');

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(Malformed::class, $result->failure);
    }

    // ── Inquiry and close receipt ────────────────────────────────────

    public function testAnOutageDuringAnInquiryIsAFailedLookupNotNotFound(): void
    {
        $byReceipt = $this->terminal(self::throws('network down'))->inquire('215', '20260826');
        $byReference = $this->terminal(self::throws('network down'))->inquireByReference('ORD-1');

        self::assertInstanceOf(InquiryFailed::class, $byReceipt);
        self::assertInstanceOf(ConnectionLost::class, $byReceipt->failure);
        self::assertInstanceOf(InquiryFailed::class, $byReference);
    }

    public function testAnInquiryTheHubRejectsIsAFailedLookupNeverNotFound(): void
    {
        foreach ([[401, '{}'], [400, '{"success":false,"responseCode":"400","message":"Invalid signature"}'], [404, '{}']] as [$status, $body]) {
            $inquiry = $this->terminal(FakeHttpTransport::reply($status, $body))->inquireByReference('ORD-1');
            self::assertInstanceOf(InquiryFailed::class, $inquiry, 'HTTP ' . $status);
        }
    }

    public function testARealNotFoundIsStillNotFoundAndAFoundRecordIsRead(): void
    {
        $notFound = $this->terminal(FakeHttpTransport::reply(200, '{"success":false,"responseCode":"25","message":"Transaction not found"}'))->inquire('215', '20260826');
        $http = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00","data":{"stan":"000215","status":"Approved","amount":"1.234"}}');
        $found = $this->terminal($http)->inquireByReference('ORD-88231', '', 'INQ-1');

        self::assertInstanceOf(NotFound::class, $notFound);
        self::assertInstanceOf(Found::class, $found);
        $sent = Wire::obj($http->posts[0]['body']);
        self::assertSame('', $sent->str('stan'));
        self::assertSame('ORD-88231', $sent->str('originalMerchantReference'));
        self::assertSame('INQ-1', $sent->str('merchantReference'));
        self::assertStringEndsWith('/Ecr/Inquiry', $http->posts[0]['url']);
    }

    public function testCloseReceiptNeedsTheOriginalReferenceAndPostsIt(): void
    {
        $http = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00","message":"OK"}');

        $missing = $this->terminal($http)->closeReceipt(' ');
        $closed = $this->terminal($http)->closeReceipt('ORD-1');

        self::assertInstanceOf(CloseReceiptFailed::class, $missing);
        self::assertStringContainsString('original merchant reference', $missing->failure->message);
        self::assertInstanceOf(ReceiptClosed::class, $closed);
        self::assertCount(1, $http->posts);
        self::assertStringEndsWith('/Ecr/closeReceipt', $http->posts[0]['url']);
        self::assertSame('ORD-1', Wire::obj($http->posts[0]['body'])->str('originalMerchantReference'));
    }

    // ── Decided before anything leaves ───────────────────────────────

    public function testMissingOrNonNumericBackendIdsFailLocallyAndSendNothing(): void
    {
        $http = FakeHttpTransport::reply(200, '{}');
        foreach ([['', ''], ['M1', 'T1'], ['-5', '7'], ['10001', '1.5']] as [$merchant, $terminal]) {
            $ws = $this->terminal($http, new EcrConfig(merchantId: $merchant, terminalId: $terminal, stateDirectory: $this->stateDir()));
            $sale = $ws->sale('1', 'REF-1');
            $inquiry = $ws->inquire('24', '20260806', 'LOOKUP-1');
            self::assertInstanceOf(Failed::class, $sale);
            self::assertInstanceOf(Invalid::class, $sale->failure);
            self::assertFalse($sale->failure->outcomeUnknown(), 'nothing was sent');
            self::assertStringContainsString('merchantId and terminalId', $sale->failure->message);
            self::assertInstanceOf(InquiryFailed::class, $inquiry);
            self::assertSame('LOOKUP-1', $inquiry->merchantReference);
        }
        self::assertSame([], $http->posts);
    }

    public function testASignedBodyIsLoggedWithoutTheSecret(): void
    {
        $lines = [];
        $this->terminal(FakeHttpTransport::reply(200, '{"success":true}'), null, EcrLogger::from(static function (string $l) use (&$lines): void {
            $lines[] = $l;
        }))->sale('1', 'REF-1');

        $log = implode("\n", $lines);
        self::assertStringContainsString('[Web Service] SALE POST https://test.amwalpg.com:25453/Ecr/Sale (env=UAT)', $log);
        self::assertStringContainsString('signing key (Web Service secure hash key): configured', $log);
        self::assertStringNotContainsStringIgnoringCase(TestKeys::WEB_SERVICE, $log);
    }

    public function testIdsAreReadAsPositiveIntegers(): void
    {
        self::assertSame(10001, EcrWebServiceTerminal::id(' 10001 '));
        self::assertSame(7, EcrWebServiceTerminal::id('007'));
        self::assertNull(EcrWebServiceTerminal::id('-5'));
        self::assertNull(EcrWebServiceTerminal::id('99999999999999999999'));
        self::assertNull(EcrWebServiceTerminal::id(''));
    }

    public function testTheClockAnswersInTheConfiguredZone(): void
    {
        $http = FakeHttpTransport::reply(200, '{"success":true}');
        $this->terminal($http, TestKeys::webService($this->stateDir(), timezone: 'Pacific/Kiritimati'))->sale('1');

        $expected = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Kiritimati')))->format('Ymd');
        self::assertStringStartsWith($expected, Wire::obj($http->posts[0]['body'])->str('transactionDateTime'));
        unset($http);
        self::assertInstanceOf(HttpResponse::class, new HttpResponse(200, ''));
    }
}
