<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\Channel\EcrFrames;
use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\Channel\SerialEcrChannel;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Failure\Unreachable;
use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Link\UsbCableLink;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Settled;
use AmwalPay\Ecr\Serial\SerialPortChoice;
use AmwalPay\Ecr\Serial\SerialPorts;
use AmwalPay\Ecr\Serial\WindowsSerialLine;
use AmwalPay\Ecr\Tests\Support\FakeSerialLine;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The USB cable: which COM port a terminal is on (ported from the Windows
 * SDK's SerialEcrChannelTests), and the same framed, signed exchange as Wi-Fi
 * over a scripted serial line.
 */
final class SerialTest extends TestCase
{
    use TempState;

    // ── Finding the port ──────────────────────────────────────────────

    public function testThePickerOrdersComPortsAndOffersTheAndroidVirtualPortFirst(): void
    {
        $ports = SerialPorts::present([
            new SerialPortChoice('COM7', 'Intel(R) Active Management Technology - SOL (COM7)'),
            new SerialPortChoice('COM8', 'MediaTek USB VCOM (Android) (COM8)'),
            new SerialPortChoice('COM5', 'USB Serial Device (COM5)'),
            new SerialPortChoice('COM6', 'USB Serial Device (COM6)'),
            new SerialPortChoice('', ''),
            new SerialPortChoice('/dev/tty.T6', '/dev/tty.T6'),
            new SerialPortChoice('/dev/cu.T6', '/dev/cu.T6'),
            new SerialPortChoice('Bluetooth-Incoming-Port', 'Bluetooth-Incoming-Port'),
            new SerialPortChoice('wlan-debug', 'wlan-debug'),
        ]);

        self::assertSame(['COM5', 'COM6', 'COM7', 'COM8', '/dev/cu.T6'], array_map(static fn (SerialPortChoice $p): string => $p->name, $ports));
        self::assertSame('COM8', SerialPorts::preferred($ports));
        self::assertSame('COM10', SerialPorts::present([new SerialPortChoice('COM10', ''), new SerialPortChoice('COM9', '')])[1]->name);
    }

    public function testASavedDeviceSerialOpensTheComPortWindowsAssignedNow(): void
    {
        $ports = SerialPorts::present([
            new SerialPortChoice('COM3', 'MediaTek USB VCOM (Android) (COM3)', 'HF4349L000053'),
            new SerialPortChoice('COM8', 'USB Serial Device (COM8)', 'OTHER'),
            new SerialPortChoice('COM5', 'USB Serial Device (COM5)'),
        ]);

        $com3 = array_values(array_filter($ports, static fn (SerialPortChoice $p): bool => $p->name === 'COM3'))[0];
        self::assertSame('HF4349L000053', $com3->selection());
        self::assertSame('HF4349L000053', SerialPorts::preferred($ports, 'hf4349l000053'));
        self::assertSame('COM3', SerialPorts::resolve('HF4349L000053', $ports));
        self::assertSame('COM8', SerialPorts::resolve('COM8', $ports));
        self::assertSame('', SerialPorts::resolve('not-plugged-in', $ports));
        self::assertSame('COM9', SerialPorts::resolve('COM9', $ports));
    }

    public function testTheTerminalListIsTheDevicesPluggedInNow(): void
    {
        $ports = [
            new SerialPortChoice('COM3', 'MediaTek USB VCOM (Android) (COM3)', 'HF4349L000053'),
            new SerialPortChoice('COM8', 'USB Serial Device (COM8)', 'OTHER'),
        ];

        $listed = SerialPorts::listFor('HF4349L000053', 'HF4349L000053', $ports);
        self::assertSame('HF4349L000053', $listed->selected);
        self::assertCount(2, $listed->ports);

        $missing = SerialPorts::listFor('ewrewrwer', '', $ports);
        self::assertSame('HF4349L000053', $missing->selected);
        self::assertCount(2, $missing->ports);
        self::assertNotContains('ewrewrwer', array_map(static fn (SerialPortChoice $p): string => $p->name, $missing->ports));

        $typed = SerialPorts::listFor('COM8', 'HF4349L000053', $ports);
        self::assertSame('HF4349L000053', $typed->selected);

        self::assertSame('', SerialPorts::listFor('', '', [])->selected);
    }

    public function testASaleOpensTheComPortForTheSavedDevice(): void
    {
        $ports = [
            new SerialPortChoice('COM3', 'MediaTek USB VCOM (Android) (COM3)', 'HF4349L000053'),
            new SerialPortChoice('COM8', 'USB Serial Device (COM8)', 'OTHER'),
        ];

        $learned = SerialPorts::portFor('COM3', $ports);
        self::assertSame('COM3', $learned->port);
        self::assertSame('HF4349L000053', $learned->remember);

        $followed = SerialPorts::portFor('HF4349L000053', $ports);
        self::assertSame('COM3', $followed->port);
        self::assertSame('HF4349L000053', $followed->remember);

        $gone = SerialPorts::portFor('ewrewrwer', $ports);
        self::assertSame('', $gone->port);
        self::assertSame('ewrewrwer', $gone->remember);
    }

    public function testComNamesAndDevicePathsAreDirectPortsAndAUsbSerialIsNot(): void
    {
        self::assertTrue(SerialPorts::isDirectPort('COM8'));
        self::assertTrue(SerialPorts::isDirectPort(' com12 '));
        self::assertTrue(SerialPorts::isDirectPort('/dev/ttyACM0'));
        self::assertFalse(SerialPorts::isDirectPort('HF4349L000053'));
        self::assertFalse(SerialPorts::isDirectPort('COMX'));
        self::assertFalse(SerialPorts::isDirectPort(''));
        self::assertSame('COM8', (new SerialPortChoice('COM8', ' '))->label);
    }

    public function testListingThisMachineNeverThrows(): void
    {
        foreach (SerialPorts::list() as $port) {
            self::assertNotSame('', $port->name);
        }
        self::assertIsArray(SerialPorts::presentNames());
    }

    // ── Plan and session ──────────────────────────────────────────────

    public function testAUsbPlanNamesTheComPortAndExpectsTheWifiKey(): void
    {
        $plan = EcrSessions::plan(new UsbCableLink('COM8'), TestKeys::lan($this->stateDir()));

        self::assertTrue($plan->isReady());
        self::assertSame('COM8', $plan->connectionSummary());
        self::assertTrue($plan->usesUsbCable());
        self::assertFalse($plan->usesLan());
        self::assertSame(SecureHash::KEY_LABEL, $plan->signingKeyLabel());

        $unchosen = EcrSessions::plan(new UsbCableLink(), TestKeys::unsigned($this->stateDir()));
        self::assertSame('USB cable', $unchosen->connectionSummary());
        self::assertSame(['Wi-Fi/CABLE secure hash key is not configured'], $unchosen->issues());
    }

    public function testACableSessionNeedsAChannelFactoryAndThenBehavesAsWifi(): void
    {
        $plan = EcrSessions::plan(new UsbCableLink('COM8'), TestKeys::lan($this->stateDir()));

        try {
            EcrSessions::open('TW1', $plan);
            self::fail('a cable session without a channel must not open');
        } catch (\LogicException $e) {
            self::assertSame('USB cable sessions require a channel factory', $e->getMessage());
        }

        $session = EcrSessions::open('TW1', $plan, usbChannel: static fn () => new SerialEcrChannel('COM8'));
        self::assertTrue($session->usesLocalTerminal());
        self::assertTrue($session->supportsReceipt());
        self::assertTrue($session->supportsSettlement());
        self::assertNull($session->checkReachability());
        self::assertSame('cable|com8|0', $session->terminalKey());

        $this->expectException(\InvalidArgumentException::class);
        EcrSessions::usbCableTerminal('TW1', EcrSessions::plan(new \AmwalPay\Ecr\Link\LanLink('h'), TestKeys::lan($this->stateDir())), new SerialEcrChannel('COM8'));
    }

    // ── The exchange ──────────────────────────────────────────────────

    public function testASignedSaleGoesDownTheCableAndALeftoverAnswerIsNotBelieved(): void
    {
        $leftover = EcrFrames::wrap(Wire::signed(Wire::approval('0123456789abcdef0123456789abcdef', 'OLD-1'), TestKeys::WIFI));
        $line = new FakeSerialLine(static function (string $written) use ($leftover): string {
            $request = substr($written, EcrFrames::HEADER_BYTES);
            $answer = EcrFrames::wrap(Wire::signed(Wire::approval(Wire::nonceOf($request), 'ORD-1'), TestKeys::WIFI));
            // An answer to an earlier request arrives first, then this one's.
            return $leftover . $answer;
        }, stale: "\x00\x05junk");
        $session = $this->cable($line);

        $result = $session->sale('1.234', 'ORD-1');

        self::assertInstanceOf(Approved::class, $result);
        self::assertSame('ORD-1', $result->merchantReference);
        self::assertSame(1, $line->writes);
        self::assertTrue($line->closed, 'the port is closed after every exchange');
        $request = Wire::obj(substr($line->written, EcrFrames::HEADER_BYTES));
        self::assertSame(\strlen($line->written) - EcrFrames::HEADER_BYTES, EcrFrames::bodyLength($line->written));
        self::assertSame('SALE', $request->str('messageType'));
        self::assertSame('000000001234', $request->str('amount'));
        self::assertNotSame('', $request->str('secureHash'));
    }

    public function testSettlementWorksDownTheCableToo(): void
    {
        $line = new FakeSerialLine(static fn (string $written): string => EcrFrames::wrap(Wire::signed(
            Wire::obj('{"success":true,"responseCode":"00","data":{"settlementStatus":"SETTLED","batchId":"9","netAmount":"000000001000"}}')
                ->set('nonce', Wire::nonceOf(substr($written, EcrFrames::HEADER_BYTES))),
            TestKeys::WIFI,
        )));

        $settled = $this->cable($line)->settlement();

        self::assertInstanceOf(Settled::class, $settled);
        self::assertSame('1.000', $settled->netAmount);
    }

    public function testNoAnswerInTimeIsATimeoutAndOutcomeUnknown(): void
    {
        $line = new FakeSerialLine(static fn (): string => '');

        $result = $this->cable($line, responseTimeout: 0.2)->sale('1', 'ORD-2');

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(Timeout::class, $result->failure);
        self::assertTrue($result->failure->outcomeUnknown());
        self::assertTrue($line->closed);
    }

    public function testAnEmptyFrameIsMalformedAndALostPortAfterTheWriteIsConnectionLost(): void
    {
        $empty = $this->cable(new FakeSerialLine(static fn (): string => "\x00\x00"))->sale('1', 'ORD-3');
        self::assertInstanceOf(Failed::class, $empty);
        self::assertInstanceOf(Malformed::class, $empty->failure);

        $unplugged = $this->cable(new FakeSerialLine(static fn () => EcrFrames::closed('response')), guardFinancialOperations: false)->sale('1', 'ORD-4');
        self::assertInstanceOf(Failed::class, $unplugged);
        self::assertInstanceOf(ConnectionLost::class, $unplugged->failure);
        self::assertTrue($unplugged->failure->outcomeUnknown(), 'the request was written: the payment may have run');
    }

    public function testAPortThatWillNotOpenIsUnreachableAndNothingIsSent(): void
    {
        $channel = new SerialEcrChannel('COM8', FakeSerialLine::failingOpen("The port 'COM8' does not exist."));
        $plan = EcrSessions::plan(new UsbCableLink('COM8'), TestKeys::lan($this->stateDir(), autoInquireOnFailure: false));
        $result = EcrSessions::open('TW1', $plan, usbChannel: static fn () => $channel)->sale('1', 'ORD-5');

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(Unreachable::class, $result->failure);
        self::assertFalse($result->failure->outcomeUnknown());
        self::assertStringContainsString("The port 'COM8' does not exist.", $result->failure->message);
    }

    public function testAPortThatFailsBeforeTheWriteIsUnreachableNotLost(): void
    {
        $line = new class implements \AmwalPay\Ecr\Serial\SerialLine {
            public function write(string $bytes): void
            {
                throw new \LogicException('must not be reached');
            }

            public function read(int $maxBytes, float $timeout): string
            {
                throw EcrFrames::closed('response');
            }

            public function close(): void
            {
            }
        };
        $result = $this->cable($line)->sale('1', 'ORD-6');

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(Unreachable::class, $result->failure);
    }

    public function testWithoutAPortNothingIsOpenedAndTheProbeSaysWhy(): void
    {
        $opened = false;
        $none = new SerialEcrChannel(' ', static function () use (&$opened): never {
            $opened = true;
            throw new \LogicException('must not open');
        });

        self::assertSame('USB cable', $none->endpoint());
        self::assertSame(0, $none->port());
        self::assertStringContainsString('has not assigned a COM port', (string) $none->probe(3.0));
        try {
            $none->exchange('{}', 1.0);
            self::fail('no port, no exchange');
        } catch (EcrIOException $e) {
            self::assertStringContainsString('has not assigned a COM port', $e->getMessage());
        }
        self::assertFalse($opened);

        $listed = new SerialEcrChannel('COM8', null, static fn (): array => ['COM3', 'com8']);
        self::assertNull($listed->probe(3.0));
        self::assertSame('COM8', $listed->host());

        $unlisted = new SerialEcrChannel('COM8', null, static fn (): array => ['COM3']);
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertSame('Windows is not listing COM8. Plug the terminal in. The COM number follows the device serial and can change.', $unlisted->probe(3.0));
        } else {
            self::assertNull($unlisted->probe(3.0));
        }
    }

    #[RequiresOperatingSystemFamily('Windows')]
    #[RequiresPhpExtension('ffi')]
    public function testWindowsReportsAMissingComPortWithoutSendingAnything(): void
    {
        $this->expectException(EcrIOException::class);
        $this->expectExceptionMessage("The port 'COM250' does not exist.");
        WindowsSerialLine::open('COM250');
    }

    private function cable(\AmwalPay\Ecr\Serial\SerialLine $line, mixed ...$changes): \AmwalPay\Ecr\EcrOpenedSession
    {
        $plan = EcrSessions::plan(new UsbCableLink('COM8'), TestKeys::lan($this->stateDir(), ...['autoInquireOnFailure' => false, ...$changes]));
        return EcrSessions::open('TW1', $plan, usbChannel: static fn () => new SerialEcrChannel('COM8', static fn () => $line));
    }
}
