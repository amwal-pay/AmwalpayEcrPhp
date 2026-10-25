<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\EcrWebServiceTerminal;
use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Link\LanLink;
use AmwalPay\Ecr\Link\WebServiceLink;
use AmwalPay\Ecr\Result\CloseReceiptFailed;
use AmwalPay\Ecr\Result\ReceiptUnavailable;
use AmwalPay\Ecr\Tests\Support\FakeHttpTransport;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use AmwalPay\Ecr\WebService\WebServiceSecureHash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Configuration and sessions: open Wi-Fi and Web Service without copying SDK internals. */
final class SessionsAndConfigTest extends TestCase
{
    use TempState;

    // ── EcrConfig ─────────────────────────────────────────────────────

    public function testDefaultsDescribeAnOmaniDeploymentOnTheStandardPort(): void
    {
        $config = new EcrConfig();

        self::assertSame('ECR01', $config->ecrId);
        self::assertSame('512', $config->currencyCode);
        self::assertSame(3, $config->minorUnitDigits);
        self::assertSame(9100, $config->port);
        self::assertSame(10.0, $config->connectTimeout);
        self::assertSame(120.0, $config->responseTimeout);
        self::assertSame(3.0, $config->probeTimeout);
        self::assertSame('', $config->secureHashKey);
        self::assertSame(EcrEnvironment::SIT, $config->environment);
        self::assertTrue($config->autoInquireOnFailure);
        self::assertTrue($config->guardFinancialOperations);
        self::assertFalse($config->signsMessages());
        self::assertSame(160.0, EcrConfig::WEB_SERVICE_CLIENT_TIMEOUT);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'digits -1' => [['minorUnitDigits' => -1], 'minorUnitDigits'];
        yield 'digits 5' => [['minorUnitDigits' => 5], 'minorUnitDigits'];
        yield 'port 0' => [['port' => 0], 'port'];
        yield 'port 65536' => [['port' => 65536], 'port'];
        yield 'currency OMR' => [['currencyCode' => 'OMR'], 'currencyCode'];
        yield 'currency 12' => [['currencyCode' => '12'], 'currencyCode'];
        yield 'timeout 0' => [['responseTimeout' => 0], 'responseTimeout'];
        yield 'timeout negative' => [['connectTimeout' => -1], 'connectTimeout'];
        yield 'timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'];
        foreach (['nothex', 'abc', 'aabb', 'aabbccddeeff001', 'aabbccddeeff0011223', 'aabbccddeeff00', str_repeat('g', 24), ' ' . TestKeys::WIFI, TestKeys::WIFI . "\n"] as $key) {
            yield 'key ' . json_encode($key) => [['secureHashKey' => $key], 'secureHashKey'];
        }
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('invalidConfigs')]
    public function testAnInvalidValueIsRefusedTheMomentTheConfigIsBuilt(array $settings, string $named): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($named);
        new EcrConfig(...$settings);
    }

    public function testWellFormedKeysAreAcceptedAndWithCopiesAndValidates(): void
    {
        foreach (['', str_repeat('a', 16), str_repeat('A', 32), '0123456789abcdefABCDEF00'] as $key) {
            self::assertSame($key, (new EcrConfig(secureHashKey: $key))->secureHashKey);
        }
        self::assertSame('', (new EcrConfig(secureHashKey: null))->secureHashKey);

        $original = new EcrConfig(ecrId: 'TILL-1', currencyCode: '840', minorUnitDigits: 2, port: 9200, secureHashKey: TestKeys::WIFI, environment: EcrEnvironment::PROD);
        $changed = $original->with(responseTimeout: 60);
        self::assertSame(60.0, $changed->responseTimeout);
        self::assertSame(120.0, $original->responseTimeout);
        self::assertSame('TILL-1', $changed->ecrId);
        self::assertSame(TestKeys::WIFI, $changed->secureHashKey);
        self::assertSame(EcrEnvironment::PROD, $changed->environment);

        foreach ([['port' => 0], ['minorUnitDigits' => 9], ['secureHashKey' => 'abc'], ['noSuchSetting' => 1]] as $bad) {
            try {
                $original->with(...$bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheKeyNeverLeaksThroughTextDumpsOrSerialization(): void
    {
        $config = TestKeys::webService($this->stateDir());

        self::assertStringContainsString('secureHashKey=<redacted>', (string) $config);
        self::assertStringContainsString('environment=UAT', (string) $config);
        self::assertStringContainsString('merchantId=10001', (string) $config);
        self::assertStringContainsString('secureHashKey=empty', (string) new EcrConfig());
        foreach ([(string) $config, print_r($config, true), self::dump($config)] as $text) {
            self::assertStringNotContainsStringIgnoringCase(TestKeys::WEB_SERVICE, $text);
        }
        $this->expectException(\LogicException::class);
        serialize($config);
    }

    public function testTheKeyIsHiddenFromStackTraces(): void
    {
        if (\PHP_VERSION_ID < 80200) {
            self::markTestSkipped('#[SensitiveParameter] needs PHP 8.2');
        }
        try {
            new EcrConfig(port: 0, secureHashKey: TestKeys::WIFI);
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(TestKeys::WIFI, $e->getTraceAsString());
            // The SDK's own frame; outer PHPUnit frames carry other tests' data.
            self::assertSame('__construct', $e->getTrace()[0]['function']);
            self::assertStringNotContainsString(TestKeys::WIFI, print_r($e->getTrace()[0]['args'] ?? [], true));
        }
    }

    // ── Plans ─────────────────────────────────────────────────────────

    public function testALanPlanIsReadyWithHostPortAndKey(): void
    {
        $plan = EcrSessions::plan(new LanLink('192.168.1.10', 9100), new EcrConfig(secureHashKey: TestKeys::WIFI));

        self::assertTrue($plan->isReady());
        self::assertTrue($plan->usesLan());
        self::assertSame('192.168.1.10:9100', $plan->connectionSummary());
        self::assertSame(SecureHash::KEY_LABEL, $plan->signingKeyLabel());
        self::assertTrue($plan->signingKeyConfigured());
    }

    public function testALanPlanReportsWhatIsMissingWithoutThrowing(): void
    {
        $missing = EcrSessions::plan(new LanLink('  '), new EcrConfig());
        $badKey = EcrSessions::plan(new LanLink('127.0.0.1'), new EcrConfig(), rawSecureHashKey: 'abc');
        $url = EcrSessions::plan(new LanLink('http://192.168.1.10'), new EcrConfig(secureHashKey: TestKeys::WIFI));

        self::assertFalse($missing->isReady());
        self::assertContains('IP address is required for Wi-Fi ECR', $missing->issues());
        self::assertContains('Wi-Fi/CABLE secure hash key is not configured', $missing->issues());
        self::assertFalse($badKey->isReady());
        self::assertContains('Secure hash key must be an even-length hex string of at least 16 characters', $badKey->issues());
        self::assertSame('', $badKey->config->secureHashKey);
        self::assertFalse($url->isReady());
    }

    public function testTheTypedKeyIsTrimmedAndTheLinksPortWins(): void
    {
        $plan = EcrSessions::plan(new LanLink('10.0.0.5', 9200), new EcrConfig(port: 9100), rawSecureHashKey: '  ' . TestKeys::WIFI . "\n");

        self::assertTrue($plan->isReady());
        self::assertSame(TestKeys::WIFI, $plan->config->secureHashKey);
        self::assertSame(9200, $plan->config->port);
        self::assertSame('10.0.0.5:9200', $plan->connectionSummary());
    }

    /** @return iterable<array{int}> */
    public static function badPorts(): iterable
    {
        yield [0];
        yield [65536];
        yield [-1];
    }

    #[DataProvider('badPorts')]
    public function testAnOutOfRangePortIsAnIssueNotAnException(int $port): void
    {
        $plan = EcrSessions::plan(new LanLink('10.0.0.5', $port), new EcrConfig(secureHashKey: TestKeys::WIFI));

        self::assertFalse($plan->isReady());
        self::assertStringContainsString('Port must be between 1 and 65535', implode("\n", $plan->issues()));
    }

    public function testAWebServicePlanMergesTheIdsAndRaisesTheTimeoutTo160Seconds(): void
    {
        $plan = EcrSessions::plan(new WebServiceLink(' 10001 ', '20001'), new EcrConfig(secureHashKey: TestKeys::WEB_SERVICE, environment: EcrEnvironment::UAT));
        $custom = EcrSessions::plan(new WebServiceLink('10001', '20001'), new EcrConfig(secureHashKey: TestKeys::WEB_SERVICE, responseTimeout: 90));

        self::assertTrue($plan->isReady());
        self::assertTrue($plan->usesWebService());
        self::assertSame('10001', $plan->config->merchantId);
        self::assertSame('20001', $plan->config->terminalId);
        self::assertSame(160.0, $plan->config->connectTimeout + $plan->config->responseTimeout);
        self::assertSame(WebServiceSecureHash::KEY_LABEL, $plan->signingKeyLabel());
        self::assertSame('UAT · merchant 10001 · terminal 20001', $plan->connectionSummary());
        self::assertSame(90.0, $custom->config->responseTimeout, 'a wait the caller chose is kept');
    }

    public function testAWebServicePlanReportsMissingOrNonNumericIdsOnce(): void
    {
        $missing = EcrSessions::plan(new WebServiceLink('', ''), new EcrConfig());
        $letters = EcrSessions::plan(new WebServiceLink('M1', 'T1'), new EcrConfig(secureHashKey: TestKeys::WEB_SERVICE));

        self::assertContains('Merchant ID is required for Web Service', $missing->issues());
        self::assertContains('Terminal ID is required for Web Service', $missing->issues());
        self::assertContains('Web Service secure hash key is not configured', $missing->issues());
        self::assertSame($missing->issues(), array_values(array_unique($missing->issues())));
        self::assertContains('Merchant ID must be numeric', $letters->issues());
        self::assertContains('Terminal ID must be numeric', $letters->issues());
    }

    // ── Opening ───────────────────────────────────────────────────────

    public function testOpenDispatchesEachLinkToItsTransport(): void
    {
        $lan = EcrSessions::open('SN1', EcrSessions::plan(new LanLink('127.0.0.1'), new EcrConfig(secureHashKey: TestKeys::WIFI)));
        $web = EcrSessions::open('SN1', EcrSessions::plan(new WebServiceLink('10001', '20001'), new EcrConfig(secureHashKey: TestKeys::WEB_SERVICE)));

        self::assertTrue($lan->usesLocalTerminal());
        self::assertFalse($lan->usesWebService());
        self::assertTrue($lan->supportsReceipt());
        self::assertNotNull($lan->lanTerminal());
        self::assertTrue($web->usesWebService());
        self::assertFalse($web->supportsReceipt());
        self::assertNull($web->probeReachability(), 'the Hub has no probe');
        self::assertInstanceOf(EcrWebServiceTerminal::class, $web->webServiceTerminal());
    }

    public function testAPlanWithIssuesOrABlankSerialCannotBeOpened(): void
    {
        try {
            EcrSessions::open('SN1', EcrSessions::plan(new LanLink(''), new EcrConfig(secureHashKey: TestKeys::WIFI)));
            self::fail('opened a plan with issues');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('unresolved issues', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        EcrSessions::open('  ', EcrSessions::plan(new LanLink('127.0.0.1'), new EcrConfig(secureHashKey: TestKeys::WIFI)));
    }

    public function testAWebServiceSessionCannotFetchAReceiptAndNeedsTheOriginalReferenceToCloseOne(): void
    {
        $http = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00"}');
        $plan = EcrSessions::plan(new WebServiceLink('10001', '20001'), new EcrConfig(secureHashKey: TestKeys::WEB_SERVICE, stateDirectory: $this->stateDir()));
        $session = EcrSessions::open('SN1', $plan, null, $http);

        $receipt = $session->receipt('24', '20260817');
        $closed = $session->closeReceipt();
        $session->closeReceipt(originalMerchantReference: 'ORD-1');
        $session->closeReceipt('ORD-2');

        self::assertInstanceOf(ReceiptUnavailable::class, $receipt);
        self::assertStringContainsString('only supported over', $receipt->reason);
        self::assertInstanceOf(CloseReceiptFailed::class, $closed);
        self::assertStringContainsString('original merchant reference', $closed->failure->message);
        self::assertSame('ORD-1', Wire::obj($http->posts[0]['body'])->str('originalMerchantReference'));
        $single = Wire::obj($http->posts[1]['body']);
        self::assertSame('ORD-2', $single->str('originalMerchantReference'), 'one reference is the original');
        self::assertNotSame('ORD-2', $single->str('merchantReference'), 'and this request gets its own');
    }

    public function testNewMerchantReferencesHaveTheSdkShape(): void
    {
        self::assertMatchesRegularExpression('/^[0-9A-F]{12}$/', EcrSessions::newMerchantReference());
        self::assertNotSame(EcrSessions::newMerchantReference(), EcrSessions::newMerchantReference());
    }

    private static function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);
        return (string) ob_get_clean();
    }
}
