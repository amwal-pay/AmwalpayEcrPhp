<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\EcrTransactionType;

/** One operation, as the screen asks for it. */
final class TransactionRequest
{
    /**
     * @param string|null $amount Major units as exact decimal text (e.g. "1.234"); null when the type takes none.
     * @param string $transactionDate `yyyyMMdd`, for operations that look an original up by date.
     * @param string $originalReference Look the original up by the reference it was sent with.
     * @param string $merchantReference This attempt's own reference. Blank: the registry makes one.
     */
    public function __construct(
        public readonly EcrTransactionType $type,
        public readonly string $terminalSerial,
        public readonly ?string $amount = null,
        public readonly string $receiptNumber = '',
        public readonly string $originalTerminalId = '',
        public readonly string $transactionDate = '',
        public readonly string $originalReference = '',
        public readonly string $merchantReference = '',
    ) {
    }

    public function looksUpByReference(): bool
    {
        return trim($this->originalReference) !== '';
    }

    public function withMerchantReference(string $reference): self
    {
        return new self($this->type, $this->terminalSerial, $this->amount, $this->receiptNumber, $this->originalTerminalId, $this->transactionDate, $this->originalReference, $reference);
    }

    /** The follow-up after an unknown outcome: an inquiry by the reference it was sent with. */
    public static function inquiryByReference(string $terminalSerial, string $reference): self
    {
        // Blank own reference, so the SDK mints this inquiry's merchantReference:
        // copying the sale's here would put one value in both wire fields.
        return new self(EcrTransactionType::Inquiry, $terminalSerial, originalReference: $reference);
    }
}
