<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Channel;

use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Usb\LibUsbAccessoryBus;
use AmwalPay\Ecr\Usb\UsbAccessoryBus;
use AmwalPay\Ecr\Usb\UsbPipe;
use AmwalPay\Ecr\Usb\UsbPlug;
use AmwalPay\Ecr\Usb\WinUsbAccessoryBus;

/**
 * Reaches the terminal down a USB cable with Android Open Accessory, with no IP
 * and no COM port anywhere: the alternate host path to {@see SerialEcrChannel},
 * for a terminal that speaks accessory mode.
 *
 * This till is the USB host and the terminal is the accessory. Ask the terminal
 * what protocol it speaks, say who is calling, then tell it to switch. It drops
 * off the bus and comes back under Google's ids with one bulk pipe each way, and
 * those two pipes are the whole link.
 *
 * Bytes only. Signing, checking the answer and deciding what a response code
 * means stay in {@see \AmwalPay\Ecr\EcrTerminal}, the same as Wi-Fi. Windows opens
 * the device with WinUSB and needs the driver Amwal provides attached to it;
 * macOS and Linux open it with libusb. Both go through PHP's FFI extension.
 */
final class UsbAccessoryEcrChannel implements EcrChannel
{
    private const AOA_VENDOR = 0x18D1;
    private const AOA_PRODUCTS = [0x2D00, 0x2D01, 0x2D04, 0x2D05];
    private const GET_PROTOCOL = 51;
    private const SEND_STRING = 52;
    private const START = 53;
    private const PROTOCOL_TIMEOUT_MS = 500;
    private const CONTROL_TIMEOUT_MS = 2000;
    private const WRITE_TIMEOUT_MS = 3000;
    private const RECONNECT_ATTEMPTS = 30;
    private const RECONNECT_INTERVAL_US = 500_000;
    private const READ_SIZE = 16384;
    private const PRESENCE_CHECK_SECONDS = 1.0;

    /** Must match `usb_accessory_filter.xml` in the POS app, or Android hands the cable to nobody. */
    private const IDENTITY = [
        'AmwalPay',
        'EcrBridge',
        'ECR requests over the cable',
        '1.0',
        'https://amwal-pay.com',
        '0000000000000001',
    ];

    private readonly UsbAccessoryBus $bus;

    /** @param UsbAccessoryBus|null $bus Null: WinUSB on Windows, libusb elsewhere. */
    public function __construct(?UsbAccessoryBus $bus = null)
    {
        $this->bus = $bus ?? (\PHP_OS_FAMILY === 'Windows' ? new WinUsbAccessoryBus() : new LibUsbAccessoryBus());
    }

    public function endpoint(): string
    {
        return 'USB cable';
    }

    public function host(): string
    {
        return $this->endpoint();
    }

    public function port(): int
    {
        return 0;
    }

    public function probe(float $timeout): ?string
    {
        $unavailable = $this->bus->unavailable();
        if ($unavailable !== null && $unavailable !== '') {
            return $unavailable;
        }
        try {
            return $this->findProblem();
        } catch (EcrIOException $e) {
            return $e->getMessage();
        }
    }

    public function exchange(string $body, float $timeout): string
    {
        $unavailable = $this->bus->unavailable();
        if ($unavailable !== null && $unavailable !== '') {
            throw new EcrIOException($unavailable);
        }
        // Framed first: a request too large for a frame is a local error.
        $packet = EcrFrames::wrap($body);
        $pipe = $this->openAccessory();
        try {
            $pipe->discardStale();
            $written = $pipe->bulkWrite($packet, self::WRITE_TIMEOUT_MS);
            if ($written < \strlen($packet)) {
                // Some of it may have gone out. "Closed the connection" makes this an
                // unknown outcome: never report it as "nothing was sent".
                throw new EcrIOException('The terminal closed the connection after ' . max(0, $written) . ' of ' . \strlen($packet) . ' bytes');
            }
            return $this->readFrame($pipe, $timeout, self::nonceOf($body));
        } finally {
            $pipe->close();
        }
    }

    private function findProblem(): ?string
    {
        $plugs = $this->bus->list();
        if (self::orderedAccessories($plugs) !== []) {
            return null;
        }
        $openProblem = null;
        $opened = false;
        foreach ($plugs as $plug) {
            if (self::isAccessory($plug)) {
                continue;
            }
            try {
                if ($this->speaksAccessory($plug)) {
                    return null;
                }
                $opened = true;
            } catch (EcrIOException $e) {
                $openProblem ??= $e->getMessage();
            }
        }
        if ($plugs === []) {
            return $this->bus->nothingPluggedIn();
        }
        if (!$opened && $openProblem !== null) {
            return $openProblem;
        }
        return 'A USB device is connected but it is not a terminal in accessory mode.';
    }

    private function readFrame(UsbPipe $pipe, float $timeout, string $expectedNonce): string
    {
        $pending = '';
        $deadline = microtime(true) + $timeout;
        $nextPresenceCheck = microtime(true) + self::PRESENCE_CHECK_SECONDS;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new EcrChannelTimeout('the terminal did not answer in time');
            }
            $slice = (int) ceil(min($remaining, self::PRESENCE_CHECK_SECONDS) * 1000);
            $chunk = $pipe->bulkRead(self::READ_SIZE, $slice);
            if ($chunk === null) {
                if (microtime(true) >= $nextPresenceCheck) {
                    $nextPresenceCheck = microtime(true) + self::PRESENCE_CHECK_SECONDS;
                    if (!$pipe->stillPresent() || self::orderedAccessories($this->listAfterWrite()) === []) {
                        throw new EcrIOException(
                            'The terminal closed the connection: it left accessory mode and nothing on the cable is listening. Check that its ECR mode is still USB cable.',
                        );
                    }
                }
                // A pipe that fails at once must not spin until the next presence check.
                usleep(1000);
                continue;
            }
            $pending .= $chunk;

            while (\strlen($pending) >= EcrFrames::HEADER_BYTES) {
                $length = EcrFrames::bodyLength($pending);
                if ($length <= 0) {
                    throw new EcrIOException('Terminal returned an empty message');
                }
                if (\strlen($pending) < EcrFrames::HEADER_BYTES + $length) {
                    break;
                }
                $frame = substr($pending, EcrFrames::HEADER_BYTES, $length);
                $pending = (string) substr($pending, EcrFrames::HEADER_BYTES + $length);
                if ($expectedNonce === '') {
                    return $frame;
                }
                $answered = self::nonceOf($frame);
                if ($answered === '' || $answered === $expectedNonce) {
                    return $frame;
                }
            }
        }
    }

    /**
     * The bus could not be listed while waiting for an answer: the request is out,
     * so that is a lost connection, not a terminal that was never reached.
     *
     * @return list<UsbPlug>
     */
    private function listAfterWrite(): array
    {
        try {
            return $this->bus->list();
        } catch (EcrIOException $e) {
            throw new EcrIOException('The terminal closed the connection: ' . $e->getMessage());
        }
    }

    /**
     * Opens the accessory pipes. Windows lists the composite parent and each
     * interface separately; the accessory is interface 0, and a parent that is not
     * WinUSB is skipped.
     */
    private function openAccessory(): UsbPipe
    {
        $last = null;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $opened = $this->tryCandidates(self::orderedAccessories($this->bus->list()));
                if ($opened !== null) {
                    return $opened;
                }
                break;
            } catch (EcrIOException $e) {
                $last = $e;
                usleep(self::RECONNECT_INTERVAL_US);
            }
        }

        $this->switchIntoAccessoryMode();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $opened = $this->tryCandidates(self::orderedAccessories($this->bus->list()));
                if ($opened !== null) {
                    return $opened;
                }
            } catch (EcrIOException $e) {
                $last = $e;
            }
            usleep(self::RECONNECT_INTERVAL_US);
        }

        throw $last ?? new EcrIOException('The terminal did not come back in accessory mode. Check the cable and that the POS app is on USB cable mode.');
    }

    /** @param list<UsbPlug> $candidates */
    private function tryCandidates(array $candidates): ?UsbPipe
    {
        $last = null;
        foreach ($candidates as $plug) {
            try {
                return $this->bus->open($plug);
            } catch (EcrIOException $e) {
                $last = $e;
            }
        }
        if ($last !== null) {
            throw $last;
        }
        return null;
    }

    private function switchIntoAccessoryMode(): void
    {
        $openProblem = null;
        $opened = false;
        foreach ($this->bus->list() as $plug) {
            if (self::isAccessory($plug)) {
                continue;
            }
            try {
                $started = $this->startAccessory($plug);
            } catch (EcrIOException $e) {
                $openProblem ??= $e->getMessage();
                continue;
            }
            $opened = true;
            if (!$started) {
                continue;
            }
            for ($attempt = 0; $attempt < self::RECONNECT_ATTEMPTS; $attempt++) {
                usleep(self::RECONNECT_INTERVAL_US);
                if (self::orderedAccessories($this->bus->list()) !== []) {
                    return;
                }
            }
        }
        if (!$opened && $openProblem !== null) {
            throw new EcrIOException($openProblem);
        }
    }

    /** Whether this device answers the accessory protocol request. */
    private function speaksAccessory(UsbPlug $plug): bool
    {
        $pipe = $this->bus->open($plug);
        try {
            return self::protocolVersion($pipe) >= 1;
        } finally {
            $pipe->close();
        }
    }

    /** Tells a device that speaks accessory mode to switch. False when it does not. */
    private function startAccessory(UsbPlug $plug): bool
    {
        $pipe = $this->bus->open($plug);
        try {
            if (self::protocolVersion($pipe) < 1) {
                return false;
            }
            foreach (self::IDENTITY as $index => $text) {
                $pipe->controlOut(0x40, self::SEND_STRING, 0, $index, $text . "\0", self::CONTROL_TIMEOUT_MS);
            }
            $pipe->controlOut(0x40, self::START, 0, 0, '', self::CONTROL_TIMEOUT_MS);
            return true;
        } finally {
            $pipe->close();
        }
    }

    private static function protocolVersion(UsbPipe $pipe): int
    {
        $version = $pipe->controlIn(0xC0, self::GET_PROTOCOL, 0, 0, 2, self::PROTOCOL_TIMEOUT_MS);
        if ($version === null || \strlen($version) < 2) {
            return 0;
        }
        return \ord($version[0]) | (\ord($version[1]) << 8);
    }

    private static function isAccessory(UsbPlug $plug): bool
    {
        return $plug->vendorId === self::AOA_VENDOR && \in_array($plug->productId, self::AOA_PRODUCTS, true);
    }

    /**
     * Accessory interfaces first (MI_00), then the composite parent, then any other interface.
     *
     * @param list<UsbPlug> $plugs
     * @return list<UsbPlug>
     */
    private static function orderedAccessories(array $plugs): array
    {
        $found = array_values(array_filter($plugs, self::isAccessory(...)));
        usort($found, static fn (UsbPlug $a, UsbPlug $b): int => self::accessoryRank($a->id) <=> self::accessoryRank($b->id));
        return $found;
    }

    private static function accessoryRank(string $id): int
    {
        $path = strtolower($id);
        if (str_contains($path, 'mi_00')) {
            return 0;
        }
        return str_contains($path, 'mi_') ? 2 : 1;
    }

    private static function nonceOf(string $body): string
    {
        if ($body === '') {
            return '';
        }
        try {
            $nonce = Json::parseObject($body)->get('nonce');
        } catch (\Throwable) {
            return '';
        }
        return \is_string($nonce) ? $nonce : '';
    }
}
