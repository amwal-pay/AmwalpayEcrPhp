<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Safety;

/**
 * One sale, void or refund the guard sent, as the journal remembers it. Holds
 * the reference, type, amount and outcome; never card data or keys.
 */
final class JournalEntry
{
    /** Recorded before the request left; the answer has not been read yet. */
    public const PENDING = 'pending';
    /** The request went out and the answer did not settle it. Inquire by reference. */
    public const UNKNOWN = 'unknown';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    /** Settled by hand with {@see FinancialGuard::markResolved()} after confirming on the terminal or with Amwal. */
    public const RESOLVED = 'resolved';

    public function __construct(
        public readonly string $reference,
        public readonly string $type,
        public readonly string $state,
        public readonly string $amountMinorUnits,
        public readonly string $currencyCode,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $status = '',
        public readonly string $failure = '',
        public readonly string $note = '',
    ) {
    }

    /** Whether the outcome is still unknown: the terminal must be asked before any new money moves. */
    public function isUnresolved(): bool
    {
        return $this->state === self::PENDING || $this->state === self::UNKNOWN;
    }

    public function with(
        ?string $state = null,
        ?string $status = null,
        ?string $failure = null,
        ?string $note = null,
    ): self {
        return new self(
            $this->reference,
            $this->type,
            $state ?? $this->state,
            $this->amountMinorUnits,
            $this->currencyCode,
            $this->createdAt,
            gmdate('Y-m-d\TH:i:s\Z'),
            $status ?? $this->status,
            $failure ?? $this->failure,
            $note ?? $this->note,
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $key): string => isset($data[$key]) && \is_scalar($data[$key]) ? (string) $data[$key] : '';
        return new self(
            $text('reference'),
            $text('type'),
            $text('state'),
            $text('amountMinorUnits'),
            $text('currencyCode'),
            $text('createdAt'),
            $text('updatedAt'),
            $text('status'),
            $text('failure'),
            $text('note'),
        );
    }
}
