<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Usb;

/** An opened USB device: its control pipe, and one bulk pipe each way. */
interface UsbPipe
{
    /** False once the device is known to have left the bus. */
    public function stillPresent(): bool;

    /** @return string|null the bytes the device sent, or null when the transfer failed */
    public function controlIn(int $requestType, int $request, int $value, int $index, int $length, int $timeoutMs): ?string;

    /** @return int the bytes transferred, or -1 when the transfer failed */
    public function controlOut(int $requestType, int $request, int $value, int $index, string $data, int $timeoutMs): int;

    /** @return string|null the bytes read, possibly none, or null when the pipe timed out or failed */
    public function bulkRead(int $maxBytes, int $timeoutMs): ?string;

    /** @return int the bytes written, or -1 when the pipe timed out or failed */
    public function bulkWrite(string $bytes, int $timeoutMs): int;

    /** Drops whatever an earlier exchange left in the pipe. */
    public function discardStale(): void;

    public function close(): void;
}
