<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\Channel\EcrChannelTimeout;
use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\Channel\UsbAccessoryEcrChannel;
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Unreachable;
use AmwalPay\Ecr\Link\UsbCableLink;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Tests\Support\TempState;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Usb\LibUsbAccessoryBus;
use AmwalPay\Ecr\Usb\UsbAccessoryBus;
use AmwalPay\Ecr\Usb\UsbPipe;
use AmwalPay\Ecr\Usb\UsbPlug;
use AmwalPay\Ecr\Usb\WinUsbAccessoryBus;
use PHPUnit\Framework\TestCase;

/** The accessory handshake and framing, ported from the Windows SDK's UsbAccessoryEcrChannelTests, over a scripted bus. */
final class UsbAccessoryEcrChannelTest extends TestCase
{
    use TempState;

    public function testAnAccessoryAlreadyOnTheBusIsReachable(): void
    {
        $bus = new ScriptedBus();
        $bus->plugs[] = new UsbPlug('aoa', 0x18D1, 0x2D00);

        self::assertNull((new UsbAccessoryEcrChannel($bus))->probe(1.0));
    }

    public function testProbeSaysWhatIsWrong(): void
    {
        $nothing = new ScriptedBus();
        self::assertSame('Nothing is plugged in. Connect the terminal with a USB cable.', (new UsbAccessoryEcrChannel($nothing))->probe(1.0));

        $mouse = new ScriptedBus();
        $mouse->speaks = false;
        $mouse->plugs[] = new UsbPlug('mouse', 0x046D, 0xC077);
        self::assertSame('A USB device is connected but it is not a terminal in accessory mode.', (new UsbAccessoryEcrChannel($mouse))->probe(1.0));

        $locked = new ScriptedBus();
        $locked->openFails = 'refused';
        $locked->plugs[] = new UsbPlug('phone', 0x1234, 0x0001);
        self::assertSame('refused', (new UsbAccessoryEcrChannel($locked))->probe(1.0));

        $noFfi = new ScriptedBus();
        $noFfi->unavailable = 'needs FFI';
        self::assertSame('needs FFI', (new UsbAccessoryEcrChannel($noFfi))->probe(1.0));
    }

    public function testASaleSwitchesTheTerminalThenReadsTheMatchingAnswer(): void
    {
        $bus = new ScriptedBus();
        $bus->plugs[] = new UsbPlug('phone', 0x1234, 0x0001);

        $body = (new UsbAccessoryEcrChannel($bus))->exchange('{"nonce":"abc"}', 2.0);

        self::assertSame('{"nonce":"abc","ok":true}', $body);
        self::assertContains('phone', $bus->opened);
        self::assertContains('aoa', $bus->opened);
        self::assertSame(['AmwalPay', 'EcrBridge', 'ECR requests over the cable', '1.0', 'https://amwal-pay.com', '0000000000000001'], $bus->identity);
        self::assertSame(pack('n', 15) . '{"nonce":"abc"}', $bus->written);
        self::assertSame(0, $bus->openPipes, 'every pipe is closed again');
    }

    public function testAStaleAnswerIsSkippedUntilTheNonceMatches(): void
    {
        $bus = new ScriptedBus();
        $bus->plugs[] = new UsbPlug('aoa', 0x18D1, 0x2D00);
        $bus->answers = [self::frame('{"nonce":"old"}') . self::frame('{"nonce":"mine","ok":true}')];

        self::assertSame('{"nonce":"mine","ok":true}', (new UsbAccessoryEcrChannel($bus))->exchange('{"nonce":"mine"}', 2.0));
    }

    public function testAFrameSplitAcrossReadsIsPutBackTogether(): void
    {
        $bus = new ScriptedBus();
        $bus->plugs[] = new UsbPlug('aoa', 0x18D1, 0x2D00);
        $frame = self::frame('{"nonce":"n","ok":true}');
        $bus->answers = [substr($frame, 0, 1), substr($frame, 1, 5), substr($frame, 6)];

        self::assertSame('{"nonce":"n","ok":true}', (new UsbAccessoryEcrChannel($bus))->exchange('{"nonce":"n"}', 2.0));
    }

    public function testTheAccessoryInterfaceIsOpenedBeforeTheCompositeParent(): void
    {
        $bus = new ScriptedBus();
        array_push(
            $bus->plugs,
            new UsbPlug('usb#vid_18d1&pid_2d01&mi_01', 0x18D1, 0x2D01),
            new UsbPlug('usb#vid_18d1&pid_2d01', 0x18D1, 0x2D01),
            new UsbPlug('usb#vid_18d1&pid_2d01&mi_00', 0x18D1, 0x2D01),
        );
        $bus->answers = [self::frame('{"nonce":"n"}')];

        (new UsbAccessoryEcrChannel($bus))->exchange('{"nonce":"n"}', 2.0);

        self::assertSame('usb#vid_18d1&pid_2d01&mi_00', $bus->opened[0]);
    }

    public function testNoAnswerIsATimeout(): void
    {
        $bus = new ScriptedBus();
        $bus->plugs[] = new UsbPlug('aoa', 0x18D1, 0x2D00);
        $bus->answers = [];

        $this->expectException(EcrChannelTimeout::class);
        (new UsbAccessoryEcrChannel($bus))->exchange('{"nonce":"n"}', 0.05);
    }

    public function testATerminalThatLeavesAfterTheWriteIsAnUnknownOutcome(): void
    {
        $bus = new ScriptedBus();
        $bus->plugs[] = new UsbPlug('aoa', 0x18D1, 0x2D00);
        $bus->answers = [];
        $bus->leavesAfterWrite = true;

        $result = $this->sale($bus);

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(ConnectionLost::class, $result->failure, 'the terminal may have acted on it');
        self::assertTrue($result->failure->outcomeUnknown());
    }

    public function testAWriteThatDoesNotGoThroughIsAnUnknownOutcome(): void
    {
        $bus = new ScriptedBus();
        $bus->plugs[] = new UsbPlug('aoa', 0x18D1, 0x2D00);
        $bus->writeFails = true;

        $result = $this->sale($bus);

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(ConnectionLost::class, $result->failure);
    }

    public function testATerminalThatCannotBeOpenedMeansNothingWasSent(): void
    {
        $bus = new ScriptedBus();
        $bus->plugs[] = new UsbPlug('aoa', 0x18D1, 0x2D00);
        $bus->openFails = 'Could not open the terminal on the cable.';

        $thrown = null;
        try {
            (new UsbAccessoryEcrChannel($bus))->exchange('{"nonce":"n"}', 0.1);
        } catch (EcrIOException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertSame('Could not open the terminal on the cable.', $thrown->getMessage());
        self::assertSame('', $bus->written);
    }

    public function testUnavailableIsReportedBeforeAnythingIsSent(): void
    {
        $bus = new ScriptedBus();
        $bus->unavailable = 'needs FFI';

        $result = $this->sale($bus);

        self::assertInstanceOf(Failed::class, $result);
        self::assertInstanceOf(Unreachable::class, $result->failure);
        self::assertStringContainsString('needs FFI', $result->failure->message);
    }

    public function testAnAccessoryChannelServesACableSession(): void
    {
        $plan = EcrSessions::plan(new UsbCableLink(), new EcrConfig(secureHashKey: TestKeys::WIFI, stateDirectory: $this->stateDir()));
        $session = EcrSessions::open('SER', $plan, usbChannel: static fn () => new UsbAccessoryEcrChannel(new ScriptedBus()));

        self::assertTrue($session->supportsSettlement());
        self::assertSame('USB cable', $plan->connectionSummary());
    }

    public function testHardwareIdsAreRead(): void
    {
        self::assertSame([0x18D1, 0x2D00], WinUsbAccessoryBus::vidPid('USB\\VID_18D1&PID_2D00&MI_00'));
        self::assertSame([0x0E8D, 0x201C], WinUsbAccessoryBus::vidPid('USB\\VID_0E8D&PID_201C&REV_0100&MI_00' . "\0" . 'USB\\VID_0E8D&PID_201C&MI_00'));
        self::assertNull(WinUsbAccessoryBus::vidPid('ACPI\\PNP0501'));
    }

    public function testConfigurationDescriptorsAreRead(): void
    {
        $configHeader = "\x09\x02\x20\x00\x02\x01\x00\x80\x32";
        $adb = "\x09\x04\x00\x00\x02\xFF\x42\x01\x00";
        $ethernet = "\x09\x04\x00\x00\x02\xFF\x06\x00\x00";
        self::assertTrue(LibUsbAccessoryBus::hasAdbInterface($configHeader . $adb));
        self::assertFalse(LibUsbAccessoryBus::hasAdbInterface($configHeader . $ethernet), 'USB ethernet must not be asked to switch');
        self::assertFalse(LibUsbAccessoryBus::hasAdbInterface("\x01"));

        $interrupt = "\x07\x05\x83\x03\x40\x00\x01";
        $in = "\x07\x05\x84\x02\x00\x02\x00";
        $out = "\x07\x05\x02\x02\x00\x02\x00";
        $otherInterface = "\x09\x04\x01\x00\x02\xFF\x42\x01\x00\x07\x05\x85\x02\x00\x02\x00";
        self::assertSame([0x84, 0x02], LibUsbAccessoryBus::bulkEndpoints($configHeader . $adb . $interrupt . $in . $out . $otherInterface));
        self::assertSame([0x81, 0x01], LibUsbAccessoryBus::bulkEndpoints(''), 'the usual pair when the device does not say');
    }

    private function sale(ScriptedBus $bus): mixed
    {
        $plan = EcrSessions::plan(new UsbCableLink(), new EcrConfig(secureHashKey: TestKeys::WIFI, stateDirectory: $this->stateDir(), autoInquireOnFailure: false, responseTimeout: 1.2));
        return EcrSessions::open('SER', $plan, usbChannel: static fn () => new UsbAccessoryEcrChannel($bus))->sale('1.000', 'ORDER-1');
    }

    private static function frame(string $json): string
    {
        return pack('n', \strlen($json)) . $json;
    }
}

final class ScriptedBus implements UsbAccessoryBus
{
    /** @var list<UsbPlug> */
    public array $plugs = [];
    /** @var list<string> */
    public array $opened = [];
    /** @var list<string> */
    public array $identity = [];
    /** @var list<string>|null null: answer whatever nonce was sent */
    public ?array $answers = null;
    public string $written = '';
    public int $openPipes = 0;
    public bool $speaks = true;
    public bool $writeFails = false;
    public bool $leavesAfterWrite = false;
    public ?string $openFails = null;
    public ?string $unavailable = null;

    public function unavailable(): ?string
    {
        return $this->unavailable;
    }

    public function nothingPluggedIn(): string
    {
        return 'Nothing is plugged in. Connect the terminal with a USB cable.';
    }

    public function list(): array
    {
        return $this->plugs;
    }

    public function open(UsbPlug $plug): UsbPipe
    {
        if ($this->openFails !== null) {
            throw new EcrIOException($this->openFails);
        }
        $this->opened[] = $plug->id;
        $this->openPipes++;
        return new ScriptedPipe($this);
    }
}

final class ScriptedPipe implements UsbPipe
{
    private bool $closed = false;

    public function __construct(private readonly ScriptedBus $bus)
    {
    }

    public function stillPresent(): bool
    {
        return true;
    }

    public function controlIn(int $requestType, int $request, int $value, int $index, int $length, int $timeoutMs): ?string
    {
        return $request === 51 && $this->bus->speaks ? "\x01\x00" : null;
    }

    public function controlOut(int $requestType, int $request, int $value, int $index, string $data, int $timeoutMs): int
    {
        if ($request === 52) {
            $this->bus->identity[$index] = rtrim($data, "\0");
        } elseif ($request === 53) {
            $this->bus->plugs = [new UsbPlug('aoa', 0x18D1, 0x2D00)];
        }
        return \strlen($data);
    }

    public function bulkRead(int $maxBytes, int $timeoutMs): ?string
    {
        if ($this->bus->answers === null) {
            if ($this->bus->written === '') {
                return null;
            }
            $json = substr($this->bus->written, 2);
            $this->bus->answers = [];
            $answer = substr($json, 0, -1) . ',"ok":true}';
            return pack('n', \strlen($answer)) . $answer;
        }
        if ($this->bus->written === '' || $this->bus->answers === []) {
            usleep(min($timeoutMs, 5) * 1000);
            return null;
        }
        return array_shift($this->bus->answers);
    }

    public function bulkWrite(string $bytes, int $timeoutMs): int
    {
        if ($this->bus->writeFails) {
            return -1;
        }
        $this->bus->written .= $bytes;
        if ($this->bus->leavesAfterWrite) {
            $this->bus->plugs = [];
        }
        return \strlen($bytes);
    }

    public function discardStale(): void
    {
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->bus->openPipes--;
        }
    }
}
