<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService;

use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Internal\EcrMessage;
use AmwalPay\Ecr\Internal\MerchantReference;
use AmwalPay\Ecr\Internal\Validate;
use AmwalPay\Ecr\Json\JsonObject;

/**
 * Web Service request bodies: the Hub contract's field set per operation
 * (POS-552), signed with {@see WebServiceSecureHash}. Field order matches the
 * Windows SDK byte for byte.
 *
 * @internal
 */
final class WebServiceMessage
{
    /**
     * @param int|null $amountMinorUnits Required for sale and refund.
     * @param string $stan The receipt number, as given; padded here.
     * @param string $originalMerchantReference Inquiry by reference only: the transaction looked up.
     * @return array{0: string, 1: JsonObject} the reference and the (signed) body
     */
    public static function build(
        EcrTransactionType $type,
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        #[\SensitiveParameter] string $secureHashKey,
        string $currencyCode = '512',
        string $ecrId = 'ECR01',
        ?int $amountMinorUnits = null,
        string $stan = '',
        string $originalTransactionDate = '',
        string $originalMerchantReference = '',
        string $merchantReference = '',
        string $transactionDateTime = '',
    ): array {
        if ($type === EcrTransactionType::CloseReceipt) {
            return self::buildCloseReceipt($merchantId, $terminalId, $terminalSerial, $secureHashKey, $ecrId, $merchantReference, $originalMerchantReference, $transactionDateTime);
        }
        if ($type === EcrTransactionType::Receipt) {
            throw new \LogicException('Receipt is not a Web Service operation');
        }

        $reference = MerchantReference::resolve($merchantReference);
        $wireDateTime = $transactionDateTime !== '' ? $transactionDateTime : self::now();
        $body = (new JsonObject())
            ->set('version', EcrMessage::PROTOCOL_VERSION)
            ->set('messageType', $type->messageType())
            ->set('merchantId', $merchantId)
            ->set('terminalId', $terminalId)
            ->set('terminalSerial', $terminalSerial)
            ->set('ecrId', $ecrId)
            ->set('merchantReference', $reference)
            ->set('currencyCode', $currencyCode)
            ->set('transactionDateTime', $wireDateTime)
            // The Hub validates this separately (RequestDateTimeIsRequired when omitted).
            ->set('requestDateTime', $wireDateTime);

        switch ($type) {
            case EcrTransactionType::Sale:
                $body->set('amount', self::padded($amountMinorUnits));
                break;
            case EcrTransactionType::Void:
                $body->set('stan', Validate::paddedStan($stan))
                    ->set('originalMerchantReference', $reference)
                    ->set('originalTerminalId', $terminalId);
                break;
            case EcrTransactionType::Refund:
                $body->set('amount', self::padded($amountMinorUnits))
                    ->set('stan', Validate::paddedStan($stan))
                    ->set('originalTransactionDate', $originalTransactionDate)
                    ->set('originalMerchantReference', $reference)
                    ->set('originalTerminalId', $terminalId);
                break;
            case EcrTransactionType::Inquiry:
                $invoiceStan = Validate::paddedStan($stan);
                $byReference = $invoiceStan === '' && trim($originalMerchantReference) !== '';
                // By STAN: empty originalMerchantReference. By reference: set it.
                $body->set('stan', $invoiceStan)
                    ->set('originalTransactionDate', $originalTransactionDate)
                    ->set('originalMerchantReference', $byReference ? MerchantReference::resolve($originalMerchantReference) : '')
                    ->set('originalTerminalId', $terminalId);
                break;
            default:
                throw new \LogicException($type->displayName() . ' is not a Web Service operation');
        }

        return [$reference, $secureHashKey === '' ? $body : WebServiceSecureHash::withHash($body, $secureHashKey)];
    }

    /**
     * The Hub's `/Ecr/closeReceipt` body: no `messageType`, no `currencyCode`.
     * `$originalMerchantReference` is the sale, void or refund that just finished;
     * `$merchantReference` is this close request's own id.
     *
     * @return array{0: string, 1: JsonObject}
     */
    public static function buildCloseReceipt(
        int $merchantId,
        int $terminalId,
        string $terminalSerial,
        #[\SensitiveParameter] string $secureHashKey,
        string $ecrId = 'ECR01',
        string $merchantReference = '',
        string $originalMerchantReference = '',
        string $transactionDateTime = '',
    ): array {
        if (trim($originalMerchantReference) === '') {
            throw new \InvalidArgumentException('Close receipt needs the original merchant reference');
        }
        $reference = MerchantReference::resolve($merchantReference);
        $wireDateTime = $transactionDateTime !== '' ? $transactionDateTime : self::now();
        $body = (new JsonObject())
            ->set('version', EcrMessage::PROTOCOL_VERSION)
            ->set('merchantId', $merchantId)
            ->set('terminalId', $terminalId)
            ->set('terminalSerial', $terminalSerial)
            ->set('ecrId', $ecrId)
            ->set('merchantReference', $reference)
            ->set('transactionDateTime', $wireDateTime)
            ->set('requestDateTime', $wireDateTime)
            ->set('originalMerchantReference', MerchantReference::resolve($originalMerchantReference));

        return [$reference, $secureHashKey === '' ? $body : WebServiceSecureHash::withHash($body, $secureHashKey)];
    }

    /** PHP's default time zone; the terminal passes a timestamp in the configured one. */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('YmdHis');
    }

    private static function padded(?int $minorUnits): string
    {
        if ($minorUnits === null) {
            throw new \LogicException('This operation needs an amount');
        }
        return str_pad((string) $minorUnits, 12, '0', \STR_PAD_LEFT);
    }
}
