<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Safety;

/**
 * Remembers every merchant reference sent to a terminal and what became of it,
 * across requests and processes. Implement it over your database to share the
 * guard between servers; {@see FileTransactionJournal} is the default.
 */
interface TransactionJournal
{
    public function find(string $terminalKey, string $reference): ?JournalEntry;

    /** @return list<JournalEntry> Entries whose outcome is still unknown, oldest first. */
    public function unresolved(string $terminalKey): array;

    /** Inserts or replaces the entry for `$entry->reference`. */
    public function put(string $terminalKey, JournalEntry $entry): void;

    public function remove(string $terminalKey, string $reference): void;
}
