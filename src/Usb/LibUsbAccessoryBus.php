<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Usb;

use AmwalPay\Ecr\Channel\EcrIOException;

/**
 * Opens the terminal through libusb on macOS and Linux, by PHP's FFI extension.
 * The library is found at run time, so a till that never uses the cable does
 * not need it installed.
 */
final class LibUsbAccessoryBus implements UsbAccessoryBus
{
    private const ERROR_ACCESS = -3;
    private const ERROR_NO_DEVICE = -4;

    private const DEFINITIONS = <<<'C'
        typedef struct {
            uint8_t bLength; uint8_t bDescriptorType; uint16_t bcdUSB;
            uint8_t bDeviceClass; uint8_t bDeviceSubClass; uint8_t bDeviceProtocol; uint8_t bMaxPacketSize0;
            uint16_t idVendor; uint16_t idProduct; uint16_t bcdDevice;
            uint8_t iManufacturer; uint8_t iProduct; uint8_t iSerialNumber; uint8_t bNumConfigurations;
        } libusb_device_descriptor;
        int libusb_init(intptr_t* context);
        const char* libusb_strerror(int code);
        intptr_t libusb_get_device_list(intptr_t context, intptr_t** list);
        void libusb_free_device_list(intptr_t* list, int unrefDevices);
        int libusb_get_device_descriptor(intptr_t device, libusb_device_descriptor* descriptor);
        uint8_t libusb_get_bus_number(intptr_t device);
        uint8_t libusb_get_device_address(intptr_t device);
        int libusb_open(intptr_t device, intptr_t* handle);
        void libusb_close(intptr_t handle);
        int libusb_claim_interface(intptr_t handle, int interfaceNumber);
        int libusb_release_interface(intptr_t handle, int interfaceNumber);
        int libusb_get_configuration(intptr_t handle, int* configuration);
        int libusb_set_configuration(intptr_t handle, int configuration);
        int libusb_set_auto_detach_kernel_driver(intptr_t handle, int enable);
        int libusb_control_transfer(intptr_t handle, uint8_t requestType, uint8_t request, uint16_t value, uint16_t index, void* data, uint16_t length, unsigned int timeout);
        int libusb_bulk_transfer(intptr_t handle, uint8_t endpoint, void* data, int length, int* transferred, unsigned int timeout);
        C;

    private static ?\FFI $api = null;
    private static int $context = 0;
    private static ?string $loadError = null;

    public function __construct()
    {
        self::ensureLoaded();
    }

    public function unavailable(): ?string
    {
        return self::$loadError;
    }

    public function nothingPluggedIn(): string
    {
        return 'Nothing is plugged in. Connect the terminal with a USB cable.';
    }

    public function list(): array
    {
        if (self::$api === null) {
            return [];
        }
        $plugs = [];
        $api = self::$api;
        $this->withDevices(static function (int $device) use ($api, &$plugs): bool {
            $descriptor = $api->new('libusb_device_descriptor');
            if ($api->libusb_get_device_descriptor($device, \FFI::addr($descriptor)) !== 0) {
                return false;
            }
            $vendor = $descriptor->idVendor;
            $product = $descriptor->idProduct;
            $id = self::plugId($device);
            if (self::isAccessory($vendor, $product)) {
                $plugs[] = new UsbPlug($id, $vendor, $product);
            } elseif (self::mightBeTerminal($vendor, $descriptor->bDeviceClass) && self::mayBeAndroid($device)) {
                $plugs[] = new UsbPlug($id, $vendor, $product);
            }
            return false;
        });
        return $plugs;
    }

    public function open(UsbPlug $plug): UsbPipe
    {
        if (self::$api === null) {
            throw new EcrIOException(self::$loadError ?? $this->nothingPluggedIn());
        }
        $api = self::$api;
        $handle = 0;
        $error = self::ERROR_NO_DEVICE;
        $this->withDevices(static function (int $device) use ($api, $plug, &$handle, &$error): bool {
            if (self::plugId($device) !== $plug->id) {
                return false;
            }
            $out = $api->new('intptr_t');
            $error = $api->libusb_open($device, \FFI::addr($out));
            $handle = $out->cdata;
            return true;
        });
        if ($error !== 0 || $handle === 0) {
            throw new EcrIOException(self::couldNotOpen($error));
        }
        if (!self::isAccessory($plug->vendorId, $plug->productId)) {
            return new LibUsbPipe($api, $handle, false, 0x81, 0x01);
        }

        $api->libusb_set_auto_detach_kernel_driver($handle, 1);
        $configuration = $api->new('int');
        if ($api->libusb_get_configuration($handle, \FFI::addr($configuration)) === 0 && $configuration->cdata === 0) {
            $api->libusb_set_configuration($handle, 1);
        }
        $claimed = $api->libusb_claim_interface($handle, 0);
        if ($claimed !== 0) {
            $api->libusb_close($handle);
            throw new EcrIOException('Could not claim the terminal on the cable (' . self::describe($claimed) . ').');
        }
        [$input, $output] = self::bulkEndpoints(self::readConfig($handle) ?? '');
        return new LibUsbPipe($api, $handle, true, $input, $output);
    }

    /**
     * A terminal that has not switched yet still looks like an ordinary Android
     * device. Its accessory interface is not there yet; the ADB interface is
     * (class 255, subclass 66). USB ethernet uses class 255 as well, with a
     * different subclass, and must not be asked to switch.
     */
    public static function hasAdbInterface(string $config): bool
    {
        $offset = 0;
        $size = \strlen($config);
        while ($offset + 1 < $size) {
            $length = \ord($config[$offset]);
            if ($length < 2 || $offset + $length > $size) {
                break;
            }
            if (\ord($config[$offset + 1]) === 4 && $length >= 7
                && \ord($config[$offset + 5]) === 0xFF && \ord($config[$offset + 6]) === 0x42) {
                return true;
            }
            $offset += $length;
        }
        return false;
    }

    /**
     * The bulk IN and OUT endpoints of interface 0, or the usual pair when a
     * configuration descriptor does not say.
     *
     * @return array{0: int, 1: int}
     */
    public static function bulkEndpoints(string $config): array
    {
        $input = 0x81;
        $output = 0x01;
        $offset = 0;
        $interface = -1;
        $size = \strlen($config);
        while ($offset + 1 < $size) {
            $length = \ord($config[$offset]);
            if ($length < 2 || $offset + $length > $size) {
                break;
            }
            $type = \ord($config[$offset + 1]);
            if ($type === 4 && $length >= 5) {
                $interface = \ord($config[$offset + 2]);
            }
            if ($type === 5 && $length >= 5 && $interface === 0 && (\ord($config[$offset + 3]) & 0x03) === 2) {
                $address = \ord($config[$offset + 2]);
                if (($address & 0x80) !== 0) {
                    $input = $address;
                } else {
                    $output = $address;
                }
            }
            $offset += $length;
        }
        return [$input, $output];
    }

    /** Calls `$visit` with each device until it returns true. @param callable(int): bool $visit */
    private function withDevices(callable $visit): void
    {
        $api = self::$api;
        $list = $api->new('intptr_t*');
        $count = $api->libusb_get_device_list(self::$context, \FFI::addr($list));
        if ($count < 0) {
            throw new EcrIOException('Could not list USB devices (' . self::describe($count) . ').');
        }
        try {
            for ($i = 0; $i < $count; $i++) {
                if ($visit($list[$i])) {
                    return;
                }
            }
        } finally {
            $api->libusb_free_device_list($list, 1);
        }
    }

    /** Whether to offer this device: an Android one, or one that cannot be asked (it is then for open() to say why). */
    private static function mayBeAndroid(int $device): bool
    {
        $api = self::$api;
        $out = $api->new('intptr_t');
        if ($api->libusb_open($device, \FFI::addr($out)) !== 0 || $out->cdata === 0) {
            return true;
        }
        $handle = $out->cdata;
        try {
            $config = self::readConfig($handle);
            return $config === null || self::hasAdbInterface($config);
        } finally {
            $api->libusb_close($handle);
        }
    }

    /** GET_DESCRIPTOR(configuration 0), or null when the device will not say. */
    private static function readConfig(int $handle): ?string
    {
        $header = self::transfer($handle, 4);
        if ($header === null || \strlen($header) < 4) {
            return null;
        }
        $total = \ord($header[2]) | (\ord($header[3]) << 8);
        if ($total < 9 || $total > 4096) {
            return null;
        }
        $config = self::transfer($handle, $total);
        return $config !== null && \strlen($config) >= 9 ? $config : null;
    }

    private static function transfer(int $handle, int $length): ?string
    {
        $buffer = self::$api->new('uint8_t[' . $length . ']');
        $read = self::$api->libusb_control_transfer($handle, 0x80, 6, 0x0200, 0, $buffer, $length, 1000);
        return $read < 0 ? null : \FFI::string($buffer, $read);
    }

    private static function plugId(int $device): string
    {
        return self::$api->libusb_get_bus_number($device) . ':' . self::$api->libusb_get_device_address($device);
    }

    private static function mightBeTerminal(int $vendor, int $class): bool
    {
        if ($vendor === 0x05AC || $class === 9) {
            return false;
        }
        return $class === 0 || $class === 0xFF || $class === 0xEF;
    }

    private static function isAccessory(int $vendor, int $product): bool
    {
        return $vendor === 0x18D1 && \in_array($product, [0x2D00, 0x2D01, 0x2D04, 0x2D05], true);
    }

    private static function couldNotOpen(int $error): string
    {
        return match ($error) {
            self::ERROR_ACCESS => 'Could not open the terminal on the cable. This machine refused access to the USB device.',
            self::ERROR_NO_DEVICE => 'The terminal is no longer on the cable.',
            default => 'Could not open the terminal on the cable (' . self::describe($error) . ').',
        };
    }

    private static function describe(int $code): string
    {
        $text = self::$api?->libusb_strerror($code);
        return \is_string($text) && $text !== '' ? $text : (string) $code;
    }

    private static function ensureLoaded(): void
    {
        if (self::$api !== null || self::$loadError !== null) {
            return;
        }
        if (!\extension_loaded('ffi')) {
            self::$loadError = "USB cable needs PHP's FFI extension: set extension=ffi in php.ini (and ffi.enable=true under a web server)";
            return;
        }
        $names = \PHP_OS_FAMILY === 'Darwin'
            ? ['/opt/homebrew/lib/libusb-1.0.dylib', '/usr/local/lib/libusb-1.0.dylib', 'libusb-1.0.0.dylib']
            : ['libusb-1.0.so.0', 'libusb-1.0.so'];
        $api = null;
        foreach ($names as $name) {
            try {
                $api = \FFI::cdef(self::DEFINITIONS, $name);
                break;
            } catch (\Throwable) {
            }
        }
        if ($api === null) {
            self::$loadError = \PHP_OS_FAMILY === 'Darwin'
                ? 'USB cable needs libusb. Install it with Homebrew (brew install libusb) and start the till again.'
                : 'USB cable needs libusb. Install libusb and start the till again.';
            return;
        }
        $context = $api->new('intptr_t');
        $started = $api->libusb_init(\FFI::addr($context));
        if ($started !== 0) {
            self::$loadError = 'USB cable could not open the USB bus (' . $api->libusb_strerror($started) . ').';
            return;
        }
        self::$context = $context->cdata;
        self::$api = $api;
    }
}
