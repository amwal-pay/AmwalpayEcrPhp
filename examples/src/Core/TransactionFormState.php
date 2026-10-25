<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\EcrTransactionType;

/**
 * What the operator has entered, and what is wrong with it. Which fields apply
 * is decided by the transaction type, so showing a field and requiring it
 * cannot disagree. Same rules and messages as the Windows samples' Core.
 */
final class TransactionFormState
{
    public const MERCHANT_REFERENCE_MAX_LENGTH = 32;
    public const RECEIPT_NUMBER_MAX_LENGTH = 6;

    /**
     * @param string $amountDigits Raw keyed digits (mills); the field renders them as 0.000.
     * @param \DateTimeImmutable $originalDate Date of the original transaction, defaulting to today.
     */
    public function __construct(
        public readonly EcrTransactionType $type = EcrTransactionType::Sale,
        public readonly string $amountDigits = '',
        public readonly string $receiptNumber = '',
        public readonly string $originalTerminalId = '',
        public readonly ?\DateTimeImmutable $originalDate = null,
        public readonly bool $useOtherTerminal = false,
        public readonly bool $lookUpByReference = false,
        public readonly string $originalReference = '',
        public readonly string $merchantReference = '',
    ) {
    }

    public function showOtherTerminalSwitch(): bool
    {
        return $this->type->allowsOtherTerminal();
    }

    public function showOriginalTerminal(): bool
    {
        return $this->type->allowsOtherTerminal() && $this->useOtherTerminal;
    }

    /** Only an inquiry may look up by reference. */
    public function showReferenceSwitch(): bool
    {
        return $this->type === EcrTransactionType::Inquiry;
    }

    /** Optional till reference for a new sale. */
    public function showMerchantReference(): bool
    {
        return $this->type === EcrTransactionType::Sale;
    }

    public function looksUpByReference(): bool
    {
        return $this->showReferenceSwitch() && $this->lookUpByReference;
    }

    /** The receipt number field gives way to the reference field. */
    public function showReceiptNumber(): bool
    {
        return $this->type->requiresOriginalStan() && !$this->looksUpByReference();
    }

    /** A reference is unique in its own right; the date is not needed to disambiguate it. */
    public function showOriginalDate(): bool
    {
        return $this->type->requiresOriginalDate() && !$this->looksUpByReference();
    }

    /**
     * Validates, and returns either the request to send or the per-field errors
     * keyed like the form (`amount`, `receiptNumber`, `originalTerminalId`,
     * `originalReference`, `merchantReference`).
     *
     * @return array{0: ?TransactionRequest, 1: array<string, string>}
     */
    public function validated(string $terminalSerial): array
    {
        $mills = AmountEntry::mills($this->amountDigits);
        $errors = [];
        if ($this->type->requiresAmount()) {
            if ($mills === 0) {
                $errors['amount'] = 'Required field';
            } elseif ($mills < AmountEntry::MIN_MILLS) {
                $errors['amount'] = 'minimum amount allowed is ' . AmountEntry::formatMills(AmountEntry::MIN_MILLS);
            } elseif ($mills > AmountEntry::MAX_MILLS) {
                $errors['amount'] = 'maximum amount allowed is ' . AmountEntry::formatMills(AmountEntry::MAX_MILLS);
            }
        }
        if ($this->showReceiptNumber() && trim($this->receiptNumber) === '') {
            $errors['receiptNumber'] = 'Enter the receipt number of the transaction to act on';
        }
        if ($this->showOriginalTerminal() && trim($this->originalTerminalId) === '') {
            $errors['originalTerminalId'] = 'Enter the terminal the original was taken on';
        }
        if ($this->looksUpByReference() && trim($this->originalReference) === '') {
            $errors['originalReference'] = 'Enter the reference the transaction was sent with';
        }
        if ($this->showMerchantReference()) {
            $error = self::merchantReferenceError($this->merchantReference);
            if ($error !== null) {
                $errors['merchantReference'] = $error;
            }
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        $date = $this->originalDate ?? new \DateTimeImmutable('today');
        return [new TransactionRequest(
            type: $this->type,
            terminalSerial: $terminalSerial,
            amount: $this->type->requiresAmount() ? AmountEntry::format($this->amountDigits) : null,
            receiptNumber: $this->looksUpByReference() ? '' : $this->receiptNumber,
            originalTerminalId: $this->showOriginalTerminal() ? $this->originalTerminalId : '',
            transactionDate: $this->showOriginalDate() ? $date->format('Ymd') : '',
            // By reference: originalMerchantReference is what to look up; this
            // inquiry's own reference stays blank so the SDK mints one.
            originalReference: $this->looksUpByReference() ? trim($this->originalReference) : '',
            merchantReference: $this->looksUpByReference() ? '' : ($this->showMerchantReference() ? trim($this->merchantReference) : ''),
        ), []];
    }

    /** The same rule the SDK enforces, so the operator hears it before sending. */
    public static function merchantReferenceError(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (\strlen($trimmed) > self::MERCHANT_REFERENCE_MAX_LENGTH) {
            return 'A merchant reference is at most ' . self::MERCHANT_REFERENCE_MAX_LENGTH . ' characters';
        }
        if (preg_match('/^[\x21-\x7E]+$/', $trimmed) !== 1 || strpbrk($trimmed, '&=') !== false) {
            return "Use printable ASCII without spaces, '&' or '='";
        }
        return null;
    }
}
