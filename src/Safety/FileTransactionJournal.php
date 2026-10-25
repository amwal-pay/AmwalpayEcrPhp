<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Safety;

/**
 * The journal as one JSON file per terminal, in a private folder.
 *
 * Every change is a read-modify-write under an exclusive lock, written to a
 * temporary file and renamed into place, so two PHP processes never interleave
 * and a crash never leaves half a file. Settled entries older than the
 * retention period are dropped; unresolved ones are kept until they are settled.
 */
final class FileTransactionJournal implements TransactionJournal
{
    public function __construct(
        private readonly string $directory,
        private readonly int $retentionDays = 90,
    ) {
    }

    public function find(string $terminalKey, string $reference): ?JournalEntry
    {
        $entries = $this->read($terminalKey);
        return isset($entries[$reference]) ? JournalEntry::fromArray($entries[$reference]) : null;
    }

    public function unresolved(string $terminalKey): array
    {
        $open = [];
        foreach ($this->read($terminalKey) as $data) {
            $entry = JournalEntry::fromArray($data);
            if ($entry->isUnresolved()) {
                $open[] = $entry;
            }
        }
        usort($open, static fn (JournalEntry $a, JournalEntry $b): int => strcmp($a->createdAt, $b->createdAt));
        return $open;
    }

    public function put(string $terminalKey, JournalEntry $entry): void
    {
        $this->update($terminalKey, static function (array $entries) use ($entry): array {
            $entries[$entry->reference] = $entry->toArray();
            return $entries;
        });
    }

    public function remove(string $terminalKey, string $reference): void
    {
        $this->update($terminalKey, static function (array $entries) use ($reference): array {
            unset($entries[$reference]);
            return $entries;
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function read(string $terminalKey): array
    {
        $path = $this->path($terminalKey);
        if (!is_file($path)) {
            return [];
        }
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException('The ECR transaction journal could not be read: ' . $path);
        }
        if ($text === '') {
            return [];
        }
        $data = json_decode($text, true);
        if (!\is_array($data) || !isset($data['entries']) || !\is_array($data['entries'])) {
            // Fail closed: a journal that cannot be read cannot prove a reference is unused.
            throw new \RuntimeException('The ECR transaction journal is corrupt and must be checked by hand: ' . $path);
        }
        /** @var array<string, array<string, mixed>> */
        return $data['entries'];
    }

    /** @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $change */
    private function update(string $terminalKey, callable $change): void
    {
        $this->ensureDirectory();
        $path = $this->path($terminalKey);
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('The ECR transaction journal cannot be written: ' . $this->directory);
        }
        try {
            if (!flock($lock, \LOCK_EX)) {
                throw new \RuntimeException('The ECR transaction journal could not be locked: ' . $path);
            }
            $entries = $this->prune($change($this->read($terminalKey)));
            // An object even when every reference looks like a list index.
            $json = json_encode(['terminal' => $terminalKey, 'entries' => (object) $entries],\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temp, $json, \LOCK_EX) === false || !@rename($temp, $path)) {
                @unlink($temp);
                throw new \RuntimeException('The ECR transaction journal could not be saved: ' . $path);
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function prune(array $entries): array
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $this->retentionDays * 86400);
        foreach ($entries as $reference => $data) {
            $entry = JournalEntry::fromArray($data);
            if (!$entry->isUnresolved() && $entry->updatedAt !== '' && strcmp($entry->updatedAt, $cutoff) < 0) {
                unset($entries[$reference]);
            }
        }
        return $entries;
    }

    private function path(string $terminalKey): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . 'journal-' . substr(hash('sha256', $terminalKey), 0, 32) . '.json';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The ECR state folder cannot be created: ' . $this->directory);
        }
    }
}
