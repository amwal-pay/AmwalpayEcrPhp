<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Amount;
use AmwalPay\Ecr\EcrWireResponse;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Json\JsonObject;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\CloseReceiptRefused;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\EcrInquiry;
use AmwalPay\Ecr\Result\EcrReceipt;
use AmwalPay\Ecr\Result\EcrReceiptClosed;
use AmwalPay\Ecr\Result\EcrResult;
use AmwalPay\Ecr\Result\EcrSettlement;
use AmwalPay\Ecr\Result\EcrSettlementTotal;
use AmwalPay\Ecr\Result\EcrTransaction;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\NextStep;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Result\NothingToSettle;
use AmwalPay\Ecr\Result\NotSettled;
use AmwalPay\Ecr\Result\ReceiptClosed;
use AmwalPay\Ecr\Result\ReceiptReady;
use AmwalPay\Ecr\Result\ReceiptUnavailable;
use AmwalPay\Ecr\Result\Settled;

/**
 * Reads an answer, from the terminal or the Hub, into the SDK's result types.
 * The same rules for Wi-Fi, USB cable and Web Service.
 *
 * @internal
 */
final class WireReaders
{
    /** The types a settlement reports totals for, in receipt order. */
    public const SETTLEMENT_TOTAL_TYPES = ['SALE', 'REFUND', 'VOID', 'PRE_AUTH', 'CAPTURE'];

    /** Reads an answer to a sale, void or refund into one of the three outcomes. */
    public static function toResult(JsonObject $json, string $merchantReference, int $minorUnitDigits): EcrResult
    {
        $envelope = EcrWireResponse::parse($json);
        $data = $envelope->data;
        $reference = self::orElse($envelope->merchantReference(), $merchantReference);

        if (!$envelope->success) {
            $nextStep = NextStep::fromWire($json->str('nextStep'));
            // A false envelope that asks for an inquiry is not a decline: money
            // may have moved. The Hub synthesises its timeouts this way
            // (responseCode NO_RESPONSE), so it surfaces as Failed.
            if ($nextStep === NextStep::InquireByMerchantReference) {
                $reason = $envelope->displayMessage() !== '' ? $envelope->displayMessage() : 'timeout';
                $code = strtoupper(trim($envelope->responseCode));
                $failure = $code === 'NO_RESPONSE' || stripos($reason, 'timeout') !== false
                    ? new Timeout($reason)
                    : new ConnectionLost($reason);
                return new Failed($reference, $failure);
            }

            $declinedReason = $envelope->displayMessage();
            return new Declined(
                $reference,
                $envelope->responseCode,
                $declinedReason !== '' ? $declinedReason : 'Declined',
                $nextStep,
                $json->toJson(),
            );
        }

        $requested = $data !== null && $data->str('amount') !== ''
            ? $data->str('amount')
            : Amount::majorUnits($json->str('requestedAmount'), $minorUnitDigits);

        return new Approved(
            $reference,
            self::settledAmountMajor($data, $minorUnitDigits, $json),
            $envelope->responseCode,
            self::first($data?->str('rrn'), $json->str('rrn')),
            self::first($data?->str('authCode'), $json->str('authCode')),
            self::first($data?->str('cardMask'), $data?->str('maskedPan'), $json->str('maskedPan')),
            $data !== null ? $data->flag('isPartialApprove') : $json->flag('partialApproval'),
            $requested,
            $json->toJson(),
        );
    }

    /**
     * Reads an answer to an inquiry. The transaction sits under `data`, or is
     * mirrored at the top level by the Hub; "found" means transaction markers
     * are present, not merely that `data` is an object.
     *
     * `success` reports the transaction's own outcome, so a declined sale the
     * terminal knows all about answers `false`. Reading `success` here would
     * turn it into "not found", and a till told that charges again.
     */
    public static function toInquiry(JsonObject $json, string $merchantReference, int $minorUnitDigits): EcrInquiry
    {
        $envelope = EcrWireResponse::parse($json);
        $data = self::inquiryRecord($envelope->data, $json);
        $reference = self::orElse($data?->str('merchantReference') ?? '', self::orElse($envelope->merchantReference(), $merchantReference));

        if ($data === null) {
            $reason = $envelope->displayMessage();
            return new NotFound($reference, $reason !== '' ? $reason : 'Transaction not found', $json->toJson());
        }

        return new Found(
            $reference,
            new EcrTransaction(
                transactionId: $data->str('transactionId'),
                stan: self::orElse($data->str('stan'), $data->str('systemTraceNr')),
                type: self::orElse($data->str('transactionTypeDisplayName'), $data->str('transactionType')),
                status: self::orElse($data->str('status'), $envelope->success ? 'Approved' : 'Declined'),
                // Amount is what was requested; authorizedAmount is what settled.
                amount: self::orElse($data->str('amount'), self::settledAmountMajor($data, $minorUnitDigits, $json)),
                totalAmount: $data->str('totalAmount'),
                currency: self::orElse($data->str('currency'), $data->str('currencyId')),
                transactionTime: $data->str('transactionTime'),
                maskedPan: self::orElse($data->str('cardMask'), $data->str('cardNumber')),
                cardHolderName: $data->str('cardHolderName'),
                rrn: $data->str('rrn'),
                authCode: $data->str('authCode'),
                batchId: $data->str('batchId'),
                terminalId: $data->str('terminalId'),
                isRefunded: $data->flag('isRefunded'),
                canVoid: $data->flag('canVoid'),
                canRefund: $data->flag('canRefund'),
                partialApproval: $data->flag('isPartialApprove'),
                authorizedAmount: self::orElse($data->str('authorizeAmount'), $data->str('amount')),
            ),
            $json->toJson(),
        );
    }

    /** Reads an answer to a receipt request. */
    public static function toReceipt(JsonObject $json, string $merchantReference): EcrReceipt
    {
        $envelope = EcrWireResponse::parse($json);
        $reference = self::orElse($envelope->merchantReference(), $merchantReference);
        $url = $envelope->data?->str('receiptUrl') ?? '';
        if ($url === '') {
            $reason = $envelope->displayMessage();
            return new ReceiptUnavailable($reference, $reason !== '' ? $reason : 'The receipt could not be generated', $json->toJson());
        }
        return new ReceiptReady($reference, $url, $json->toJson());
    }

    /** Reads an answer to close receipt. Success is taken from the envelope only. */
    public static function toReceiptClosed(JsonObject $json, string $merchantReference): EcrReceiptClosed
    {
        $envelope = EcrWireResponse::parse($json);
        $reference = self::orElse($envelope->merchantReference(), $merchantReference);
        if (!$envelope->success) {
            $reason = $envelope->displayMessage();
            return new CloseReceiptRefused(
                $reference,
                $envelope->responseCode,
                $reason !== '' ? $reason : 'The terminal refused to close its receipt',
                $json->toJson(),
            );
        }
        return new ReceiptClosed($reference, $json->toJson());
    }

    /**
     * Reads the answer to a settlement.
     *
     * `settlementStatus` decides the branch, not the envelope alone: an empty
     * batch is a success with nothing in it, and a refusal before the
     * settlement started (busy, not idle, a terminal too old to know it)
     * carries no status at all.
     */
    public static function toSettlement(JsonObject $json, string $merchantReference, int $minorUnitDigits): EcrSettlement
    {
        $envelope = EcrWireResponse::parse($json);
        $data = $envelope->data;
        $reference = self::orElse($envelope->merchantReference(), $merchantReference);
        $status = $data?->str('settlementStatus') ?? '';
        $raw = $json->toJson();

        if ($envelope->success && $status === 'NO_TRANSACTIONS') {
            $message = $envelope->displayMessage();
            return new NothingToSettle($reference, $message !== '' ? $message : 'There are no transactions to settle', $raw);
        }

        if (!$envelope->success || $data === null || $status !== 'SETTLED') {
            $reason = $envelope->displayMessage();
            return new NotSettled(
                $reference,
                $envelope->responseCode,
                $status,
                $reason !== '' ? $reason : 'The batch was not settled',
                self::wholeNumber($data, 'uploadedTransactions'),
                $raw,
            );
        }

        $totalsNode = $data->obj('totals');
        $totals = [];
        foreach (self::SETTLEMENT_TOTAL_TYPES as $type) {
            $row = $totalsNode?->obj($type);
            $totals[$type] = $row === null
                ? new EcrSettlementTotal(0, Amount::majorUnits('0', $minorUnitDigits))
                : new EcrSettlementTotal(self::wholeNumber($row, 'count') ?? 0, Amount::majorUnits($row->str('amount'), $minorUnitDigits));
        }

        return new Settled(
            $reference,
            $data->str('batchId'),
            $data->str('currency'),
            $data->str('startTime'),
            $data->str('endTime'),
            self::wholeNumber($data, 'totalTransactions') ?? 0,
            Amount::majorUnits($data->str('netAmount'), $minorUnitDigits),
            $totals,
            $raw,
        );
    }

    /**
     * The field as a 32-bit whole number, or null: `int.TryParse` with
     * `NumberStyles.Integer`, so surrounding white space and a sign are accepted.
     */
    private static function wholeNumber(?JsonObject $json, string $key): ?int
    {
        if ($json === null) {
            return null;
        }
        if (preg_match('/^[\t\n\v\f\r ]*([+-]?)([0-9]+)[\t\n\v\f\r ]*$/', $json->str($key), $m) !== 1) {
            return null;
        }
        $digits = ltrim($m[2], '0');
        if (\strlen($digits) > 10) {
            return null;
        }
        $value = (int) ($m[1] === '-' ? '-' . $digits : $digits);
        return $value < -2147483648 || $value > 2147483647 ? null : $value;
    }

    /**
     * The amount actually settled, in major units: `authorizeAmount` when it is
     * greater than zero, otherwise `amount`, otherwise the envelope's minor units.
     * Voids often carry `authorizeAmount` as zero while `amount` holds the figure.
     */
    public static function settledAmountMajor(?JsonObject $data, int $minorUnitDigits, JsonObject $root): string
    {
        if ($data !== null) {
            if (self::isPositiveDecimal($data->str('authorizeAmount'))) {
                return $data->str('authorizeAmount');
            }
            $amount = $data->str('amount');
            if ($amount !== '') {
                return $amount;
            }
        }
        return Amount::majorUnits($root->str('amount'), $minorUnitDigits);
    }

    /** Nested `data` when it names a transaction; otherwise the Hub's top-level fields; otherwise none. */
    private static function inquiryRecord(?JsonObject $nested, JsonObject $json): ?JsonObject
    {
        if (self::hasTransactionRecord($nested)) {
            return $nested;
        }
        $root = ResponseEnvelope::root($json);
        return self::hasTransactionRecord($root) ? $root : null;
    }

    private static function hasTransactionRecord(?JsonObject $data): bool
    {
        if ($data === null) {
            return false;
        }
        foreach (['systemTraceNr', 'stan', 'transactionId', 'status', 'rrn', 'authCode', 'amount', 'authorizeAmount', 'transactionType', 'transactionTypeDisplayName'] as $key) {
            if ($data->str($key) !== '') {
                return true;
            }
        }
        return false;
    }

    /** Whether `$text` is a decimal number greater than zero, read without a float. */
    private static function isPositiveDecimal(string $text): bool
    {
        if (preg_match('/^\s*([+-]?)([0-9]*)(?:\.([0-9]*))?(?:[eE][+-]?[0-9]+)?\s*$/', $text, $m) !== 1) {
            return false;
        }
        $mantissa = $m[2] . ($m[3] ?? '');
        if ($mantissa === '') {
            return false;
        }
        return $m[1] !== '-' && trim($mantissa, '0') !== '';
    }

    private static function orElse(string $value, string $fallback): string
    {
        return $value !== '' ? $value : $fallback;
    }

    private static function first(?string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return '';
    }
}
