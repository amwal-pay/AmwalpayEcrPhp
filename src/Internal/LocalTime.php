<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

/**
 * The machine's local time, as the Windows SDK reads it with `DateTime.Now`.
 *
 * PHP does not use the operating system's time zone: it uses `date.timezone`
 * from php.ini, which is often UTC or whatever the installer shipped (XAMPP
 * ships Europe/Berlin). A `transactionDateTime` stamped in that zone is hours
 * off the terminal's clock, and the terminal refuses it as outside the accepted
 * window. So the zone is read from the operating system, as .NET does, and
 * PHP's default is only the last resort.
 *
 * @internal
 */
final class LocalTime
{
    private static ?\DateTimeZone $machineZone = null;

    /**
     * `yyyyMMddHHmmss`, the format the Windows SDK writes `DateTime.Now` in.
     * `$timezone` overrides the machine's zone when the till sets one.
     */
    public static function stamp(?string $timezone = null): string
    {
        $zone = $timezone !== null ? new \DateTimeZone($timezone) : self::zone();
        return (new \DateTimeImmutable('now', $zone))->format('YmdHis');
    }

    /** The operating system's time zone, read once per process. */
    public static function zone(): \DateTimeZone
    {
        return self::$machineZone ??= self::detect() ?? new \DateTimeZone(date_default_timezone_get());
    }

    private static function detect(): ?\DateTimeZone
    {
        return \PHP_OS_FAMILY === 'Windows' ? self::windowsZone() : self::unixZone();
    }

    private static function windowsZone(): ?\DateTimeZone
    {
        // "Pakistan Standard Time", "Arabian Standard Time", ...: the Windows ID.
        $windowsId = self::run('tzutil /g');
        if ($windowsId !== null && class_exists(\IntlTimeZone::class)) {
            $iana = \IntlTimeZone::getIDForWindowsID($windowsId);
            if (\is_string($iana) && ($zone = self::named($iana)) !== null) {
                return $zone;
            }
        }
        // Without intl: the current offset, which is what the stamp needs.
        $minutes = self::run('powershell -NoProfile -NonInteractive -Command "[int][TimeZoneInfo]::Local.GetUtcOffset([DateTime]::Now).TotalMinutes"');
        if ($minutes !== null && preg_match('/^-?\d{1,4}$/', $minutes) === 1) {
            $m = (int) $minutes;
            return new \DateTimeZone(sprintf('%s%02d:%02d', $m < 0 ? '-' : '+', intdiv(abs($m), 60), abs($m) % 60));
        }
        return null;
    }

    private static function unixZone(): ?\DateTimeZone
    {
        $tz = getenv('TZ');
        if (\is_string($tz) && ($zone = self::named(ltrim($tz, ':'))) !== null) {
            return $zone;
        }
        $link = @readlink('/etc/localtime');
        if (\is_string($link) && ($at = strpos($link, 'zoneinfo/')) !== false
            && ($zone = self::named(substr($link, $at + 9))) !== null) {
            return $zone;
        }
        $name = @file_get_contents('/etc/timezone');
        if (\is_string($name) && ($zone = self::named(trim($name))) !== null) {
            return $zone;
        }
        return null;
    }

    private static function named(string $name): ?\DateTimeZone
    {
        if ($name === '' || !\in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            return null;
        }
        return new \DateTimeZone($name);
    }

    private static function run(string $command): ?string
    {
        if (!\function_exists('shell_exec')) {
            return null;
        }
        $out = @shell_exec($command . ' 2>' . (\PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
        return \is_string($out) && trim($out) !== '' ? trim($out) : null;
    }
}
