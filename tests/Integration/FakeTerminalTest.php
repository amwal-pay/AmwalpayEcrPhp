<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Integration;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrOpenedSession;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Failure\Unauthenticated;
use AmwalPay\Ecr\Failure\Unresolved;
use AmwalPay\Ecr\Link\LanLink;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\CloseReceiptRefused;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Result\ReceiptClosed;
use AmwalPay\Ecr\Result\ReceiptReady;
use AmwalPay\Ecr\Safety\JournalEntry;
use AmwalPay\Ecr\Tests\Support\BackgroundProcess;
use AmwalPay\Ecr\Tests\Support\TempState;
use PHPUnit\Framework\TestCase;

/**
 * The whole Wi-Fi path, from EcrSessions::open to a socket, against the signed
 * fake terminal in tools/: a separate program that verifies the till's
 * signature, refuses replayed nonces and signs its answers with its own code.
 */
final class FakeTerminalTest extends TestCase
{
    use TempState;

    // A placeholder, as in the Windows example's fake terminal instructions.
    private const KEY = '0123456789ABCDEF0123456789ABCDEF';

    private ?BackgroundProcess $terminal = null;

    protected function tearDown(): void
    {
        $this->terminal?->stop();
    }

    /** @param list<string> $flags */
    private function session(array $flags = [], mixed ...$config): EcrOpenedSession
    {
        $this->terminal = new BackgroundProcess(array_merge([__DIR__ . '/../../tools/fake-pos-server.php', '--key', self::KEY, '--port', '0'], $flags));
        $plan = EcrSessions::plan(
            new LanLink('127.0.0.1', $this->terminal->port),
            new EcrConfig(...array_merge(['secureHashKey' => self::KEY, 'stateDirectory' => $this->stateDir(), 'responseTimeout' => 5.0], $config)),
        );
        self::assertTrue($plan->isReady(), implode("\n", $plan->issues()));
        return EcrSessions::open('SN-WIFI-0001', $plan);
    }

    public function testApprovedSaleThenInquiryReceiptVoidRefundAndCloseReceipt(): void
    {
        $session = $this->session();

        self::assertTrue($session->probeReachability()?->reachable);
        $sale = $session->sale('1.234', 'ORDER-1001-1');
        self::assertInstanceOf(Approved::class, $sale, $this->terminal?->output() ?? '');
        self::assertSame('1.234', $sale->amount);
        self::assertSame('483312******1234', $sale->maskedPan);
        self::assertSame('ORDER-1001-1', $sale->merchantReference);

        $found = $session->inquireByReference('ORDER-1001-1');
        self::assertInstanceOf(Found::class, $found);
        $stan = $found->transaction->stan;
        self::assertSame('000024', $stan);
        self::assertTrue($found->transaction->canVoid);

        $byReceipt = $session->inquire($stan, $found->transaction->day());
        self::assertInstanceOf(Found::class, $byReceipt);

        $receipt = $session->receipt($stan, $found->transaction->day());
        self::assertInstanceOf(ReceiptReady::class, $receipt);
        self::assertStringContainsString('stan=000024', $receipt->url);

        self::assertInstanceOf(ReceiptClosed::class, $session->closeReceipt());
        self::assertInstanceOf(Approved::class, $session->void($stan, merchantReference: 'ORDER-1001-V1'));
        self::assertInstanceOf(Approved::class, $session->refund('0.500', $stan, $found->transaction->day(), merchantReference: 'ORDER-1001-R1'));
        self::assertSame([], $session->pendingOutcomes());
    }

    public function testADeclineIsADeclineAndTheReferenceIsSpent(): void
    {
        $session = $this->session(['--decline', '51']);

        $declined = $session->sale('1.000', 'ORD-D1');

        self::assertInstanceOf(Declined::class, $declined);
        self::assertSame('51', $declined->responseCode);
        self::assertSame('Declined by the issuer (51)', $declined->reason);
        self::assertSame(JournalEntry::DECLINED, $session->guard()->entry($session->terminalKey(), 'ORD-D1')?->state);
    }

    public function testAPartialApprovalReportsWhatWasTakenAndWhatWasAsked(): void
    {
        $approved = $this->session(['--partial'])->sale('961.100', 'ORD-P1');

        self::assertInstanceOf(Approved::class, $approved);
        self::assertTrue($approved->partialApproval);
        self::assertSame('480.550', $approved->amount);
        self::assertSame('961.100', $approved->requestedAmount);
    }

    public function testABusyTerminalRefusesAndNothingMoved(): void
    {
        $result = $this->session(['--busy'])->sale('1.000', 'ORD-B1');

        self::assertInstanceOf(Declined::class, $result);
        self::assertSame('BUSY', $result->responseCode);
        self::assertSame('Terminal busy', $result->reason);
    }

    public function testALostAnswerIsRecoveredByTheAutomaticInquiry(): void
    {
        $session = $this->session(['--drop-sale']);

        $failed = $session->sale('2.500', 'ORD-L1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(ConnectionLost::class, $failed->failure);
        self::assertTrue($failed->settled());
        self::assertInstanceOf(Found::class, $failed->recovered);
        self::assertSame('2.500', $failed->recovered->transaction->amount);
        self::assertSame([], $session->pendingOutcomes(), 'settled');
    }

    public function testALostAnswerTheTerminalCannotExplainBlocksTheTerminalUntilReconciled(): void
    {
        $session = $this->session(['--drop-sale', '--drop-first-inquiry']);

        $failed = $session->sale('2.500', 'ORD-L2');
        self::assertInstanceOf(Failed::class, $failed);
        self::assertTrue($failed->failure->outcomeUnknown());
        self::assertInstanceOf(InquiryFailed::class, $failed->recovered);
        self::assertFalse($failed->settled());

        $blocked = $session->sale('1.000', 'ORD-L3');
        self::assertInstanceOf(Failed::class, $blocked);
        self::assertInstanceOf(Unresolved::class, $blocked->failure);

        $reconciled = $session->reconcile();
        self::assertInstanceOf(Found::class, $reconciled['ORD-L2'] ?? null);
        self::assertSame([], $session->pendingOutcomes());
    }

    public function testATerminalThatNeverAnswersIsATimeoutThenRecovered(): void
    {
        $session = $this->session(['--stall-sale', '--stall-seconds', '2'], responseTimeout: 1.5);

        $failed = $session->sale('1.234', 'ORD-T1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Timeout::class, $failed->failure);
        self::assertStringContainsString('did not answer within 2s', $failed->failure->message);
        self::assertTrue($failed->settled(), 'the follow-up found it once the terminal was free');
    }

    public function testAResetAfterTheSaleIsALostConnection(): void
    {
        if (!\function_exists('socket_import_stream')) {
            self::markTestSkipped('ext-sockets is needed to send a TCP reset');
        }
        $failed = $this->session(['--reset-sale'])->sale('1.234', 'ORD-R1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(ConnectionLost::class, $failed->failure);
        self::assertTrue($failed->settled());
    }

    public function testAnUnreadableAnswerIsMalformedAndInquiredAbout(): void
    {
        $failed = $this->session(['--garbage-sale'])->sale('1.234', 'ORD-G1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Malformed::class, $failed->failure);
        self::assertTrue($failed->settled());
    }

    public function testAnAnswerSignedWithAnotherKeyIsNotBelieved(): void
    {
        $failed = $this->session(['--wrong-key-sale'])->sale('1.234', 'ORD-K1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Unauthenticated::class, $failed->failure);
        self::assertTrue($failed->settled(), 'the properly signed follow-up found it');
    }

    public function testATillWithTheWrongKeyIsRefusedByTheTerminal(): void
    {
        $this->terminal = new BackgroundProcess([__DIR__ . '/../../tools/fake-pos-server.php', '--key', self::KEY, '--port', '0']);
        $plan = EcrSessions::plan(new LanLink('127.0.0.1', $this->terminal->port), new EcrConfig(secureHashKey: str_repeat('d', 32), stateDirectory: $this->stateDir()));

        $result = EcrSessions::open('P1', $plan)->sale('1.000', 'ORD-W1');

        // The terminal answers, signed with its key, which this till cannot verify.
        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(Unauthenticated::class, $result->failure);
    }

    public function testAnAnswerInANewerFormatIsRefusedExplicitly(): void
    {
        $failed = $this->session(['--answer-version', '2'], autoInquireOnFailure: false)->sale('1.234', 'ORD-V1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertInstanceOf(Malformed::class, $failed->failure);
        self::assertStringContainsString('version 2', $failed->failure->message);
    }

    public function testOldFirmwareRefusesCloseReceiptAndNotFoundIsNotFound(): void
    {
        $session = $this->session(['--no-close-receipt', '--not-found']);

        self::assertInstanceOf(CloseReceiptRefused::class, $session->closeReceipt());
        self::assertInstanceOf(NotFound::class, $session->inquireByReference('NEVER-SENT'));
    }
}
