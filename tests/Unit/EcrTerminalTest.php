<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\Channel\EcrChannelTimeout;
use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrReachability;
use AmwalPay\Ecr\EcrTerminal;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Failure\Unauthenticated;
use AmwalPay\Ecr\Failure\Unreachable;
use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\NextStep;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Result\ReceiptFailed;
use AmwalPay\Ecr\Tests\Support\FakeChannel;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the Wi-Fi terminal puts on the wire and what it will believe coming
 * back, against a scripted channel.
 */
final class EcrTerminalTest extends TestCase
{
    use TempState;

    private function signed(mixed ...$changes): EcrConfig
    {
        return TestKeys::lan($this->stateDir(), ...['autoInquireOnFailure' => false, ...$changes]);
    }

    private function unsigned(mixed ...$changes): EcrConfig
    {
        return TestKeys::unsigned($this->stateDir(), ...$changes);
    }

    private function terminal(FakeChannel $channel, ?EcrConfig $config = null, ?EcrLogger $logger = null): EcrTerminal
    {
        return new EcrTerminal($channel, 'TW1', $config ?? $this->unsigned(), $logger);
    }

    // ── Requests ──────────────────────────────────────────────────────

    public function testASaleGoesOutAsAnUnsignedSaleMessageWhenNoKeyIsConfigured(): void
    {
        $channel = FakeChannel::always('{"responseCode":"00","approved":true}');

        $this->terminal($channel)->sale('1.234', 'ORD-1');

        self::assertCount(1, $channel->requests);
        $sent = Wire::obj($channel->requests[0]);
        self::assertSame('1', $sent->str('version'));
        self::assertSame('SALE', $sent->str('messageType'));
        self::assertSame('ORD-1', $sent->str('merchantReference'));
        self::assertSame('TW1', $sent->str('terminalSerial'));
        self::assertSame('512', $sent->str('currencyCode'));
        self::assertSame('ECR01', $sent->str('ecrId'));
        self::assertSame('000000001234', $sent->str('amount'));
        self::assertFalse($sent->has('secureHash'));
        self::assertFalse($sent->has('nonce'));
    }

    public function testAVoidNamesTheOriginalByPaddedReceiptNumberAndItsTerminal(): void
    {
        $channel = FakeChannel::always('{"responseCode":"00","approved":true}');

        $this->terminal($channel)->void('24', originalTerminalId: '30001', merchantReference: 'VOID-1');

        $sent = Wire::obj($channel->requests[0]);
        self::assertSame('VOID', $sent->str('messageType'));
        self::assertSame('000024', $sent->str('stan'));
        self::assertSame('30001', $sent->str('originalTerminalId'));
        self::assertSame('VOID-1', $sent->str('merchantReference'));
        self::assertFalse($sent->has('amount'));
    }

    public function testARefundCarriesTheAmountTheOriginalAndItsDate(): void
    {
        $channel = FakeChannel::always('{"responseCode":"00","approved":true}');

        $this->terminal($channel)->refund('0.5', '24', '20260806');

        $sent = Wire::obj($channel->requests[0]);
        self::assertSame('REFUND', $sent->str('messageType'));
        self::assertSame('000000000500', $sent->str('amount'));
        self::assertSame('000024', $sent->str('stan'));
        self::assertSame('20260806', $sent->str('originalTransactionDate'));
    }

    public function testAnInquiryAndAReceiptNameTheOriginalAndItsDate(): void
    {
        $channel = FakeChannel::always('{"success":true,"data":null}');
        $terminal = $this->terminal($channel);

        $terminal->inquire('24', '20260806');
        $terminal->receipt('24', '20260806');

        $inquiry = Wire::obj($channel->requests[0]);
        $receipt = Wire::obj($channel->requests[1]);
        self::assertSame('INQUIRY', $inquiry->str('messageType'));
        self::assertSame('RECEIPT', $receipt->str('messageType'));
        foreach ([$inquiry, $receipt] as $sent) {
            self::assertSame('000024', $sent->str('stan'));
            self::assertSame('20260806', $sent->str('originalTransactionDate'));
            self::assertFalse($sent->has('amount'));
        }
    }

    public function testTheConfiguredResponseTimeoutIsHandedToTheChannel(): void
    {
        $channel = FakeChannel::always('{"responseCode":"00","approved":true}');

        $this->terminal($channel, $this->unsigned(responseTimeout: 45))->sale('1');

        self::assertSame([45.0], $channel->timeouts);
    }

    public function testACurrencyWithTwoDecimalsIsWrittenInCents(): void
    {
        $channel = FakeChannel::always('{"responseCode":"00","approved":true}');

        $this->terminal($channel, $this->unsigned(currencyCode: '840', minorUnitDigits: 2))->sale('1.23');

        $sent = Wire::obj($channel->requests[0]);
        self::assertSame('000000000123', $sent->str('amount'));
        self::assertSame('840', $sent->str('currencyCode'));
    }

    public function testNonAsciiFieldsGoOutAsRawUtf8AndStillVerify(): void
    {
        $channel = FakeChannel::always(null);
        $config = $this->signed(ecrId: 'Tïll <&> "1"');

        (new EcrTerminal($channel, 'Café ☕', $config))->sale('1', 'ORD-1');

        self::assertStringContainsString('"terminalSerial":"Café ☕"', $channel->requests[0]);
        self::assertStringNotContainsString('\\u', $channel->requests[0]);
        $parsed = Wire::obj($channel->requests[0]);
        self::assertSame('Tïll <&> "1"', $parsed->str('ecrId'));
        self::assertTrue(SecureHash::verify($parsed, TestKeys::WIFI));
    }

    // ── Refused before anything is sent ───────────────────────────────

    public function testBadArgumentsAreRefusedBeforeAnythingIsSent(): void
    {
        $channel = FakeChannel::always('{}');
        $terminal = $this->terminal($channel);
        $calls = [
            fn () => $terminal->sale('1', 'has space'),
            fn () => $terminal->sale('1', str_repeat('X', 33)),
            fn () => $terminal->sale('abc'),
            fn () => $terminal->void(''),
            fn () => $terminal->void('  '),
            fn () => $terminal->inquire(' ', '20260806'),
            fn () => $terminal->inquireByReference(' '),
            fn () => $terminal->receipt('', '20260806'),
            fn () => $terminal->run(EcrTransactionType::Inquiry),
            fn () => $terminal->run(EcrTransactionType::Receipt),
            fn () => $terminal->run(EcrTransactionType::CloseReceipt),
            fn () => $terminal->run(EcrTransactionType::Sale),
            fn () => $terminal->run(EcrTransactionType::Refund, amount: '1'),
            fn () => (new EcrTerminal($channel, "TW\xC3\x28", $this->unsigned()))->sale('1'),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                self::fail('call ' . $i . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        self::assertSame([], $channel->requests);
        self::assertSame([], $terminal->guard()->unresolved($terminal->terminalKey()), 'nothing pending after refusals');
    }

    // ── Failures the channel can report ───────────────────────────────

    public function testATimeoutIsATimeoutNotADecline(): void
    {
        $channel = new FakeChannel(static fn () => throw new EcrChannelTimeout('no answer'));

        $failed = $this->terminal($channel, $this->unsigned(responseTimeout: 90))->sale('1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Timeout::class, $failed->failure);
        self::assertStringContainsString('1m 30s', $failed->failure->message);
        self::assertStringContainsString('inquire before retrying', $failed->failure->message);
        self::assertTrue($failed->failure->outcomeUnknown());
        self::assertSame(NextStep::InquireByMerchantReference, $failed->nextStep());
    }

    /** @return iterable<array{int, string}> */
    public static function durations(): iterable
    {
        yield [0, '0s'];
        yield [30, '30s'];
        yield [59, '59s'];
        yield [60, '1m'];
        yield [90, '1m 30s'];
        yield [120, '2m'];
        yield [160, '2m 40s'];
    }

    #[DataProvider('durations')]
    public function testDurationsAreFormattedForOperators(int $seconds, string $expected): void
    {
        self::assertSame($expected, EcrTerminal::formatDuration($seconds));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'html' => ['<html>gateway error</html>'];
        yield 'null' => ['null'];
        yield 'array' => ['[]'];
        yield 'string' => ['"approved"'];
        yield 'success twice' => ['{"success":false,"success":true,"responseCode":"00"}'];
        yield 'data twice' => ['{"success":true,"data":{"amount":"1"},"data":null}'];
        yield 'amount twice' => ['{"success":true,"data":{"amount":"1","amount":"999"}}'];
    }

    #[DataProvider('unreadable')]
    public function testAnUnreadableAnswerIsMalformedAndOutcomeUnknownNeverADecline(string $body): void
    {
        $failed = $this->terminal(FakeChannel::always($body))->sale('1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Malformed::class, $failed->failure);
        self::assertTrue($failed->failure->outcomeUnknown());
    }

    public function testAnEmptyMessageIsMalformedAndAClosedConnectionIsLost(): void
    {
        $empty = $this->terminal(new FakeChannel(static fn () => throw new EcrIOException('Terminal returned an empty message')))->sale('1');
        $lost = $this->terminal(FakeChannel::always(null), TestKeys::unsigned($this->stateDir() . '/lost'))->sale('1');

        self::assertInstanceOf(Failed::class, $empty);
        self::assertInstanceOf(Malformed::class, $empty->failure);
        self::assertInstanceOf(Failed::class, $lost);
        self::assertInstanceOf(ConnectionLost::class, $lost->failure);
    }

    public function testAConnectFailureIsUnreachableAndNotAnUnknownOutcome(): void
    {
        $channel = new FakeChannel(static fn () => throw new EcrIOException('Connection timed out after 3000 ms'));

        $failed = $this->terminal($channel, TestKeys::unsigned($this->stateDir(), autoInquireOnFailure: true))->sale('1.234', 'ORD-1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Unreachable::class, $failed->failure);
        self::assertStringContainsString('Connection timed out after 3000 ms', $failed->failure->message);
        self::assertStringContainsString('(fake:9100)', $failed->failure->message);
        self::assertFalse($failed->failure->outcomeUnknown());
        self::assertNull($failed->recovered, 'nothing was sent, so nothing is asked');
        self::assertCount(1, $channel->requests);
    }

    public function testInquiriesAndReceiptsReportTheSameFailures(): void
    {
        $terminal = $this->terminal(FakeChannel::always(null));

        $inquiry = $terminal->inquire('24', '20260806');
        $byReference = $terminal->inquireByReference('ORD-1');
        $receipt = $terminal->receipt('24', '20260806');

        self::assertInstanceOf(InquiryFailed::class, $inquiry);
        self::assertInstanceOf(ConnectionLost::class, $inquiry->failure);
        self::assertInstanceOf(InquiryFailed::class, $byReference);
        self::assertInstanceOf(ReceiptFailed::class, $receipt);
    }

    public function testWhatTheWindowsSdkSendsAsGivenIsSent(): void
    {
        foreach ([
            fn (EcrTerminal $t) => $t->sale('0'),
            fn (EcrTerminal $t) => $t->sale('-1.000'),
            fn (EcrTerminal $t) => $t->void('12a'),
            fn (EcrTerminal $t) => $t->refund('1', '24', ''),
            fn (EcrTerminal $t) => $t->refund('1', '24', '2026-08-06'),
        ] as $i => $call) {
            $channel = FakeChannel::always('{"success":true,"responseCode":"00"}');
            $call($this->terminal($channel, TestKeys::unsigned($this->stateDir() . '/' . $i)));
            self::assertCount(1, $channel->requests, 'call ' . $i . ' was not sent');
        }
    }

    public function testAnAnswerIsReadWhateverVersionItDeclares(): void
    {
        $approved = $this->terminal(FakeChannel::always('{"version":2,"success":true,"responseCode":"00","message":"Approved"}'))->sale('1');

        self::assertInstanceOf(Approved::class, $approved);
    }

    // ── Reachability ─────────────────────────────────────────────────

    public function testReachabilityReportsWhyNotAndWhere(): void
    {
        $channel = FakeChannel::always(null);
        $channel->probeError = 'Connection refused';
        $terminal = $this->terminal($channel);

        $reachability = $terminal->probeReachability();

        self::assertFalse($reachability->reachable);
        self::assertSame('Connection refused', $reachability->error);
        self::assertSame('fake', $reachability->host);
        self::assertSame(9100, $reachability->port);
        self::assertSame('fake:9100', $reachability->endpoint);
        self::assertFalse($terminal->isReachable());
        $channel->probeError = null;
        self::assertTrue($terminal->isReachable());
        self::assertSame('USB cable', (new EcrReachability(true, 'USB cable', 0))->endpoint);
    }

    // ── Trust: signing the request, checking the answer ───────────────

    public function testASignedAnswerToThisRequestIsBelieved(): void
    {
        $channel = new FakeChannel(static fn (string $request) => Wire::signed(Wire::approval(Wire::nonceOf($request)), TestKeys::WIFI));

        $approved = $this->terminal($channel, $this->signed())->sale('1.234', 'ORD-1');

        self::assertInstanceOf(Approved::class, $approved);
        self::assertSame('1.234', $approved->amount);
        self::assertSame('622908275947', $approved->rrn);
        $sent = Wire::obj($channel->requests[0]);
        self::assertSame(32, \strlen($sent->str('nonce')));
        self::assertTrue(SecureHash::verify($sent, TestKeys::WIFI));
    }

    /** @return iterable<string, array{\Closure(string): string, string}> */
    public static function untrustworthyAnswers(): iterable
    {
        yield 'unsigned' => [static fn (string $r) => Wire::approval(Wire::nonceOf($r))->toJson(), "not signed with this terminal's key"];
        yield 'another key' => [static fn (string $r) => Wire::signed(Wire::approval(Wire::nonceOf($r)), TestKeys::WIFI_OTHER), 'not signed'];
        yield 'altered after signing' => [static fn (string $r) => str_replace('"success":true', '"success":false', Wire::signed(Wire::approval(Wire::nonceOf($r)), TestKeys::WIFI)), 'not signed'];
        yield 'replayed from an earlier request' => [static fn () => Wire::signed(Wire::approval('00000000000000000000000000000001'), TestKeys::WIFI), 'belongs to a different request'];
        yield 'no nonce' => [static fn () => Wire::signed(Wire::approval('')->remove('nonce'), TestKeys::WIFI), 'belongs to a different request'];
    }

    #[DataProvider('untrustworthyAnswers')]
    public function testAnAnswerThatCannotBeShownToComeFromTheTerminalIsRejectedAsUnknown(\Closure $answer, string $why): void
    {
        $failed = $this->terminal(new FakeChannel($answer), $this->signed())->sale('1.234', 'ORD-1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Unauthenticated::class, $failed->failure);
        self::assertStringContainsString($why, $failed->failure->message);
        self::assertTrue($failed->failure->outcomeUnknown(), 'the terminal may well have taken the payment');
    }

    public function testAnUnsignedTillAcceptsAnUnsignedAnswerAndIgnoresAnyNonce(): void
    {
        self::assertInstanceOf(Approved::class, $this->terminal(FakeChannel::always(Wire::approval('SOMETHING-ELSE')->toJson()))->sale('1.234', 'ORD-1'));
    }

    public function testInquiriesAndReceiptsAreAuthenticatedToo(): void
    {
        $terminal = $this->terminal(FakeChannel::always(Wire::foundEnvelope('x')->toJson()), $this->signed());

        $inquiry = $terminal->inquire('24', '20260806');
        $receipt = $terminal->receipt('24', '20260806');

        self::assertInstanceOf(InquiryFailed::class, $inquiry);
        self::assertInstanceOf(Unauthenticated::class, $inquiry->failure);
        self::assertInstanceOf(ReceiptFailed::class, $receipt);
        self::assertInstanceOf(Unauthenticated::class, $receipt->failure);
        $believed = $this->terminal(new FakeChannel(static fn (string $r) => Wire::signed(Wire::foundEnvelope(Wire::nonceOf($r)), TestKeys::WIFI)), $this->signed());
        self::assertInstanceOf(Found::class, $believed->inquire('24', '20260806'));
    }

    // ── Recovery: the automatic inquiry after a lost answer ───────────

    private function recovering(FakeChannel $channel, bool $autoInquire = true): EcrTerminal
    {
        return $this->terminal($channel, TestKeys::unsigned($this->stateDir(), autoInquireOnFailure: $autoInquire));
    }

    public function testASaleWhoseAnswerNeverArrivesIsFollowedByOneInquiryByItsReference(): void
    {
        $channel = new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : Wire::FOUND);

        $failed = $this->recovering($channel)->sale('1.234', 'ORD-88231');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(ConnectionLost::class, $failed->failure);
        self::assertTrue($failed->settled(), 'but the outcome is no longer unknown');
        self::assertInstanceOf(Found::class, $failed->recovered);
        self::assertSame('Approved', $failed->recovered->transaction->status);
        self::assertCount(2, $channel->requests);
        self::assertStringContainsString('"messageType":"SALE"', $channel->requests[0]);
        self::assertStringContainsString('"messageType":"INQUIRY"', $channel->requests[1]);
        self::assertStringContainsString('"originalMerchantReference":"ORD-88231"', $channel->requests[1]);
        self::assertStringNotContainsString('"amount"', $channel->requests[1], 'the follow-up is an inquiry, never a second sale');
    }

    public function testATimeoutAndAnUnauthenticatedAnswerAreFollowedUpToo(): void
    {
        $timeout = new FakeChannel(static fn (string $r, int $n) => $n === 0 ? throw new EcrChannelTimeout('no answer') : Wire::FOUND);
        $failed = $this->recovering($timeout)->sale('1.234', 'ORD-1');
        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Timeout::class, $failed->failure);
        self::assertTrue($failed->settled());

        $config = TestKeys::lan($this->stateDir());
        $unauthenticated = new FakeChannel(static fn (string $r, int $n) => $n === 0
            ? Wire::approval(Wire::nonceOf($r))->toJson()
            : Wire::signed(Wire::foundEnvelope(Wire::nonceOf($r)), TestKeys::WIFI));
        $rejected = (new EcrTerminal($unauthenticated, 'TW1', $config))->sale('1.234', 'ORD-2');
        self::assertInstanceOf(Failed::class, $rejected);
        self::assertInstanceOf(Unauthenticated::class, $rejected->failure);
        self::assertTrue($rejected->settled());
    }

    public function testAVoidAndARefundGetTheSameFollowUp(): void
    {
        $voidChannel = new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : Wire::FOUND);
        $refundChannel = new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : Wire::FOUND);

        $voided = $this->recovering($voidChannel)->void('24', merchantReference: 'VOID-1');
        $refunded = (new EcrTerminal($refundChannel, 'TW2', TestKeys::unsigned($this->stateDir(), autoInquireOnFailure: true)))->refund('0.5', '24', '20260806', merchantReference: 'REF-1');

        self::assertInstanceOf(Failed::class, $voided);
        self::assertTrue($voided->settled());
        self::assertInstanceOf(Failed::class, $refunded);
        self::assertTrue($refunded->settled());
        self::assertStringContainsString('"originalMerchantReference":"VOID-1"', $voidChannel->requests[1]);
        self::assertStringContainsString('"originalMerchantReference":"REF-1"', $refundChannel->requests[1]);
    }

    public function testNoRecordOrAFailedFollowUpLeavesTheOutcomeUnknown(): void
    {
        $notFound = $this->recovering(new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : '{"responseCode":"25","responseMessage":"Not found","approved":false}'))->sale('1.234', 'ORD-1');
        self::assertInstanceOf(Failed::class, $notFound);
        self::assertInstanceOf(NotFound::class, $notFound->recovered);
        self::assertFalse($notFound->settled(), 'still unknown, and still not a decline');

        $gone = FakeChannel::always(null);
        $stillUnknown = (new EcrTerminal($gone, 'TW1', TestKeys::unsigned($this->stateDir() . '/b', autoInquireOnFailure: true)))->sale('1.234', 'ORD-2');
        self::assertInstanceOf(Failed::class, $stillUnknown);
        self::assertInstanceOf(InquiryFailed::class, $stillUnknown->recovered);
        self::assertFalse($stillUnknown->settled());
        self::assertCount(2, $gone->requests);

        $escaped = (new EcrTerminal(new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : throw new \RuntimeException('boom')), 'TW1', TestKeys::unsigned($this->stateDir() . '/c', autoInquireOnFailure: true)))->sale('1.234', 'ORD-3');
        self::assertInstanceOf(Failed::class, $escaped);
        self::assertNull($escaped->recovered, 'an unexpected error in the follow-up does not escape');
    }

    public function testFollowUpCanBeTurnedOffAndApprovalsOrDeclinesAreNeverFollowedUp(): void
    {
        $off = new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : Wire::FOUND);
        $failed = $this->recovering($off, false)->sale('1.234', 'ORD-1');
        self::assertInstanceOf(Failed::class, $failed);
        self::assertNull($failed->recovered);
        self::assertCount(1, $off->requests);
        self::assertSame(NextStep::InquireByMerchantReference, $failed->nextStep(), 'always inquire, never retry');

        $approved = FakeChannel::always('{"responseCode":"00","responseMessage":"Approved","approved":true,"amount":"000000001234"}');
        self::assertInstanceOf(Approved::class, (new EcrTerminal($approved, 'A', TestKeys::unsigned($this->stateDir() . '/a', autoInquireOnFailure: true)))->sale('1.234'));
        self::assertCount(1, $approved->requests);

        $declined = FakeChannel::always('{"responseCode":"51","responseMessage":"DECLINED","approved":false}');
        self::assertInstanceOf(Declined::class, (new EcrTerminal($declined, 'D', TestKeys::unsigned($this->stateDir() . '/d', autoInquireOnFailure: true)))->sale('1.234'));
        self::assertCount(1, $declined->requests);
    }

    // ── Diagnostics ───────────────────────────────────────────────────

    public function testTheSecretNeverReachesTheLog(): void
    {
        $lines = [];
        $channel = new FakeChannel(static fn (string $r) => Wire::signed(Wire::approval(Wire::nonceOf($r)), TestKeys::WIFI));

        $this->terminal($channel, $this->signed(), EcrLogger::from(static function (string $l) use (&$lines): void {
            $lines[] = $l;
        }))->sale('1.234', 'ORD-1');

        $log = implode("\n", $lines);
        self::assertNotSame('', $log);
        self::assertStringNotContainsStringIgnoringCase(TestKeys::WIFI, $log);
        self::assertStringContainsString('Sending SALE ORD-1 to fake:9100', $log);
        self::assertStringContainsString('response signature valid', $log);
        self::assertStringContainsString('signing key (Wi-Fi/CABLE secure hash key): configured', $log);
    }

    public function testARejectedAnswerIsLoggedAsRejected(): void
    {
        $lines = [];
        $this->terminal(FakeChannel::always(Wire::approval('x')->toJson()), $this->signed(), EcrLogger::from(static function (string $l) use (&$lines): void {
            $lines[] = $l;
        }))->sale('1', 'ORD-1');

        self::assertNotEmpty(array_filter($lines, static fn (string $l) => str_starts_with($l, 'Rejected the answer:')));
    }

    public function testAnUnmaskedCardNumberInAnAnswerIsMaskedInTheLog(): void
    {
        $lines = [];
        $answer = '{"success":true,"responseCode":"00","data":{"cardNumber":"4111111111111111","pin":"1234","cvv":"123"}}';
        $this->terminal(FakeChannel::always($answer), null, EcrLogger::from(static function (string $l) use (&$lines): void {
            $lines[] = $l;
        }))->sale('1', 'ORD-1');

        $log = implode("\n", $lines);
        self::assertStringNotContainsString('4111111111111111', $log);
        self::assertStringContainsString('411111******1111', $log);
        self::assertStringNotContainsString('"pin":"1234"', $log);
        self::assertStringNotContainsString('"cvv":"123"', $log);
    }
}
