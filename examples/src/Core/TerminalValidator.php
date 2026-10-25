<?php

declare(strict_types=1);

namespace AmwalExample\Core;

/** The terminal edit form's rules and messages, the same as the Android and Windows samples'. */
final class TerminalValidator
{
    public const DEFAULT_PORT = '9100';

    /** Dotted quad, each octet 0-255. */
    private const IPV4 = '/^((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/';

    /** @return array<string, string> field => message; empty when valid */
    public static function validate(
        string $name,
        string $serial,
        EcrMode $mode,
        string $ip,
        string $port,
        string $terminalId,
        string $merchantId,
    ): array {
        $errors = [];
        if (trim($name) === '') {
            $errors['name'] = 'Enter a terminal name';
        }
        // The serial is the terminal's identity, so it is required for every mode.
        if (trim($serial) === '') {
            $errors['serial'] = 'Enter the terminal serial number';
        }
        if ($mode->isIpBased()) {
            if (trim($ip) === '') {
                $errors['ip'] = 'Enter the terminal IP address';
            } elseif (preg_match(self::IPV4, trim($ip)) !== 1) {
                $errors['ip'] = 'Enter a valid IPv4 address, for example 192.168.1.50';
            }
            // Optional: blank means the terminals' standard port, 9100.
            if (trim($port) !== '' && !(self::isInt($port) && (int) $port >= 1 && (int) $port <= 65535)) {
                $errors['port'] = 'Port must be between 1 and 65535';
            }
        }
        if ($mode === EcrMode::WebService) {
            if (trim($terminalId) === '') {
                $errors['terminalId'] = 'Enter the backend terminal ID';
            } elseif (!self::isNumeric($terminalId)) {
                $errors['terminalId'] = 'The terminal ID must be numeric';
            }
            if (trim($merchantId) === '') {
                $errors['merchantId'] = 'Enter the merchant ID';
            } elseif (!self::isNumeric($merchantId)) {
                $errors['merchantId'] = 'The merchant ID must be numeric';
            }
        }
        return $errors;
    }

    public static function toTerminal(
        string $name,
        string $serial,
        EcrMode $mode,
        string $ip,
        string $port,
        string $terminalId,
        string $merchantId,
    ): Terminal {
        return new Terminal(
            serialNumber: trim($serial),
            name: trim($name),
            ecrMode: $mode->value,
            ipAddress: trim($ip),
            port: $mode->isIpBased() ? (self::isInt($port) ? (int) $port : (int) self::DEFAULT_PORT) : 0,
            terminalId: trim($terminalId),
            merchantId: trim($merchantId),
        );
    }

    /** The SDK reads both IDs as 64-bit integers, so that is the rule here. */
    private static function isNumeric(string $value): bool
    {
        $v = trim($value);
        return preg_match('/^[0-9]{1,19}$/', $v) === 1 && filter_var(ltrim($v, '0') ?: '0', \FILTER_VALIDATE_INT) !== false;
    }

    private static function isInt(string $value): bool
    {
        return preg_match('/^\s*-?[0-9]{1,9}\s*$/', $value) === 1;
    }
}
