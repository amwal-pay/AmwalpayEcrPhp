<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrTerminal;
use AmwalPay\Ecr\EcrWebServiceTerminal;
use AmwalPay\Ecr\Exception\DuplicateMerchantReferenceException;
use AmwalPay\Ecr\Failure\Busy;
use AmwalPay\Ecr\Failure\Unresolved;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Safety\FileTerminalLock;
use AmwalPay\Ecr\Safety\FileTransactionJournal;
use AmwalPay\Ecr\Safety\FinancialGuard;
use AmwalPay\Ecr\Safety\JournalEntry;
use AmwalPay\Ecr\Tests\Support\FakeChannel;
use AmwalPay\Ecr\Tests\Support\FakeHttpTransport;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use PHPUnit\Framework\TestCase;

/**
 * The payment-safety rules the PHP SDK enforces across requests and processes:
 * one financial transaction per terminal, a reference used once, and no new
 * money movement while an outcome is unknown.
 */
final class FinancialGuardTest extends TestCase
{
    use TempState;

    private const APPROVED = '{"success":true,"responseCode":"00","message":"Approved","data":{"amount":"1.000"}}';
    private const DECLINED = '{"success":false,"responseCode":"51","message":"Declined"}';
    private const NOT_FOUND = '{"success":false,"responseCode":"25","message":"Transaction not found","data":null}';

    private function terminal(FakeChannel $channel, bool $autoInquire = false): EcrTerminal
    {
        return new EcrTerminal($channel, 'TW1', TestKeys::unsigned($this->stateDir(), autoInquireOnFailure: $autoInquire));
    }

    public function testAnApprovedOrDeclinedReferenceCannotBeSentAgain(): void
    {
        $channel = new FakeChannel(static fn (string $r, int $n) => $n === 0 ? self::APPROVED : self::DECLINED);
        $terminal = $this->terminal($channel);

        self::assertInstanceOf(Approved::class, $terminal->sale('1', 'ORD-1'));
        try {
            $terminal->sale('1', 'ORD-1');
            self::fail('a used reference was sent again');
        } catch (DuplicateMerchantReferenceException $e) {
            self::assertSame(JournalEntry::APPROVED, $e->previous->state);
            self::assertStringContainsString('A new attempt needs a new reference', $e->getMessage());
        }
        self::assertInstanceOf(Declined::class, $terminal->sale('1', 'ORD-2'));
        $this->expectException(DuplicateMerchantReferenceException::class);
        $terminal->void('24', merchantReference: 'ORD-2');
    }

    public function testAnUnknownOutcomeBlocksNewMoneyMovementUntilAnInquirySettlesIt(): void
    {
        // The sale's answer is lost; the till asks later and learns it was approved.
        $channel = new FakeChannel(static function (string $request, int $n): ?string {
            return match ($n) {
                0 => null,
                1 => Wire::FOUND,
                default => self::APPROVED,
            };
        });
        $terminal = $this->terminal($channel);
        $key = $terminal->terminalKey();

        $lost = $terminal->sale('1.234', 'ORD-1');
        self::assertInstanceOf(Failed::class, $lost);
        self::assertSame(['ORD-1'], array_map(static fn (JournalEntry $e) => $e->reference, $terminal->guard()->unresolved($key)));

        $blocked = $terminal->sale('5', 'ORD-2');
        self::assertInstanceOf(Failed::class, $blocked);
        self::assertInstanceOf(Unresolved::class, $blocked->failure);
        self::assertFalse($blocked->failure->outcomeUnknown(), 'nothing was sent');
        self::assertStringContainsString('ORD-1', $blocked->failure->message);
        self::assertCount(1, $channel->requests, 'the blocked sale never left');

        try {
            $terminal->sale('1.234', 'ORD-1');
            self::fail('the unknown sale was resent');
        } catch (DuplicateMerchantReferenceException $e) {
            self::assertStringContainsString('inquire by this reference, never resend', $e->getMessage());
        }

        self::assertInstanceOf(Found::class, $terminal->inquireByReference('ORD-1'));
        self::assertSame([], $terminal->guard()->unresolved($key));
        self::assertSame(JournalEntry::APPROVED, $terminal->guard()->entry($key, 'ORD-1')?->state);
        self::assertInstanceOf(Approved::class, $terminal->sale('5', 'ORD-2'));
    }

    public function testNotFoundDoesNotSettleAnUnknownOutcomeButAnOperatorCan(): void
    {
        $channel = new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : ($n === 1 ? self::NOT_FOUND : self::APPROVED));
        $terminal = $this->terminal($channel);
        $key = $terminal->terminalKey();

        $terminal->sale('1', 'ORD-1');
        self::assertInstanceOf(NotFound::class, $terminal->inquireByReference('ORD-1'));
        self::assertCount(1, $terminal->guard()->unresolved($key));
        self::assertStringContainsString('does not prove nothing was charged', $terminal->guard()->entry($key, 'ORD-1')?->note ?? '');

        $resolved = $terminal->guard()->markResolved($key, 'ORD-1', 'Checked the terminal batch report: not charged');
        self::assertSame(JournalEntry::RESOLVED, $resolved->state);
        self::assertInstanceOf(Approved::class, $terminal->sale('1', 'ORD-2'));
    }

    public function testAutoInquiryThatFindsTheSaleSettlesTheJournalAtOnce(): void
    {
        $terminal = $this->terminal(new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : Wire::FOUND), true);

        $failed = $terminal->sale('1.234', 'ORD-1');

        self::assertInstanceOf(Failed::class, $failed);
        self::assertTrue($failed->settled());
        self::assertSame([], $terminal->guard()->unresolved($terminal->terminalKey()));
        $entry = $terminal->guard()->entry($terminal->terminalKey(), 'ORD-1');
        self::assertSame(JournalEntry::APPROVED, $entry?->state);
        self::assertStringContainsString('connection_lost', $entry->failure);
    }

    public function testAFoundStatusOtherThanApprovedOrDeclinedStaysUnresolved(): void
    {
        $voided = '{"success":true,"data":{"stan":"1","status":"Voided","amount":"1.000"}}';
        $terminal = $this->terminal(new FakeChannel(static fn (string $r, int $n) => $n === 0 ? null : $voided), true);

        $terminal->sale('1', 'ORD-1');

        self::assertCount(1, $terminal->guard()->unresolved($terminal->terminalKey()));
    }

    public function testNothingSentLeavesTheReferenceFreeAndNothingPending(): void
    {
        $channel = new FakeChannel(static fn (string $r, int $n) => $n === 0 ? throw new \AmwalPay\Ecr\Channel\EcrIOException('Connection refused') : self::APPROVED);
        $terminal = $this->terminal($channel);

        $unreachable = $terminal->sale('1', 'ORD-1');

        self::assertInstanceOf(Failed::class, $unreachable);
        self::assertFalse($unreachable->failure->outcomeUnknown());
        self::assertSame([], $terminal->guard()->unresolved($terminal->terminalKey()));
        self::assertInstanceOf(Approved::class, $terminal->sale('1', 'ORD-1'), 'the reference was never used');
    }

    public function testASecondFinancialTransactionWhileOneRunsIsBusyAndNotSent(): void
    {
        $second = null;
        $inner = null;
        $channel = new FakeChannel(function (string $request, int $n) use (&$second, &$inner): string {
            if ($n === 0) {
                // While this sale is out, another request (another PHP process,
                // in production) tries the same terminal.
                $second = $inner?->sale('2', 'ORD-2');
                $inquiry = $inner?->inquire('24', '20260806');
                self::assertNotNull($inquiry, 'inquiries are never blocked');
            }
            return $n === 0 ? self::APPROVED : self::NOT_FOUND;
        });
        $terminal = $this->terminal($channel);
        $inner = new EcrTerminal($channel, 'TW1', TestKeys::unsigned($this->stateDir(), autoInquireOnFailure: false));

        self::assertInstanceOf(Approved::class, $terminal->sale('1', 'ORD-1'));
        self::assertInstanceOf(Failed::class, $second);
        self::assertInstanceOf(Busy::class, $second->failure);
        self::assertFalse($second->failure->outcomeUnknown());
        self::assertNotContains('ORD-2', array_map(static fn (string $r) => Wire::obj($r)->str('merchantReference'), $channel->requests));
        self::assertNull($terminal->guard()->entry($terminal->terminalKey(), 'ORD-2'), 'a busy refusal leaves no trace');
    }

    public function testAProcessKilledMidWaitLeavesAPendingEntryThatBlocks(): void
    {
        $channel = new FakeChannel(static fn () => throw new \Error('PHP fatal error: maximum execution time exceeded'));
        $terminal = $this->terminal($channel);

        try {
            $terminal->sale('1', 'ORD-1');
            self::fail('expected the simulated fatal error');
        } catch (\Error) {
            self::assertTrue(true);
        }

        $open = $terminal->guard()->unresolved($terminal->terminalKey());
        self::assertCount(1, $open);
        self::assertSame(JournalEntry::PENDING, $open[0]->state);
        self::assertSame('1000', $open[0]->amountMinorUnits);
        self::assertSame('512', $open[0]->currencyCode);
    }

    public function testTheGuardIsSharedByTerminalNotByObject(): void
    {
        $lost = new EcrTerminal(FakeChannel::always(null), 'TW1', TestKeys::unsigned($this->stateDir(), autoInquireOnFailure: false));
        $lost->sale('1', 'ORD-1');
        $sameTerminalLater = new EcrTerminal(FakeChannel::always(self::APPROVED), 'TW1', TestKeys::unsigned($this->stateDir(), autoInquireOnFailure: false));

        $result = $sameTerminalLater->sale('1', 'ORD-2');

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(Unresolved::class, $result->failure);
    }

    public function testWebServiceIsGuardedTheSameWay(): void
    {
        $http = new FakeHttpTransport(static fn (string $url, string $body, int $n) => $n === 0
            ? throw new \AmwalPay\Ecr\WebService\Http\HttpTransportException('timed out', true)
            : new \AmwalPay\Ecr\WebService\Http\HttpResponse(200, '{"success":true,"responseCode":"00","data":{"status":"Declined","stan":"000009","amount":"1.000"}}'));
        $ws = new EcrWebServiceTerminal('TW1', TestKeys::webService($this->stateDir()), null, $http);

        self::assertInstanceOf(Failed::class, $ws->sale('1', 'WS-1'));
        $blocked = $ws->sale('1', 'WS-2');
        self::assertInstanceOf(Failed::class, $blocked);
        self::assertInstanceOf(Unresolved::class, $blocked->failure);
        self::assertCount(1, $http->posts);

        $found = $ws->inquireByReference('WS-1');
        self::assertInstanceOf(Found::class, $found);
        self::assertSame(JournalEntry::DECLINED, $ws->guard()->entry($ws->terminalKey(), 'WS-1')?->state);
    }

    public function testTheGuardCanBeTurnedOffForTillsThatEnforceTheRulesThemselves(): void
    {
        $terminal = new EcrTerminal(FakeChannel::always(null), 'TW1', new EcrConfig(guardFinancialOperations: false, autoInquireOnFailure: false, stateDirectory: $this->stateDir()));

        $terminal->sale('1', 'ORD-1');
        $again = $terminal->sale('1', 'ORD-1');

        self::assertInstanceOf(Failed::class, $again);
        self::assertSame([], $terminal->guard()->unresolved($terminal->terminalKey()));
    }

    public function testTheJournalSurvivesReferencesThatLookLikeNumbersAndFailsClosedWhenCorrupt(): void
    {
        $journal = new FileTransactionJournal($this->stateDir());
        $entry = new JournalEntry('0', 'Sale', JournalEntry::UNKNOWN, '1000', '512', '2026-10-25T00:00:00Z', '2026-10-25T00:00:00Z');
        $journal->put('k', $entry);
        $journal->put('k', new JournalEntry('1', 'Sale', JournalEntry::APPROVED, '1000', '512', '2026-10-25T00:00:01Z', gmdate('Y-m-d\TH:i:s\Z')));

        self::assertSame('0', $journal->find('k', '0')?->reference);
        self::assertSame(['0'], array_map(static fn (JournalEntry $e) => $e->reference, $journal->unresolved('k')));

        foreach (glob($this->stateDir() . '/journal-*.json') ?: [] as $file) {
            file_put_contents($file, '{corrupt');
        }
        $this->expectException(\RuntimeException::class);
        $journal->find('k', '0');
    }

    public function testOldSettledEntriesArePrunedButUnresolvedOnesAreKept(): void
    {
        $journal = new FileTransactionJournal($this->stateDir(), retentionDays: 1);
        $old = gmdate('Y-m-d\TH:i:s\Z', time() - 3 * 86400);
        $journal->put('k', new JournalEntry('OLD-OK', 'Sale', JournalEntry::APPROVED, '1', '512', $old, $old));
        $journal->put('k', new JournalEntry('OLD-UNKNOWN', 'Sale', JournalEntry::UNKNOWN, '1', '512', $old, $old));
        $journal->put('k', new JournalEntry('NEW', 'Sale', JournalEntry::APPROVED, '1', '512', $old, gmdate('Y-m-d\TH:i:s\Z')));

        self::assertNull($journal->find('k', 'OLD-OK'));
        self::assertNotNull($journal->find('k', 'OLD-UNKNOWN'));
        self::assertNotNull($journal->find('k', 'NEW'));
    }

    public function testTheLockIsExclusiveAndReleasable(): void
    {
        $lock = new FileTerminalLock($this->stateDir());

        $release = $lock->tryAcquire('terminal-a');
        self::assertNotNull($release);
        self::assertNull($lock->tryAcquire('terminal-a'), 'held');
        $other = $lock->tryAcquire('terminal-b');
        self::assertNotNull($other, 'another terminal is independent');
        $release();
        $again = $lock->tryAcquire('terminal-a');
        self::assertNotNull($again);
        $again();
        $other();
    }

    public function testMarkResolvedNeedsAKnownReferenceAndANote(): void
    {
        $guard = FinancialGuard::fromConfig(new EcrConfig(stateDirectory: $this->stateDir()));

        $this->expectException(\InvalidArgumentException::class);
        $guard->markResolved('k', 'NOPE', 'note');
    }
}
