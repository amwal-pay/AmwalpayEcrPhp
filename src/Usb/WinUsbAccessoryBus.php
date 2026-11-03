<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Usb;

use AmwalPay\Ecr\Channel\EcrIOException;

/**
 * Opens the terminal through WinUSB, by PHP's FFI extension. The WinUSB driver
 * must already be attached to the terminal (Amwal provides it): unlike the .NET
 * SDK this does not install one, which would need an elevation prompt that a PHP
 * process has no desktop to show.
 *
 * Needs `extension=ffi`, and `ffi.enable=true` under a web server.
 */
final class WinUsbAccessoryBus implements UsbAccessoryBus
{
    private const USB_DEVICE_INTERFACE = 'A5DCBF10-6530-11D2-901F-00C04FB951ED';
    private const WINUSB_INTERFACE = 'DEE824EF-729B-4A0E-9C14-B7117D33A817';
    private const DIGCF_PRESENT = 0x02;
    private const DIGCF_DEVICEINTERFACE = 0x10;
    private const SPDRP_HARDWAREID = 1;
    private const GENERIC_READ_WRITE = 0xC0000000;
    private const FILE_SHARE_READ_WRITE = 3;
    private const OPEN_EXISTING = 3;
    private const INVALID_HANDLE_VALUE = -1;
    private const USB_PIPE_TYPE_BULK = 2;
    private const COULD_NOT_OPEN = 'Could not open the terminal on the cable. Install the USB driver Amwal provides for this terminal, then try again.';

    private const SETUPAPI = <<<'C'
        typedef struct { uint32_t Data1; uint16_t Data2; uint16_t Data3; uint8_t Data4[8]; } GUID;
        typedef struct { uint32_t cbSize; GUID InterfaceClassGuid; uint32_t Flags; uintptr_t Reserved; } SP_DEVICE_INTERFACE_DATA;
        typedef struct { uint32_t cbSize; GUID ClassGuid; uint32_t DevInst; uintptr_t Reserved; } SP_DEVINFO_DATA;
        intptr_t SetupDiGetClassDevsA(const GUID* classGuid, const char* enumerator, intptr_t hwndParent, uint32_t flags);
        int SetupDiEnumDeviceInterfaces(intptr_t set, void* deviceInfoData, const GUID* interfaceClassGuid, uint32_t memberIndex, SP_DEVICE_INTERFACE_DATA* data);
        int SetupDiGetDeviceInterfaceDetailA(intptr_t set, SP_DEVICE_INTERFACE_DATA* data, void* detail, uint32_t detailSize, uint32_t* requiredSize, SP_DEVINFO_DATA* info);
        int SetupDiGetDeviceRegistryPropertyA(intptr_t set, SP_DEVINFO_DATA* info, uint32_t property, uint32_t* dataType, void* buffer, uint32_t bufferSize, uint32_t* requiredSize);
        int SetupDiDestroyDeviceInfoList(intptr_t set);
        C;

    private const KERNEL32 = <<<'C'
        intptr_t CreateFileA(const char* name, uint32_t access, uint32_t share, void* security, uint32_t disposition, uint32_t flags, intptr_t templateFile);
        int CloseHandle(intptr_t handle);
        C;

    private const WINUSB = <<<'C'
        typedef struct { uint8_t bLength; uint8_t bDescriptorType; uint8_t bInterfaceNumber; uint8_t bAlternateSetting; uint8_t bNumEndpoints; uint8_t bInterfaceClass; uint8_t bInterfaceSubClass; uint8_t bInterfaceProtocol; uint8_t iInterface; } USB_INTERFACE_DESCRIPTOR;
        typedef struct { uint32_t PipeType; uint8_t PipeId; uint16_t MaximumPacketSize; uint8_t Interval; } WINUSB_PIPE_INFORMATION;
        typedef struct { uint8_t RequestType; uint8_t Request; uint16_t Value; uint16_t Index; uint16_t Length; } WINUSB_SETUP_PACKET;
        int WinUsb_Initialize(intptr_t deviceHandle, intptr_t* interfaceHandle);
        int WinUsb_Free(intptr_t interfaceHandle);
        int WinUsb_QueryInterfaceSettings(intptr_t interfaceHandle, uint8_t alternate, USB_INTERFACE_DESCRIPTOR* descriptor);
        int WinUsb_QueryPipe(intptr_t interfaceHandle, uint8_t alternate, uint8_t pipeIndex, WINUSB_PIPE_INFORMATION* information);
        int WinUsb_SetPipePolicy(intptr_t interfaceHandle, uint8_t pipeId, uint32_t policyType, uint32_t valueLength, void* value);
        int WinUsb_ControlTransfer(intptr_t interfaceHandle, WINUSB_SETUP_PACKET setup, void* buffer, uint32_t bufferLength, uint32_t* transferred, void* overlapped);
        int WinUsb_ReadPipe(intptr_t interfaceHandle, uint8_t pipeId, void* buffer, uint32_t bufferLength, uint32_t* transferred, void* overlapped);
        int WinUsb_WritePipe(intptr_t interfaceHandle, uint8_t pipeId, const void* buffer, uint32_t bufferLength, uint32_t* transferred, void* overlapped);
        C;

    private ?\FFI $setupapi = null;
    private ?\FFI $kernel32 = null;
    private ?\FFI $winusb = null;
    private ?string $unavailable = null;

    public function __construct()
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->unavailable = 'USB cable runs on Windows. This machine cannot open the terminal over a cable.';
            return;
        }
        if (!\extension_loaded('ffi')) {
            $this->unavailable = "USB cable on Windows needs PHP's FFI extension: set extension=ffi in php.ini (and ffi.enable=true under a web server)";
            return;
        }
        try {
            $this->setupapi = \FFI::cdef(self::SETUPAPI, 'setupapi.dll');
            $this->kernel32 = \FFI::cdef(self::KERNEL32, 'kernel32.dll');
            $this->winusb = \FFI::cdef(self::WINUSB, 'winusb.dll');
        } catch (\Throwable $e) {
            $this->unavailable = 'USB cable on Windows needs FFI enabled for this PHP (ffi.enable=true): ' . $e->getMessage();
        }
    }

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
        if ($this->unavailable !== null) {
            return [];
        }
        $plugs = [];
        $seen = [];
        $this->collect(self::USB_DEVICE_INTERFACE, $plugs, $seen);
        $this->collect(self::WINUSB_INTERFACE, $plugs, $seen);
        return $plugs;
    }

    public function open(UsbPlug $plug): UsbPipe
    {
        if ($this->unavailable !== null) {
            throw new EcrIOException($this->unavailable);
        }
        $handle = $this->kernel32->CreateFileA($plug->id, self::GENERIC_READ_WRITE, self::FILE_SHARE_READ_WRITE, null, self::OPEN_EXISTING, 0, 0);
        if ($handle === self::INVALID_HANDLE_VALUE || $handle === 0) {
            throw new EcrIOException(self::COULD_NOT_OPEN);
        }
        $out = $this->winusb->new('intptr_t');
        if (!$this->winusb->WinUsb_Initialize($handle, \FFI::addr($out))) {
            $this->kernel32->CloseHandle($handle);
            throw new EcrIOException(self::COULD_NOT_OPEN);
        }
        $iface = $out->cdata;
        [$input, $output] = $this->bulkPipes($iface);
        return new WinUsbPipe($this->kernel32, $this->winusb, $handle, $iface, $input, $output);
    }

    /** The vendor and product ids in a hardware id such as `USB\VID_18D1&PID_2D00&MI_00`. @return array{0: int, 1: int}|null */
    public static function vidPid(string $hardwareId): ?array
    {
        if (preg_match('/VID_([0-9A-Fa-f]{4}).*PID_([0-9A-Fa-f]{4})/s', $hardwareId, $match) !== 1) {
            return null;
        }
        return [(int) hexdec($match[1]), (int) hexdec($match[2])];
    }

    /**
     * @param list<UsbPlug> $plugs
     * @param array<string, true> $seen
     */
    private function collect(string $interface, array &$plugs, array &$seen): void
    {
        $setup = $this->setupapi;
        $guid = $this->guid($interface);
        $set = $setup->SetupDiGetClassDevsA(\FFI::addr($guid), null, 0, self::DIGCF_PRESENT | self::DIGCF_DEVICEINTERFACE);
        if ($set === self::INVALID_HANDLE_VALUE) {
            return;
        }
        try {
            $data = $setup->new('SP_DEVICE_INTERFACE_DATA');
            $data->cbSize = \FFI::sizeof($data);
            for ($index = 0; $setup->SetupDiEnumDeviceInterfaces($set, null, \FFI::addr($guid), $index, \FFI::addr($data)); $index++) {
                [$path, $hardwareId] = $this->devicePath($set, $data);
                if ($path === null || isset($seen[strtolower($path)])) {
                    continue;
                }
                $seen[strtolower($path)] = true;
                $ids = self::vidPid($hardwareId);
                if ($ids !== null) {
                    $plugs[] = new UsbPlug($path, $ids[0], $ids[1]);
                }
            }
        } finally {
            $setup->SetupDiDestroyDeviceInfoList($set);
        }
    }

    /** @return array{0: ?string, 1: string} the device path and its hardware ids */
    private function devicePath(int $set, \FFI\CData $data): array
    {
        $setup = $this->setupapi;
        $required = $setup->new('uint32_t');
        $setup->SetupDiGetDeviceInterfaceDetailA($set, \FFI::addr($data), null, 0, \FFI::addr($required), null);
        $size = $required->cdata;
        if ($size <= 4) {
            return [null, ''];
        }
        $detail = $setup->new('uint8_t[' . $size . ']');
        // SP_DEVICE_INTERFACE_DETAIL_DATA_A: a DWORD cbSize, then the path. The
        // cbSize names the fixed part only: 8 with 64-bit alignment, 5 packed on 32.
        \FFI::memcpy($detail, pack('V', \PHP_INT_SIZE === 8 ? 8 : 5), 4);
        $info = $setup->new('SP_DEVINFO_DATA');
        $info->cbSize = \FFI::sizeof($info);
        if (!$setup->SetupDiGetDeviceInterfaceDetailA($set, \FFI::addr($data), $detail, $size, null, \FFI::addr($info))) {
            return [null, ''];
        }
        $path = (string) strstr(substr(\FFI::string($detail, $size), 4) . "\0", "\0", true);

        $buffer = $setup->new('uint8_t[1024]');
        $length = $setup->new('uint32_t');
        $ids = $setup->SetupDiGetDeviceRegistryPropertyA($set, \FFI::addr($info), self::SPDRP_HARDWAREID, null, $buffer, 1024, \FFI::addr($length))
            ? rtrim(\FFI::string($buffer, min($length->cdata, 1024)), "\0")
            : '';
        return [$path, $ids];
    }

    /** @return array{0: int, 1: int} the bulk IN and OUT pipe ids; the usual pair when the device does not say */
    private function bulkPipes(int $iface): array
    {
        $input = 0x81;
        $output = 0x01;
        $settings = $this->winusb->new('USB_INTERFACE_DESCRIPTOR');
        if ($this->winusb->WinUsb_QueryInterfaceSettings($iface, 0, \FFI::addr($settings))) {
            for ($index = 0; $index < $settings->bNumEndpoints; $index++) {
                $pipe = $this->winusb->new('WINUSB_PIPE_INFORMATION');
                if (!$this->winusb->WinUsb_QueryPipe($iface, 0, $index, \FFI::addr($pipe)) || $pipe->PipeType !== self::USB_PIPE_TYPE_BULK) {
                    continue;
                }
                if (($pipe->PipeId & 0x80) !== 0) {
                    $input = $pipe->PipeId;
                } else {
                    $output = $pipe->PipeId;
                }
            }
        }
        return [$input, $output];
    }

    private function guid(string $text): \FFI\CData
    {
        $hex = str_replace('-', '', $text);
        $guid = $this->setupapi->new('GUID');
        $guid->Data1 = (int) hexdec(substr($hex, 0, 8));
        $guid->Data2 = (int) hexdec(substr($hex, 8, 4));
        $guid->Data3 = (int) hexdec(substr($hex, 12, 4));
        for ($i = 0; $i < 8; $i++) {
            $guid->Data4[$i] = (int) hexdec(substr($hex, 16 + $i * 2, 2));
        }
        return $guid;
    }
}
