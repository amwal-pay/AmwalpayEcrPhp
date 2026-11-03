<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Usb;

/** A device opened through libusb. */
final class LibUsbPipe implements UsbPipe
{
    private const ERROR_NO_DEVICE = -4;
    private const ERROR_TIMEOUT = -7;
    private const DRAIN_TIMEOUT_MS = 50;
    private const MAX_DRAIN_BYTES = 262144;

    private bool $closed = false;
    private bool $present = true;

    public function __construct(
        private readonly \FFI $api,
        private readonly int $handle,
        private readonly bool $claimed,
        private readonly int $input,
        private readonly int $output,
    ) {
    }

    public function stillPresent(): bool
    {
        return $this->present;
    }

    public function controlIn(int $requestType, int $request, int $value, int $index, int $length, int $timeoutMs): ?string
    {
        $buffer = $this->api->new('uint8_t[' . max(1, $length) . ']');
        $transferred = $this->api->libusb_control_transfer($this->handle, $requestType, $request, $value, $index, $buffer, $length, max($timeoutMs, 1));
        return $this->note($transferred) < 0 ? null : \FFI::string($buffer, $transferred);
    }

    public function controlOut(int $requestType, int $request, int $value, int $index, string $data, int $timeoutMs): int
    {
        $length = \strlen($data);
        $buffer = $this->api->new('uint8_t[' . max(1, $length) . ']');
        if ($length > 0) {
            \FFI::memcpy($buffer, $data, $length);
        }
        return $this->note($this->api->libusb_control_transfer($this->handle, $requestType, $request, $value, $index, $length > 0 ? $buffer : null, $length, max($timeoutMs, 1)));
    }

    public function bulkRead(int $maxBytes, int $timeoutMs): ?string
    {
        $buffer = $this->api->new('uint8_t[' . $maxBytes . ']');
        $transferred = $this->bulk($this->input, $buffer, $maxBytes, $timeoutMs);
        return $transferred < 0 ? null : ($transferred === 0 ? '' : \FFI::string($buffer, $transferred));
    }

    public function bulkWrite(string $bytes, int $timeoutMs): int
    {
        $length = \strlen($bytes);
        $buffer = $this->api->new('uint8_t[' . max(1, $length) . ']');
        \FFI::memcpy($buffer, $bytes, $length);
        return $this->bulk($this->output, $buffer, $length, $timeoutMs);
    }

    public function discardStale(): void
    {
        $dropped = 0;
        while ($dropped <= self::MAX_DRAIN_BYTES) {
            $read = $this->bulkRead(16384, self::DRAIN_TIMEOUT_MS);
            if ($read === null || $read === '') {
                break;
            }
            $dropped += \strlen($read);
        }
        $this->present = true;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->claimed) {
            $this->api->libusb_release_interface($this->handle, 0);
        }
        $this->api->libusb_close($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function bulk(int $endpoint, \FFI\CData $buffer, int $length, int $timeoutMs): int
    {
        $transferred = $this->api->new('int');
        $result = $this->api->libusb_bulk_transfer($this->handle, $endpoint, $buffer, $length, \FFI::addr($transferred), max($timeoutMs, 1));
        if ($result === 0 || ($result === self::ERROR_TIMEOUT && $transferred->cdata > 0)) {
            return $transferred->cdata;
        }
        $this->note($result);
        return -1;
    }

    private function note(int $result): int
    {
        if ($result === self::ERROR_NO_DEVICE) {
            $this->present = false;
        }
        return $result < 0 ? -1 : $result;
    }
}
