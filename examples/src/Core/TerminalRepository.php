<?php

declare(strict_types=1);

namespace AmwalExample\Core;

/**
 * All terminal persistence: a JSON file keyed by serial number, the same shape
 * as the Windows samples' terminals.json.
 *
 * The file is tiny, so every call reads and writes it whole under a lock. A
 * write goes to a temp file that then replaces the original, so a crash
 * mid-save leaves the previous list rather than half of a new one.
 */
final class TerminalRepository
{
    /** Set when the file existed but could not be read; it was set aside, not deleted. */
    public ?string $loadWarning = null;

    /** @var list<Terminal> */
    private array $terminals = [];

    public function __construct(private readonly string $path)
    {
        $this->load();
    }

    /** @return list<Terminal> ordered by name (case-insensitive), then serial */
    public function getAll(): array
    {
        $this->load();
        return self::sorted($this->terminals);
    }

    public function findBySerial(string $serialNumber): ?Terminal
    {
        $this->load();
        foreach ($this->terminals as $terminal) {
            if ($terminal->serialNumber === $serialNumber) {
                return $terminal;
            }
        }
        return null;
    }

    /**
     * Inserts a new terminal, or updates the one identified by `$originalSerial`.
     * Changing the serial of an existing terminal replaces the entry.
     *
     * @return string|null null when saved, otherwise why not
     */
    public function save(Terminal $terminal, ?string $originalSerial): ?string
    {
        return $this->locked(function () use ($terminal, $originalSerial): ?string {
            $this->load();
            $serialChanged = $originalSerial !== $terminal->serialNumber;
            if ($serialChanged && $this->findLoaded($terminal->serialNumber) !== null) {
                return 'A terminal with this serial number already exists';
            }
            $next = array_values(array_filter(
                $this->terminals,
                static fn (Terminal $t): bool => $originalSerial === null || $t->serialNumber !== $originalSerial,
            ));
            $next[] = $terminal;
            $this->write($next);
            $this->terminals = $next;
            return null;
        });
    }

    /** @return string|null null when deleted, otherwise why not */
    public function delete(string $serialNumber): ?string
    {
        return $this->locked(function () use ($serialNumber): ?string {
            $this->load();
            $next = array_values(array_filter($this->terminals, static fn (Terminal $t): bool => $t->serialNumber !== $serialNumber));
            $this->write($next);
            $this->terminals = $next;
            return null;
        });
    }

    private function findLoaded(string $serial): ?Terminal
    {
        foreach ($this->terminals as $terminal) {
            if ($terminal->serialNumber === $serial) {
                return $terminal;
            }
        }
        return null;
    }

    /**
     * @param list<Terminal> $terminals
     * @return list<Terminal>
     */
    private static function sorted(array $terminals): array
    {
        usort($terminals, static fn (Terminal $a, Terminal $b): int => strcasecmp($a->name, $b->name) ?: strcmp($a->serialNumber, $b->serialNumber));
        return $terminals;
    }

    private function load(): void
    {
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            $this->terminals = [];
            return;
        }
        $text = @file_get_contents($this->path);
        $data = $text === false ? null : json_decode($text, true);
        if (!\is_array($data) || !isset($data['terminals']) || !\is_array($data['terminals'])) {
            // Never silently overwrite what could not be read: keep it beside the
            // new file so the operator can recover the entries by hand.
            $aside = $this->path . '.unreadable';
            if (!@copy($this->path, $aside)) {
                $aside = '(could not be copied)';
            }
            $this->loadWarning = 'The saved terminal list could not be read (' . (json_last_error_msg() ?: 'unreadable') . '). A copy was kept at ' . $aside . '.';
            $this->terminals = [];
            return;
        }
        $seen = [];
        $terminals = [];
        foreach ($data['terminals'] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $terminal = Terminal::fromArray($row);
            // Keyed by serial: a hand-edited duplicate keeps the first.
            if ($terminal->serialNumber === '' || isset($seen[$terminal->serialNumber])) {
                continue;
            }
            $seen[$terminal->serialNumber] = true;
            $terminals[] = $terminal;
        }
        $this->terminals = $terminals;
    }

    /** @param list<Terminal> $terminals */
    private function write(array $terminals): void
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create ' . $directory);
        }
        $json = json_encode(['terminals' => array_map(static fn (Terminal $t): array => $t->toArray(), self::sorted($terminals))], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $temp = $this->path . '.tmp';
        if (@file_put_contents($temp, $json) === false || !@rename($temp, $this->path)) {
            @unlink($temp);
            throw new \RuntimeException('Could not save terminal');
        }
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T|string
     */
    private function locked(callable $work): mixed
    {
        try {
            $directory = \dirname($this->path);
            if (!is_dir($directory)) {
                @mkdir($directory, 0700, true);
            }
            $lock = @fopen($this->path . '.lock', 'c');
            if ($lock === false) {
                return 'Could not save terminal: the data folder is not writable';
            }
            flock($lock, \LOCK_EX);
            try {
                return $work();
            } finally {
                flock($lock, \LOCK_UN);
                fclose($lock);
            }
        } catch (\RuntimeException | \JsonException $e) {
            return $e->getMessage() !== '' ? $e->getMessage() : 'Could not save terminal';
        }
    }
}
