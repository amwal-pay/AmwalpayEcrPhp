<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Integration;

use AmwalPay\Ecr\EcrTerminal;
use AmwalPay\Ecr\Failure\Busy;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Tests\Support\BackgroundProcess;
use AmwalPay\Ecr\Tests\Support\FakeChannel;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use PHPUnit\Framework\TestCase;

/**
 * One financial transaction per terminal across PHP processes, as on a web
 * server where every request is its own process.
 */
final class CrossProcessGuardTest extends TestCase
{
    use TempState;

    public function testASaleWhileAnotherProcessHoldsTheTerminalIsBusyAndNotSent(): void
    {
        $channel = FakeChannel::always('{"success":true,"responseCode":"00","data":{"amount":"1.000"}}');
        $terminal = new EcrTerminal($channel, 'TW1', TestKeys::unsigned($this->stateDir()));
        $other = new BackgroundProcess([__DIR__ . '/../Support/hold-terminal.php', $this->stateDir(), $terminal->terminalKey()]);

        $busy = $terminal->sale('1.000', 'ORD-1');
        $inquiry = (new EcrTerminal(FakeChannel::always(Wire::FOUND), 'TW1', TestKeys::unsigned($this->stateDir())))->inquireByReference('ANY');

        self::assertInstanceOf(Failed::class, $busy);
        self::assertInstanceOf(Busy::class, $busy->failure);
        self::assertSame([], $channel->requests);
        self::assertInstanceOf(Found::class, $inquiry, 'an inquiry is never blocked');

        $other->stop();
        self::assertInstanceOf(Approved::class, $terminal->sale('1.000', 'ORD-1'), 'the lock went with the process');
    }
}
