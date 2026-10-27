<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

/**
 * Operations a terminal can be asked to run.
 *
 * Each type declares what the caller has to supply, so a till's own form can be
 * driven from these flags rather than repeating the rules. What the terminal
 * will accept (whether the original exists, whether it is still voidable) is
 * answered by the terminal.
 */
enum EcrTransactionType: string
{
    /** An ordinary purchase. */
    case Sale = 'Sale';

    /** Cancels an earlier transaction for its full amount. No card is presented. */
    case Void = 'Void';

    /** Returns money against an earlier transaction, in full or in part. The cardholder presents the card. */
    case Refund = 'Refund';

    /** Asks what became of an earlier transaction. Reads only, safe to repeat. */
    case Inquiry = 'Inquiry';

    /** Asks for an earlier transaction's e-receipt URL. Wi-Fi only. */
    case Receipt = 'Receipt';

    /** Asks the terminal to put its receipt away and return to idle. */
    case CloseReceipt = 'CloseReceipt';

    /**
     * Closes the terminal's current batch and prints the settlement receipt.
     * Run it with {@see EcrTerminal::settlement()} or
     * {@see EcrOpenedSession::settlement()}. Not a menu choice: the till
     * confirms with the cashier first, because the terminal starts as soon as
     * the message arrives.
     */
    case Settlement = 'Settlement';

    /**
     * The operations an operator picks from, in the order a till usually
     * offers them. Receipt, close receipt and settlement are absent by design.
     *
     * @return list<self>
     */
    public static function menuOptions(): array
    {
        return [self::Sale, self::Void, self::Refund, self::Inquiry];
    }

    /** The value carried in the message's `messageType` field. */
    public function messageType(): string
    {
        return match ($this) {
            self::Sale => 'SALE',
            self::Void => 'VOID',
            self::Refund => 'REFUND',
            self::Inquiry => 'INQUIRY',
            self::Receipt => 'RECEIPT',
            self::CloseReceipt => 'CLOSE_RECEIPT',
            self::Settlement => 'SETTLEMENT',
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::CloseReceipt => 'Close receipt',
            default => $this->value,
        };
    }

    /** Whether the caller supplies an amount. A void takes the original's amount, which only the terminal knows. */
    public function requiresAmount(): bool
    {
        return $this === self::Sale || $this === self::Refund;
    }

    /** Whether the caller names an earlier transaction by its receipt number (STAN). */
    public function requiresOriginalStan(): bool
    {
        return $this === self::Void || $this === self::Refund || $this === self::Inquiry || $this === self::Receipt;
    }

    /**
     * Whether the operation carries the original's date: a receipt number is unique only within a
     * terminal's day. Required for a refund; optional (sent when given) for an inquiry or a receipt.
     */
    public function requiresOriginalDate(): bool
    {
        return $this === self::Refund || $this === self::Inquiry || $this === self::Receipt;
    }

    /** Whether the operation moves money. */
    public function movesMoney(): bool
    {
        return $this === self::Sale || $this === self::Void || $this === self::Refund;
    }

    /** Whether the caller may name a different terminal the original was taken on (Wi-Fi only). */
    public function allowsOtherTerminal(): bool
    {
        return $this->requiresOriginalStan();
    }
}
