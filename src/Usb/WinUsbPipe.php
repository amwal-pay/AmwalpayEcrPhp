<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Usb;

/**
 * A device opened through WinUSB. Whether it left the bus is not read from
 * `GetLastError()`, which PHP disturbs between two FFI calls: the channel lists
 * the bus instead, once a second while it waits.
 */
final class WinUsbPipe implements UsbPipe
{
    private const PIPE_TRANSFER_TIMEOUT = 3;
    private const DRAIN_TIMEOUT_MS = 50;
    private const MAX_DRAIN_BYTES = 262144;

    private bool $closed = false;

    public function __construct(
        private readonly \FFI $kernel32,
        private readonly \FFI $winusb,
        private readonly int $handle,
        private readonly int $iface,
        private readonly int $input,
        private readonly int $output,
    ) {
    }

    public function stillPresent(): bool
    {
        return true;
    }

    public function controlIn(int $requestType, int $request, int $value, int $index, int $length, int $timeoutMs): ?string
    {
        $buffer = $this->winusb->new('uint8_t[' . max(1, $length) . ']');
        $transferred = $this->control($requestType, $request, $value, $index, $buffer, $length);
        return $transferred < 0 ? null : \FFI::string($buffer, $transferred);
    }

    public function controlOut(int $requestType, int $request, int $value, int $index, string $data, int $timeoutMs): int
    {
        $length = \strlen($data);
        if ($length === 0) {
            return $this->control($requestType, $request, $value, $index, null, 0);
        }
        $buffer = $this->winusb->new('uint8_t[' . $length . ']');
        \FFI::memcpy($buffer, $data, $length);
        return $this->control($requestType, $request, $value, $index, $buffer, $length);
    }

    public function bulkRead(int $maxBytes, int $timeoutMs): ?string
    {
        $this->setTimeout($this->input, $timeoutMs);
        $buffer = $this->winusb->new('uint8_t[' . $maxBytes . ']');
        $transferred = $this->winusb->new('uint32_t');
        if (!$this->winusb->WinUsb_ReadPipe($this->iface, $this->input, $buffer, $maxBytes, \FFI::addr($transferred), null)) {
            return null;
        }
        return $transferred->cdata === 0 ? '' : \FFI::string($buffer, $transferred->cdata);
    }

    public function bulkWrite(string $bytes, int $timeoutMs): int
    {
        $this->setTimeout($this->output, $timeoutMs);
        $length = \strlen($bytes);
        $buffer = $this->winusb->new('uint8_t[' . max(1, $length) . ']');
        \FFI::memcpy($buffer, $bytes, $length);
        $transferred = $this->winusb->new('uint32_t');
        if (!$this->winusb->WinUsb_WritePipe($this->iface, $this->output, $buffer, $length, \FFI::addr($transferred), null)) {
            return -1;
        }
        return $transferred->cdata;
    }

    public function discardStale(): void
    {
        $dropped = 0;
        while ($dropped <= self::MAX_DRAIN_BYTES) {
            $read = $this->bulkRead(16384, self::DRAIN_TIMEOUT_MS);
            if ($read === null || $read === '') {
                return;
            }
            $dropped += \strlen($read);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->winusb->WinUsb_Free($this->iface);
        $this->kernel32->CloseHandle($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function control(int $requestType, int $request, int $value, int $index, ?\FFI\CData $buffer, int $length): int
    {
        $setup = $this->winusb->new('WINUSB_SETUP_PACKET');
        $setup->RequestType = $requestType;
        $setup->Request = $request;
        $setup->Value = $value;
        $setup->Index = $index;
        $setup->Length = $length;
        $transferred = $this->winusb->new('uint32_t');
        if (!$this->winusb->WinUsb_ControlTransfer($this->iface, $setup, $buffer, $length, \FFI::addr($transferred), null)) {
            return -1;
        }
        return $transferred->cdata;
    }

    private function setTimeout(int $pipe, int $timeoutMs): void
    {
        $timeout = $this->winusb->new('uint32_t');
        $timeout->cdata = max($timeoutMs, 1);
        $this->winusb->WinUsb_SetPipePolicy($this->iface, $pipe, self::PIPE_TRANSFER_TIMEOUT, 4, \FFI::addr($timeout));
    }
}
