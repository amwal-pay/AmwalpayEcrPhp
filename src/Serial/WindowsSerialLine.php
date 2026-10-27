<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Serial;

use AmwalPay\Ecr\Channel\EcrFrames;
use AmwalPay\Ecr\Channel\EcrIOException;

/**
 * A Windows COM port through kernel32, by PHP's FFI extension: PHP's own
 * streams cannot put a deadline on a COM read, and a payment wait without one
 * could hang a till for good.
 *
 * Needs `extension=ffi`, and `ffi.enable=true` under a web server (the default
 * `preload` allows FFI on the command line only).
 */
final class WindowsSerialLine implements SerialLine
{
    private const DEFINITIONS = <<<'C'
        typedef struct {
            uint32_t DCBlength;
            uint32_t BaudRate;
            uint32_t Flags;
            uint16_t wReserved;
            uint16_t XonLim;
            uint16_t XoffLim;
            uint8_t ByteSize;
            uint8_t Parity;
            uint8_t StopBits;
            char XonChar;
            char XoffChar;
            char ErrorChar;
            char EofChar;
            char EvtChar;
            uint16_t wReserved1;
        } DCB;
        typedef struct {
            uint32_t ReadIntervalTimeout;
            uint32_t ReadTotalTimeoutMultiplier;
            uint32_t ReadTotalTimeoutConstant;
            uint32_t WriteTotalTimeoutMultiplier;
            uint32_t WriteTotalTimeoutConstant;
        } COMMTIMEOUTS;
        intptr_t CreateFileA(const char* name, uint32_t access, uint32_t share, void* security, uint32_t disposition, uint32_t flags, intptr_t templateFile);
        int CloseHandle(intptr_t handle);
        int GetCommState(intptr_t handle, DCB* dcb);
        int SetCommState(intptr_t handle, DCB* dcb);
        int BuildCommDCBA(const char* definition, DCB* dcb);
        int SetCommTimeouts(intptr_t handle, COMMTIMEOUTS* timeouts);
        int ReadFile(intptr_t handle, void* buffer, uint32_t toRead, uint32_t* read, void* overlapped);
        int WriteFile(intptr_t handle, const void* buffer, uint32_t toWrite, uint32_t* written, void* overlapped);
        C;

    private const GENERIC_READ_WRITE = 0xC0000000;
    private const OPEN_EXISTING = 3;
    private const MAXDWORD = 0xFFFFFFFF;
    private const WRITE_TIMEOUT_MS = 3000;
    private const INVALID_HANDLE_VALUE = -1;

    private bool $closed = false;

    /** @param int $handle A HANDLE, declared as `intptr_t` so PHP compares it as a number. */
    private function __construct(
        private readonly \FFI $kernel32,
        private readonly int $handle,
        private readonly string $port,
    ) {
    }

    /** @throws EcrIOException */
    public static function open(string $port, int $baudRate = 115200): self
    {
        $kernel32 = self::kernel32();
        $path = str_starts_with($port, '\\\\.\\') ? $port : '\\\\.\\' . $port;
        $handle = $kernel32->CreateFileA($path, self::GENERIC_READ_WRITE, 0, null, self::OPEN_EXISTING, 0, 0);
        if ($handle === self::INVALID_HANDLE_VALUE || $handle === 0) {
            // PHP itself calls Windows between two FFI calls, so GetLastError()
            // is not this failure's. Whether Windows lists the port tells the
            // two usual causes apart.
            $listed = false;
            foreach (SerialPorts::presentNames() as $name) {
                $listed = $listed || strcasecmp($name, $port) === 0;
            }
            throw new EcrIOException($listed
                ? "Access to the port '" . $port . "' is denied. Another program may have it open."
                : "The port '" . $port . "' does not exist.");
        }

        $line = new self($kernel32, $handle, $port);
        try {
            $dcb = $kernel32->new('DCB');
            $dcb->DCBlength = \FFI::sizeof($dcb);
            // No handshake, DTR and RTS held on: what "MediaTek USB VCOM" expects.
            $definition = 'baud=' . $baudRate . ' parity=N data=8 stop=1 xon=off odsr=off octs=off dtr=on rts=on idsr=off';
            if (!$kernel32->GetCommState($handle, \FFI::addr($dcb))
                || !$kernel32->BuildCommDCBA($definition, \FFI::addr($dcb))
                || !$kernel32->SetCommState($handle, \FFI::addr($dcb))) {
                throw new EcrIOException('Could not set ' . $port . ' to ' . $baudRate . ' 8N1');
            }
        } catch (EcrIOException $e) {
            $line->close();
            throw $e;
        }
        return $line;
    }

    public function write(string $bytes): void
    {
        $length = \strlen($bytes);
        if ($length === 0) {
            return;
        }
        $this->timeouts(0);
        $buffer = $this->kernel32->new('char[' . $length . ']');
        \FFI::memcpy($buffer, $bytes, $length);
        $written = $this->kernel32->new('uint32_t');
        $ok = $this->kernel32->WriteFile($this->handle, $buffer, $length, \FFI::addr($written), null);
        if (!$ok || $written->cdata !== $length) {
            throw new EcrIOException('The terminal did not take the request on ' . $this->port);
        }
    }

    public function read(int $maxBytes, float $timeout): string
    {
        // MAXDWORD / MAXDWORD / constant: return as soon as any byte is there,
        // otherwise wait up to the constant for the first one.
        $this->timeouts((int) max(1, min(ceil($timeout * 1000), self::MAXDWORD - 1)));
        $buffer = $this->kernel32->new('char[' . $maxBytes . ']');
        $read = $this->kernel32->new('uint32_t');
        if (!$this->kernel32->ReadFile($this->handle, $buffer, $maxBytes, \FFI::addr($read), null)) {
            // The request may already be with the terminal: the outcome is unknown.
            throw EcrFrames::closed('response');
        }
        return $read->cdata === 0 ? '' : \FFI::string($buffer, $read->cdata);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->kernel32->CloseHandle($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function timeouts(int $readMilliseconds): void
    {
        $timeouts = $this->kernel32->new('COMMTIMEOUTS');
        $timeouts->ReadIntervalTimeout = self::MAXDWORD;
        $timeouts->ReadTotalTimeoutMultiplier = $readMilliseconds > 0 ? self::MAXDWORD : 0;
        $timeouts->ReadTotalTimeoutConstant = $readMilliseconds;
        $timeouts->WriteTotalTimeoutMultiplier = 0;
        $timeouts->WriteTotalTimeoutConstant = self::WRITE_TIMEOUT_MS;
        if (!$this->kernel32->SetCommTimeouts($this->handle, \FFI::addr($timeouts))) {
            throw new EcrIOException('Could not set the timeouts on ' . $this->port);
        }
    }

    /** @throws EcrIOException */
    private static function kernel32(): \FFI
    {
        if (!\extension_loaded('ffi')) {
            throw new EcrIOException('USB cable on Windows needs PHP\'s FFI extension: set extension=ffi in php.ini (and ffi.enable=true under a web server)');
        }
        try {
            return \FFI::cdef(self::DEFINITIONS, 'kernel32.dll');
        } catch (\Throwable $e) {
            throw new EcrIOException('USB cable on Windows needs FFI enabled for this PHP (ffi.enable=true): ' . $e->getMessage());
        }
    }
}
