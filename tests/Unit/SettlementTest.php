<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrOpenedSession;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\EcrTerminal;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\EcrWebServiceTerminal;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Failure\Unauthenticated;
use AmwalPay\Ecr\Internal\WireReaders;
use AmwalPay\Ecr\Link\LanLink;
use AmwalPay\Ecr\Link\WebServiceLink;
use AmwalPay\Ecr\Result\EcrSettlementTotal;
use AmwalPay\Ecr\Result\NothingToSettle;
use AmwalPay\Ecr\Result\NotSettled;
use AmwalPay\Ecr\Result\SettlementFailed;
use AmwalPay\Ecr\Result\Settled;
use AmwalPay\Ecr\Tests\Support\FakeChannel;
use AmwalPay\Ecr\Tests\Support\FakeHttpTransport;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use AmwalPay\Ecr\WebService\Http\HttpTransportException;
use AmwalPay\Ecr\WebService\WebServiceOperation;
use AmwalPay\Ecr\WebService\WebServiceRequests;
use AmwalPay\Ecr\WebService\WebServiceRoutes;
use AmwalPay\Ecr\WebService\WebServiceSecureHash;
use AmwalPay\Ecr\EcrEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A settlement answers in four shapes (closed with totals, nothing to close,
 * left open, refused before it started) and a till decides what to tell the
 * cashier from which one it got. Ported from the Windows SDK's
 * SettlementReadingTests, plus both transports end to end, and the Web
 * Service reachability check that shares settle's body.
 */
final class SettlementTest extends TestCase
{
    use TempState;

    private const SETTLED = '{"success":true,"responseCode":"00","message":"Settlement successful","data":{"merchantReference":"SET01",'
        . '"settlementStatus":"SETTLED","batchId":"00000007","currency":"OMR","startTime":"20261020080000","endTime":"20261020130509",'
        . '"totalTransactions":4,"netAmount":"000000012500","totals":{"SALE":{"count":3,"amount":"000000014750"},'
        . '"REFUND":{"count":1,"amount":"000000002250"},"VOID":{"count":0,"amount":"000000000000"},'
        . '"PRE_AUTH":{"count":0,"amount":"000000000000"},"CAPTURE":{"count":0,"amount":"000000000000"}}}}';

    // ── Reading ───────────────────────────────────────────────────────

    public function testAClosedBatchReadsItsTotalsInMajorUnits(): void
    {
        $settled = WireReaders::toSettlement(Wire::obj(self::SETTLED), 'SET01', 3);

        self::assertInstanceOf(Settled::class, $settled);
        self::assertSame('SET01', $settled->merchantReference);
        self::assertSame('00000007', $settled->batchId);
        self::assertSame('OMR', $settled->currency);
        self::assertSame('20261020130509', $settled->endTime);
        self::assertSame(4, $settled->totalTransactions);
        self::assertSame('12.500', $settled->netAmount);
        self::assertEquals(new EcrSettlementTotal(3, '14.750'), $settled->totals['SALE']);
        self::assertEquals(new EcrSettlementTotal(1, '2.250'), $settled->totals['REFUND']);
        self::assertEquals(new EcrSettlementTotal(0, '0.000'), $settled->totals['VOID']);
        self::assertSame('settled', $settled->outcome());
        self::assertSame(['count' => 3, 'amount' => '14.750'], $settled->toArray()['totals']['SALE']);
    }

    public function testAMissingRowReadsAsZeroSoEveryTypeIsPresent(): void
    {
        $answer = Wire::obj('{"success":true,"responseCode":"00","message":"Settlement successful","data":{"settlementStatus":"SETTLED",'
            . '"batchId":"00000008","totalTransactions":1,"netAmount":"000000001000","totals":{"SALE":{"count":1,"amount":"000000001000"}}}}');

        $settled = WireReaders::toSettlement($answer, 'SET02', 3);

        self::assertInstanceOf(Settled::class, $settled);
        self::assertSame(['SALE', 'REFUND', 'VOID', 'PRE_AUTH', 'CAPTURE'], array_keys($settled->totals));
        self::assertEquals(new EcrSettlementTotal(0, '0.000'), $settled->totals['CAPTURE']);
        self::assertSame('SET02', $settled->merchantReference);
    }

    public function testAnEmptyBatchIsNothingToSettleNotAFailure(): void
    {
        $answer = Wire::obj('{"success":true,"responseCode":"00","message":"There are no transactions to settle","data":{"settlementStatus":"NO_TRANSACTIONS"}}');

        $nothing = WireReaders::toSettlement($answer, 'SET03', 3);

        self::assertInstanceOf(NothingToSettle::class, $nothing);
        self::assertSame('There are no transactions to settle', $nothing->message);
        self::assertSame('nothing_to_settle', $nothing->outcome());
    }

    public function testAMismatchLeavesTheBatchOpenAndSaysHowManyWentUp(): void
    {
        $answer = Wire::obj('{"success":false,"responseCode":"91","message":"Counts differ","data":{"settlementStatus":"MISMATCH","uploadedTransactions":9}}');

        $open = WireReaders::toSettlement($answer, 'SET04', 3);

        self::assertInstanceOf(NotSettled::class, $open);
        self::assertSame('MISMATCH', $open->status);
        self::assertSame('91', $open->responseCode);
        self::assertSame(9, $open->uploadedTransactions);
        self::assertSame('Counts differ', $open->reason);
    }

    public function testABusyTerminalRefusesWithNoSettlementStatus(): void
    {
        $answer = Wire::obj('{"success":false,"responseCode":"96","message":"A transaction is already in progress on this terminal"}');

        $open = WireReaders::toSettlement($answer, 'SET05', 3);

        self::assertInstanceOf(NotSettled::class, $open);
        self::assertSame('96', $open->responseCode);
        self::assertSame('', $open->status);
        self::assertNull($open->uploadedTransactions);
        self::assertSame('SET05', $open->merchantReference);
    }

    public function testASuccessWithoutSettledStatusIsStillOpenAndWordlessAnswersGetDefaults(): void
    {
        $open = WireReaders::toSettlement(Wire::obj('{"success":true,"responseCode":"00","data":{"settlementStatus":"PENDING"}}'), 'SET06', 3);
        $nothing = WireReaders::toSettlement(Wire::obj('{"success":true,"data":{"settlementStatus":"NO_TRANSACTIONS"}}'), 'SET07', 3);

        self::assertInstanceOf(NotSettled::class, $open);
        self::assertSame('The batch was not settled', $open->reason);
        self::assertInstanceOf(NothingToSettle::class, $nothing);
        self::assertSame('There are no transactions to settle', $nothing->message);
    }

    public function testCountsAreReadAsIntTryParseReadsThem(): void
    {
        $read = static fn (string $count): ?int => WireReaders::toSettlement(
            Wire::obj('{"success":false,"data":{"uploadedTransactions":' . $count . '}}'),
            'R',
            3,
        )->uploadedTransactions;

        self::assertSame(7, $read('" 7 "'));
        self::assertSame(-2, $read('"-2"'));
        self::assertSame(2147483647, $read('2147483647'));
        self::assertNull($read('2147483648'));
        self::assertNull($read('"1.5"'));
        self::assertNull($read('"seven"'));
    }

    // ── Wi-Fi / USB cable ─────────────────────────────────────────────

    public function testWifiSendsASettlementMessageAndReadsTheBatch(): void
    {
        $channel = FakeChannel::always(self::SETTLED);
        $terminal = new EcrTerminal($channel, 'TW1', TestKeys::unsigned($this->stateDir(), responseTimeout: 95.0));

        $settled = $terminal->settlement('SET01');

        self::assertInstanceOf(Settled::class, $settled);
        self::assertSame('12.500', $settled->netAmount);
        $sent = Wire::obj($channel->requests[0]);
        self::assertSame('SETTLEMENT', $sent->str('messageType'));
        self::assertSame('SET01', $sent->str('merchantReference'));
        self::assertSame('TW1', $sent->str('terminalSerial'));
        foreach (['amount', 'stan', 'originalTerminalId', 'originalTransactionDate', 'originalMerchantReference'] as $absent) {
            self::assertFalse($sent->has($absent), $absent . ' is not part of a settlement');
        }
        // A close can wait on pending reversals: the full answer wait, not the probe.
        self::assertSame([95.0], $channel->timeouts);
    }

    public function testASignedSettlementIsVerifiedAndALostAnswerIsAFailedSettlementNeverRetried(): void
    {
        $signed = new FakeChannel(static function (string $request): string {
            $answer = Wire::obj(self::SETTLED)->set('nonce', Wire::nonceOf($request));
            return Wire::signed($answer, TestKeys::WIFI);
        });
        $config = TestKeys::lan($this->stateDir());
        self::assertInstanceOf(Settled::class, (new EcrTerminal($signed, 'TW1', $config))->settlement());
        self::assertNotSame('', Wire::obj($signed->requests[0])->str('secureHash'));

        $forged = new FakeChannel(static fn (): string => Wire::signed(Wire::obj(self::SETTLED), TestKeys::WIFI_OTHER));
        $rejected = (new EcrTerminal($forged, 'TW1', $config))->settlement('SET09');
        self::assertInstanceOf(SettlementFailed::class, $rejected);
        self::assertInstanceOf(Unauthenticated::class, $rejected->failure);

        $dropped = FakeChannel::always(null);
        $lost = (new EcrTerminal($dropped, 'TW1', $config))->settlement('SET10');
        self::assertInstanceOf(SettlementFailed::class, $lost);
        self::assertSame('SET10', $lost->merchantReference);
        self::assertInstanceOf(ConnectionLost::class, $lost->failure);
        self::assertCount(1, $dropped->requests, 'no automatic inquiry or resend after a lost settlement');
        self::assertSame('failed', $lost->outcome());
    }

    public function testASettlementWaitIsNotCutShortByPhpsTimeLimit(): void
    {
        $previous = (string) ini_get('max_execution_time');
        set_time_limit(30);
        try {
            $seen = null;
            $channel = new FakeChannel(static function () use (&$seen): string {
                $seen = (int) ini_get('max_execution_time');
                return self::SETTLED;
            });
            (new EcrTerminal($channel, 'TW1', TestKeys::unsigned($this->stateDir(), guardFinancialOperations: false)))->settlement();

            // connect 10 s + answer 120 s, plus the guard's margin.
            self::assertSame(160, $seen);
        } finally {
            set_time_limit((int) $previous);
        }
    }

    // ── Web Service ───────────────────────────────────────────────────

    public function testASettlementWithoutBackendIdsFailsBeforeAnythingIsSent(): void
    {
        $http = FakeHttpTransport::reply(200, '{}');
        $ws = new EcrWebServiceTerminal('TW1', new EcrConfig(stateDirectory: $this->stateDir()), null, $http);

        $failed = $ws->settlement('SET-1');

        self::assertInstanceOf(SettlementFailed::class, $failed);
        self::assertSame('SET-1', $failed->merchantReference);
        self::assertInstanceOf(Malformed::class, $failed->failure);
        self::assertSame([], $http->posts);
    }

    public function testSettlePostsTheIdentityBodyAndReadsAClosedBatch(): void
    {
        $http = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00","message":"Settlement successful","data":{"settlementStatus":"SETTLED",'
            . '"batchId":"00000007","currency":"OMR","endTime":"20261020130509","totalTransactions":1,"netAmount":"000000001000",'
            . '"totals":{"SALE":{"count":1,"amount":"000000001000"}}}}');
        $ws = new EcrWebServiceTerminal('TW1', TestKeys::webService($this->stateDir()), null, $http);

        $settled = $ws->settlement('SET01');

        self::assertInstanceOf(Settled::class, $settled);
        self::assertSame('https://test.amwalpg.com:25453/Ecr/settle', $http->posts[0]['url']);
        $body = $http->posts[0]['body'];
        self::assertStringContainsString('"merchantId":10001', $body);
        self::assertStringContainsString('"currencyCode":"512"', $body);
        self::assertStringNotContainsString('messageType', $body);
        self::assertSame(64, \strlen(Wire::obj($body)->str(WebServiceSecureHash::FIELD)));
        self::assertSame('00000007', $settled->batchId);
        self::assertSame('1.000', $settled->netAmount);
        self::assertSame('SET01', $settled->merchantReference);
    }

    public function testAHubThatNeverAnsweredASettlementIsAFailedSettlementNotNotSettled(): void
    {
        $http = new FakeHttpTransport(static fn () => throw new HttpTransportException('network down'));
        $ws = new EcrWebServiceTerminal('TW1', TestKeys::webService($this->stateDir()), null, $http);

        $failed = $ws->settlement('SET11');

        self::assertInstanceOf(SettlementFailed::class, $failed);
        self::assertInstanceOf(ConnectionLost::class, $failed->failure);
        self::assertStringContainsString('network down', $failed->failure->message);
    }

    public function testCheckReachabilityPostsToTheHubWithTheProbeBudgetAndReportsTheAnswer(): void
    {
        $up = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00","message":"OK"}');
        $reachable = (new EcrWebServiceTerminal('TW1', TestKeys::webService($this->stateDir()), null, $up))->probeReachability();

        self::assertSame('https://test.amwalpg.com:25453/Ecr/CheckReachability', $up->posts[0]['url']);
        self::assertTrue($reachable->reachable);
        self::assertSame('https://test.amwalpg.com:25453', $reachable->endpoint);
        self::assertSame('test.amwalpg.com', $reachable->host);
        self::assertSame(25453, $reachable->port);
        self::assertSame(3.0, $up->posts[0]['total'], 'a Hub that does not answer must not hold the till for the sale timeout');
        self::assertStringNotContainsString('messageType', $up->posts[0]['body']);

        $down = new FakeHttpTransport(static fn () => throw new HttpTransportException('network down'));
        $missed = (new EcrWebServiceTerminal('TW1', TestKeys::webService($this->stateDir()), null, $down))->probeReachability();
        self::assertFalse($missed->reachable);
        self::assertStringContainsString('network down', (string) $missed->error);

        $refused = FakeHttpTransport::reply(200, '{"success":false,"responseCode":"05","errorList":["Terminal offline"]}');
        $offline = (new EcrWebServiceTerminal('TW1', TestKeys::webService($this->stateDir()), null, $refused))->probeReachability();
        self::assertFalse($offline->reachable);
        self::assertSame('Terminal offline', $offline->error);

        $prod = (new EcrWebServiceTerminal('TW1', TestKeys::webService($this->stateDir(), environment: EcrEnvironment::PROD), null, $up))->probeReachability();
        self::assertSame(443, $prod->port);

        $noIds = (new EcrWebServiceTerminal('TW1', new EcrConfig(stateDirectory: $this->stateDir()), null, $up))->probeReachability();
        self::assertFalse($noIds->reachable);
        self::assertStringContainsString('merchantId and terminalId', (string) $noIds->error);
    }

    public function testSettleAndCheckReachabilityShareTheIdentityBody(): void
    {
        $expected = '{"version":1,"merchantId":13593,"terminalId":126706,"terminalSerial":"P653200101973","ecrId":"ECR01",'
            . '"merchantReference":"SET01","currencyCode":"512","transactionDateTime":"20261026120005","requestDateTime":"20261026120005"}';

        $settle = WebServiceRequests::settle(13593, 126706, 'P653200101973', 'SET01', transactionDateTime: '20261026120005')->toJson();
        $reachability = WebServiceRequests::checkReachability(13593, 126706, 'P653200101973', 'SET01', transactionDateTime: '20261026120005')->toJson();

        self::assertSame($expected, $settle);
        self::assertSame($expected, $reachability);
    }

    public function testASignedSettlePutsSecureHashValueLastAndLeavesItOutOfTheSignedText(): void
    {
        $json = WebServiceRequests::settle(13593, 126706, 'P653200101973', 'SET01', transactionDateTime: '20261026120005', secureHashKey: TestKeys::WEB_SERVICE);

        self::assertSame(64, \strlen($json->str(WebServiceSecureHash::FIELD)));
        self::assertSame(WebServiceSecureHash::FIELD, $json->keys()[\count($json->keys()) - 1]);
        self::assertStringNotContainsString('secureHashValue', WebServiceSecureHash::compose($json));
        self::assertStringNotContainsString('messageType', $json->toJson());
    }

    public function testSettleAndCheckReachabilityUseTheFixedHubPaths(): void
    {
        $sit = new WebServiceRoutes(EcrEnvironment::SIT);
        $uat = new WebServiceRoutes(EcrEnvironment::UAT);

        self::assertSame('/Ecr/settle', $sit->path(WebServiceOperation::Settle));
        self::assertSame('/Ecr/settle', $sit->pathFor(EcrTransactionType::Settlement));
        self::assertSame('https://test.amwalpg.com:25452/Ecr/settle', $sit->url(WebServiceOperation::Settle));
        self::assertSame('/Ecr/CheckReachability', $sit->path(WebServiceOperation::CheckReachability));
        self::assertSame('https://test.amwalpg.com:25453/Ecr/CheckReachability', $uat->url(WebServiceOperation::CheckReachability));
        self::assertSame('Settlement', WebServiceOperation::Settle->label());
        self::assertSame('Check reachability', WebServiceOperation::CheckReachability->label());
    }

    // ── The type and the session ──────────────────────────────────────

    public function testSettlementDeclaresNothingForTheCallerToSupplyAndIsNotAMenuChoice(): void
    {
        $type = EcrTransactionType::Settlement;

        self::assertSame('SETTLEMENT', $type->messageType());
        self::assertSame('Settlement', $type->displayName());
        self::assertFalse($type->requiresAmount());
        self::assertFalse($type->requiresOriginalStan());
        self::assertFalse($type->requiresOriginalDate());
        self::assertFalse($type->movesMoney());
        self::assertFalse($type->allowsOtherTerminal());
        self::assertNotContains($type, EcrTransactionType::menuOptions());
        self::assertCount(7, EcrTransactionType::cases());
    }

    public function testBothTransportsSettleAndOnlyWebServiceChecksTheHub(): void
    {
        $wifi = EcrSessions::open('TW1', EcrSessions::plan(new LanLink('127.0.0.1'), TestKeys::lan($this->stateDir())));
        $http = FakeHttpTransport::reply(200, '{"success":true,"responseCode":"00","message":"There are no transactions to settle","data":{"settlementStatus":"NO_TRANSACTIONS"}}');
        $web = EcrSessions::open(
            'TW1',
            EcrSessions::plan(new WebServiceLink('10001', '20001'), TestKeys::webService($this->stateDir())),
            httpTransport: $http,
        );

        self::assertTrue($wifi->supportsSettlement());
        self::assertTrue($web->supportsSettlement());
        self::assertNull($wifi->checkReachability());
        self::assertNull($web->probeReachability());

        $nothing = $web->settlement();
        self::assertInstanceOf(NothingToSettle::class, $nothing);
        self::assertMatchesRegularExpression('/^[0-9A-F]{12}$/', $nothing->merchantReference);
        self::assertStringEndsWith('/Ecr/settle', $http->posts[0]['url']);

        self::assertTrue($web->checkReachability()?->reachable);
        self::assertStringEndsWith('/Ecr/CheckReachability', $http->posts[1]['url']);
    }

    public function testTheSessionRoutesASettlementToItsTerminal(): void
    {
        $channel = FakeChannel::always(self::SETTLED);
        $plan = EcrSessions::plan(new LanLink('127.0.0.1'), TestKeys::unsigned($this->stateDir()));
        $session = new EcrOpenedSession($plan, new EcrTerminal($channel, 'TW1', $plan->config), null);

        self::assertInstanceOf(Settled::class, $session->settlement('SET01'));
        self::assertSame('SETTLEMENT', Wire::obj($channel->requests[0])->str('messageType'));
    }
}
