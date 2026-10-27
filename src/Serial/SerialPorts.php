<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Serial;

use AmwalPay\Ecr\Internal\NetText;

/**
 * COM ports this computer can open. Windows labels come from Device Manager,
 * so "MediaTek USB VCOM (Android) (COM8)" can be told apart from the other
 * USB serial devices. The macOS call-in twin is hidden, and COM numbers are
 * ordered numerically.
 *
 * Every method that looks at the machine has an overload-style `$ports`
 * argument, so a till (or a test) can pass a list it already has.
 */
final class SerialPorts
{
    private const ENUM_KEY = 'HKLM\\SYSTEM\\CurrentControlSet\\Enum';
    private const SERIALCOMM_KEY = 'HKLM\\HARDWARE\\DEVICEMAP\\SERIALCOMM';

    /**
     * Ports for the terminal form. `$saved` is the identity already stored.
     * `$terminalSerial` wins when it equals a plugged-in USB serial, so the row
     * follows that terminal.
     *
     * @param list<SerialPortChoice>|null $ports Null: what is plugged in now.
     */
    public static function listFor(string $saved, string $terminalSerial, ?array $ports = null): ConnectedPorts
    {
        $listed = array_values(array_filter($ports ?? self::list(), static fn ($port): bool => $port instanceof SerialPortChoice));

        $selected = NetText::trim($saved);
        $serial = NetText::trim($terminalSerial);
        $byTerminal = null;
        $bySaved = null;
        foreach ($listed as $port) {
            if ($serial !== '' && $port->deviceSerial !== '' && self::same($port->deviceSerial, $serial)) {
                $byTerminal = $port;
            }
            if ($selected !== '' && (
                self::same($port->selection(), $selected)
                || self::same($port->name, $selected)
                || self::same($port->deviceSerial, $selected)
            )) {
                $bySaved = $port;
            }
        }

        $match = $byTerminal ?? $bySaved;
        $choice = $match !== null ? $match->selection() : self::preferred($listed, $serial);
        return new ConnectedPorts($listed, $choice);
    }

    /**
     * The COM port a sale opens for `$saved`, and what to store afterwards.
     *
     * @param list<SerialPortChoice>|null $ports Null: what is plugged in now.
     */
    public static function portFor(string $saved, ?array $ports = null): SerialPortBinding
    {
        $ports ??= self::list();
        $key = NetText::trim($saved);
        $live = self::resolve($key, $ports);
        if ($live === '') {
            return new SerialPortBinding('', $key);
        }
        if (!self::isDirectPort($key)) {
            return new SerialPortBinding($live, $key);
        }
        foreach ($ports as $port) {
            if ($port instanceof SerialPortChoice && self::same($port->name, $live) && $port->deviceSerial !== '') {
                return new SerialPortBinding($live, $port->deviceSerial);
            }
        }
        return new SerialPortBinding($live, $key);
    }

    /**
     * Serial devices plugged in right now. Asked of the operating system on
     * every call. A COM port remembered from an earlier plug is not included.
     *
     * @return list<SerialPortChoice>
     */
    public static function list(): array
    {
        $live = [];
        foreach (self::presentNames() as $name) {
            $name = NetText::trim($name);
            if ($name !== '') {
                $live[strtolower($name)] = $name;
            }
        }

        $described = self::described();
        if ($described === []) {
            return self::present(array_map(static fn (string $name): SerialPortChoice => new SerialPortChoice($name, $name), array_values($live)));
        }

        $matched = [];
        $describedNames = [];
        foreach ($described as $port) {
            if ($port->name === '') {
                continue;
            }
            $describedNames[strtolower($port->name)] = true;
            if (isset($live[strtolower($port->name)])) {
                $matched[] = $port;
            }
        }
        foreach ($live as $lower => $name) {
            if (!isset($describedNames[$lower])) {
                $matched[] = new SerialPortChoice($name, $name);
            }
        }
        return self::present($matched);
    }

    /**
     * The port names the operating system lists right now, without labels:
     * `SerialPort.GetPortNames()`. Empty when they cannot be read.
     *
     * @return list<string>
     */
    public static function presentNames(): array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $names = [];
            foreach (self::registryLines([self::SERIALCOMM_KEY]) as $line) {
                if (preg_match('/^\s+\S.*\s+REG_SZ\s+(\S+)\s*$/', $line, $m) === 1) {
                    $names[] = $m[1];
                }
            }
            return $names;
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            return array_values(array_merge(glob('/dev/tty.*') ?: [], glob('/dev/cu.*') ?: []));
        }
        // Linux: a tty backed by a device, as /sys reports it.
        $names = [];
        foreach (glob('/sys/class/tty/*/device') ?: [] as $device) {
            $names[] = '/dev/' . basename(\dirname($device));
        }
        if ($names === []) {
            $names = array_merge(glob('/dev/ttyACM*') ?: [], glob('/dev/ttyUSB*') ?: []);
        }
        return array_values($names);
    }

    /**
     * The device to offer first. A terminal serial that equals a USB serial
     * wins. Otherwise the Android virtual COM ("MediaTek USB VCOM"), then the
     * first name in list order. The result is a {@see SerialPortChoice::selection()},
     * so a later sale can find the device after Windows moves its COM port.
     *
     * @param list<SerialPortChoice> $ports
     */
    public static function preferred(array $ports, string $terminalSerial = ''): string
    {
        if ($ports === []) {
            return '';
        }
        $wanted = NetText::trim($terminalSerial);
        if ($wanted !== '') {
            foreach ($ports as $port) {
                if ($port->deviceSerial !== '' && self::same($port->deviceSerial, $wanted)) {
                    return $port->selection();
                }
            }
        }
        foreach ($ports as $port) {
            if (self::isVirtualCom($port->label) || self::isVirtualCom($port->name)) {
                return $port->selection();
            }
        }
        return $ports[array_key_first($ports)]->selection();
    }

    /**
     * The COM port to open for a saved cable identity. A device serial is
     * looked up again, so a terminal that moved to another USB socket is opened
     * on the COM port Windows assigned this time. A saved COM name is opened as
     * that name until the till has learned the device serial. An unplugged
     * device serial returns empty: a stale COM number may now belong to a
     * different device.
     *
     * @param list<SerialPortChoice>|null $ports Null: what is plugged in now.
     */
    public static function resolve(string $saved, ?array $ports = null): string
    {
        $key = NetText::trim($saved);
        if ($key === '') {
            return '';
        }
        $ports ??= self::list();
        foreach ($ports as $port) {
            if ($port instanceof SerialPortChoice && $port->deviceSerial !== '' && self::same($port->deviceSerial, $key)) {
                return $port->name;
            }
        }
        if (self::isDirectPort($key)) {
            foreach ($ports as $port) {
                if ($port instanceof SerialPortChoice && self::same($port->name, $key)) {
                    return $port->name;
                }
            }
        }
        return self::isDirectPort($key) ? $key : '';
    }

    /** The USB serial of the device that currently owns this COM port, or empty. */
    public static function deviceSerialFor(string $portName): string
    {
        $name = NetText::trim($portName);
        if ($name === '') {
            return '';
        }
        foreach (self::list() as $port) {
            if (self::same($port->name, $name)) {
                return $port->deviceSerial;
            }
        }
        return '';
    }

    /** A COM name or a device path. A USB serial is neither. */
    public static function isDirectPort(string $value): bool
    {
        $key = NetText::trim($value);
        if ($key === '') {
            return false;
        }
        return str_starts_with($key, '/dev/') || self::comNumber($key) >= 0;
    }

    /**
     * Drops blank names and ports that are never a terminal, hides the macOS
     * `/dev/tty.*` twin of a `/dev/cu.*` port, and orders COM ports by number.
     *
     * @internal
     * @param iterable<SerialPortChoice|null> $ports
     * @return list<SerialPortChoice>
     */
    public static function present(iterable $ports): array
    {
        $seen = [];
        $names = [];
        foreach ($ports as $port) {
            if ($port === null) {
                continue;
            }
            $name = NetText::trim($port->name);
            if ($name === '') {
                continue;
            }
            foreach (['Bluetooth-Incoming-Port', 'debug-console', 'wlan-debug'] as $noise) {
                if (stripos($name, $noise) !== false) {
                    continue 2;
                }
            }
            $seen[] = new SerialPortChoice($name, $port->label, $port->deviceSerial);
            $names[$name] = true;
        }

        $result = [];
        foreach ($seen as $port) {
            if (str_starts_with($port->name, '/dev/tty.') && isset($names['/dev/cu.' . substr($port->name, \strlen('/dev/tty.'))])) {
                continue;
            }
            $result[] = $port;
        }

        usort($result, static fn (SerialPortChoice $left, SerialPortChoice $right): int => self::compare($left->name, $right->name));
        return $result;
    }

    private static function isVirtualCom(string $text): bool
    {
        return stripos($text, 'VCOM') !== false || stripos($text, 'MediaTek') !== false;
    }

    /**
     * Windows only: every COM port Plug and Play has a `PortName` for, with its
     * Device Manager label and the USB serial of the device instance.
     *
     * The COM number is not chosen by the MediaTek driver. Plug and Play
     * stores `PortName` on the device instance, and that instance is the USB
     * serial when the device has one. The same device in another USB socket,
     * or a COM number that is already taken, gets a different `PortName`.
     *
     * @return list<SerialPortChoice>
     */
    private static function described(): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return [];
        }
        /** @var array<string, SerialPortChoice> $found keyed by lower-case COM name */
        $found = [];
        $key = null;
        foreach (self::registryLines([self::ENUM_KEY, '/s', '/v', 'PortName']) as $line) {
            if (str_starts_with($line, 'HKEY_')) {
                $key = $line;
                continue;
            }
            if ($key === null || preg_match('/^\s+PortName\s+REG_SZ\s+(.+?)\s*$/', $line, $m) !== 1) {
                continue;
            }
            $name = trim($m[1]);
            if (self::comNumber($name) < 0 || !str_ends_with(strtolower($key), '\\device parameters')) {
                continue;
            }
            $owner = substr($key, 0, -\strlen('\\Device Parameters'));
            $marker = stripos($owner, '\\Enum\\');
            $serial = '';
            if ($marker !== false) {
                foreach (explode('\\', substr($owner, $marker + \strlen('\\Enum\\'))) as $segment) {
                    if (self::looksLikeSerial($segment)) {
                        $serial = $segment;
                    }
                }
            }
            $friendly = self::friendlyName($owner);
            $shown = $friendly === '' ? $name : self::stripCom($friendly, $name);
            $identity = $serial !== '' ? 'device ' . $serial : 'this USB socket';
            self::keep($found, $name, $name . ' · ' . $shown . ' · ' . $identity, $serial);
        }
        return array_values($found);
    }

    private static function friendlyName(string $key): string
    {
        foreach (self::registryLines([$key, '/v', 'FriendlyName']) as $line) {
            if (preg_match('/^\s+FriendlyName\s+REG_SZ\s+(.+?)\s*$/', $line, $m) === 1) {
                return trim($m[1]);
            }
        }
        return '';
    }

    /** @param array<string, SerialPortChoice> $found */
    private static function keep(array &$found, string $name, string $label, string $serial): void
    {
        $lower = strtolower($name);
        $existing = $found[$lower] ?? null;
        if ($existing === null) {
            $found[$lower] = new SerialPortChoice($name, $label, $serial);
            return;
        }
        $keepSerial = $existing->deviceSerial !== '' ? $existing->deviceSerial : $serial;
        $keepLabel = self::isVirtualCom($label) || !self::isVirtualCom($existing->label) ? $label : $existing->label;
        $found[$lower] = new SerialPortChoice($name, $keepLabel, $keepSerial);
    }

    private static function looksLikeSerial(string $instanceId): bool
    {
        if (\strlen($instanceId) < 4) {
            return false;
        }
        if (str_contains($instanceId, '&') || str_contains($instanceId, ' ')) {
            return false;
        }
        return self::comNumber($instanceId) < 0;
    }

    private static function stripCom(string $friendly, string $port): string
    {
        $suffix = ' (' . $port . ')';
        if (\strlen($friendly) >= \strlen($suffix) && strcasecmp(substr($friendly, -\strlen($suffix)), $suffix) === 0) {
            return trim(substr($friendly, 0, -\strlen($suffix)));
        }
        return $friendly;
    }

    private static function compare(string $left, string $right): int
    {
        $leftCom = self::comNumber($left);
        $rightCom = self::comNumber($right);
        if ($leftCom >= 0 && $rightCom >= 0) {
            return $leftCom <=> $rightCom;
        }
        if ($leftCom >= 0) {
            return -1;
        }
        if ($rightCom >= 0) {
            return 1;
        }
        return strcmp($left, $right);
    }

    /** The number in `COMn`, or -1. */
    private static function comNumber(string $name): int
    {
        if (\strlen($name) < 4 || strncasecmp($name, 'COM', 3) !== 0) {
            return -1;
        }
        // int.TryParse: white space and a sign around the digits are accepted.
        if (preg_match('/^[\t\n\v\f\r ]*([+-]?)0*([0-9]{1,10})[\t\n\v\f\r ]*$/', substr($name, 3), $m) !== 1) {
            return -1;
        }
        $value = (int) $m[2];
        if ($value > 2147483647) {
            return -1;
        }
        return $m[1] === '-' && $value !== 0 ? -1 : $value;
    }

    private static function same(string $left, string $right): bool
    {
        return strcasecmp($left, $right) === 0;
    }

    /**
     * Runs `reg query` with `$arguments` and returns its output lines. Empty
     * when the key is missing or `reg` cannot run: a port list is never worth
     * failing a sale over.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    private static function registryLines(array $arguments): array
    {
        if (!\function_exists('proc_open')) {
            return [];
        }
        $process = @proc_open(
            array_merge(['reg', 'query'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return [];
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !\is_string($output)) {
            return [];
        }
        // reg.exe writes in the console code page; keep only what is valid UTF-8.
        if (preg_match('//u', $output) !== 1) {
            $output = (string) preg_replace('/[\x80-\xFF]/', '?', $output);
        }
        return preg_split('/\r\n|\n|\r/', $output) ?: [];
    }
}
