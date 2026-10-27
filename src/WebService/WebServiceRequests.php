<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService;

use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Json\JsonObject;

/**
 * Builds and signs Hub request bodies, for a caller that posts the JSON
 * itself. Sessions do this on their own; reach for these only when you own the
 * HTTP call. Each returns the body as a {@see JsonObject}: send
 * `$body->toJson()`.
 *
 * ```php
 * $body = WebServiceRequests::sale(10001, 20002, 'SN-WS-0001', 1234, 'ORDER-1', secureHashKey: $key);
 * ```
 */
final class WebServiceRequests
{
    /** @param int $amountMinorUnits Minor units: OMR 1.234 is 1234. */
    public static function sale(
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        int $amountMinorUnits,
        string $merchantReference = '',
        string $currencyCode = '512',
        string $ecrId = 'ECR01',
        string $transactionDateTime = '',
        #[\SensitiveParameter] string $secureHashKey = '',
    ): JsonObject {
        return WebServiceMessage::build(
            EcrTransactionType::Sale,
            $merchantId,
            $terminalId,
            $terminalSerial,
            $secureHashKey,
            $currencyCode,
            $ecrId,
            amountMinorUnits: $amountMinorUnits,
            merchantReference: $merchantReference,
            transactionDateTime: $transactionDateTime,
        )[1];
    }

    public static function void(
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        string $stan,
        string $merchantReference = '',
        string $currencyCode = '512',
        string $ecrId = 'ECR01',
        string $transactionDateTime = '',
        #[\SensitiveParameter] string $secureHashKey = '',
    ): JsonObject {
        return WebServiceMessage::build(
            EcrTransactionType::Void,
            $merchantId,
            $terminalId,
            $terminalSerial,
            $secureHashKey,
            $currencyCode,
            $ecrId,
            stan: $stan,
            merchantReference: $merchantReference,
            transactionDateTime: $transactionDateTime,
        )[1];
    }

    /** @param string $originalTransactionDate `yyyyMMdd`. */
    public static function refund(
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        int $amountMinorUnits,
        string $stan,
        string $originalTransactionDate,
        string $merchantReference = '',
        string $currencyCode = '512',
        string $ecrId = 'ECR01',
        string $transactionDateTime = '',
        #[\SensitiveParameter] string $secureHashKey = '',
    ): JsonObject {
        return WebServiceMessage::build(
            EcrTransactionType::Refund,
            $merchantId,
            $terminalId,
            $terminalSerial,
            $secureHashKey,
            $currencyCode,
            $ecrId,
            amountMinorUnits: $amountMinorUnits,
            stan: $stan,
            originalTransactionDate: $originalTransactionDate,
            merchantReference: $merchantReference,
            transactionDateTime: $transactionDateTime,
        )[1];
    }

    /**
     * Inquiry. By receipt number when `$stan` is set; otherwise by
     * `$originalMerchantReference`.
     */
    public static function transactionStatus(
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        string $stan = '',
        string $originalTransactionDate = '',
        string $merchantReference = '',
        string $currencyCode = '512',
        string $ecrId = 'ECR01',
        string $transactionDateTime = '',
        string $originalMerchantReference = '',
        #[\SensitiveParameter] string $secureHashKey = '',
    ): JsonObject {
        return WebServiceMessage::build(
            EcrTransactionType::Inquiry,
            $merchantId,
            $terminalId,
            $terminalSerial,
            $secureHashKey,
            $currencyCode,
            $ecrId,
            stan: $stan,
            originalTransactionDate: $originalTransactionDate,
            originalMerchantReference: $originalMerchantReference,
            merchantReference: $merchantReference,
            transactionDateTime: $transactionDateTime,
        )[1];
    }

    /** `/Ecr/closeReceipt`: `$originalMerchantReference` is the sale, void or refund being dismissed. */
    public static function closeReceipt(
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        string $originalMerchantReference,
        string $merchantReference = '',
        string $ecrId = 'ECR01',
        string $transactionDateTime = '',
        #[\SensitiveParameter] string $secureHashKey = '',
    ): JsonObject {
        return WebServiceMessage::buildCloseReceipt(
            $merchantId,
            $terminalId,
            $terminalSerial,
            $secureHashKey,
            $ecrId,
            $merchantReference,
            $originalMerchantReference,
            $transactionDateTime,
        )[1];
    }

    /**
     * `/Ecr/settle`. The path selects the operation; the body is the shared
     * identity payload (no `messageType`, no amount).
     */
    public static function settle(
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        string $merchantReference = '',
        string $currencyCode = '512',
        string $ecrId = 'ECR01',
        string $transactionDateTime = '',
        #[\SensitiveParameter] string $secureHashKey = '',
    ): JsonObject {
        return WebServiceMessage::buildPlain(
            $merchantId,
            $terminalId,
            $terminalSerial,
            $secureHashKey,
            $currencyCode,
            $ecrId,
            $merchantReference,
            $transactionDateTime,
        )[1];
    }

    /** `/Ecr/CheckReachability`. Same body as {@see self::settle()}. */
    public static function checkReachability(
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        string $merchantReference = '',
        string $currencyCode = '512',
        string $ecrId = 'ECR01',
        string $transactionDateTime = '',
        #[\SensitiveParameter] string $secureHashKey = '',
    ): JsonObject {
        return WebServiceMessage::buildPlain(
            $merchantId,
            $terminalId,
            $terminalSerial,
            $secureHashKey,
            $currencyCode,
            $ecrId,
            $merchantReference,
            $transactionDateTime,
        )[1];
    }
}
