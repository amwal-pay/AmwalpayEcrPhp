<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService;

use AmwalPay\Ecr\EcrTransactionType;

/** Web Service ECR operations, one REST endpoint each on the Hub. */
enum WebServiceOperation: string
{
    case Sale = '/Ecr/Sale';
    case Void = '/Ecr/Void';
    case Refund = '/Ecr/Refund';
    case TransactionStatus = '/Ecr/Inquiry';
    case CloseReceipt = '/Ecr/closeReceipt';
    case Settle = '/Ecr/settle';
    case CheckReachability = '/Ecr/CheckReachability';

    public function path(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Void => 'Void',
            self::Refund => 'Refund',
            self::TransactionStatus => 'Transaction status',
            self::CloseReceipt => 'Close receipt',
            self::Settle => 'Settlement',
            self::CheckReachability => 'Check reachability',
        };
    }

    /** The REST operation for a transaction type, or null when it has none (receipts). */
    public static function forType(EcrTransactionType $type): ?self
    {
        return match ($type) {
            EcrTransactionType::Sale => self::Sale,
            EcrTransactionType::Void => self::Void,
            EcrTransactionType::Refund => self::Refund,
            EcrTransactionType::Inquiry => self::TransactionStatus,
            EcrTransactionType::CloseReceipt => self::CloseReceipt,
            EcrTransactionType::Receipt => null,
            EcrTransactionType::Settlement => self::Settle,
        };
    }
}
