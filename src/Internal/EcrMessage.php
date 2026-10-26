<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Amount;
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Json\JsonObject;

/**
 * A Wi-Fi request as it goes on the wire.
 *
 * Fields that do not apply to the operation are left out entirely, so the
 * terminal can read a field's presence as meaning the operation uses it.
 *
 * @internal
 */
final class EcrMessage
{
    /**
     * Bumped when the message format changes in a way older terminals cannot
     * read. Present from the first release so a terminal can tell.
     */
    public const PROTOCOL_VERSION = 1;

    private function __construct(
        public readonly string $merchantReference,
        public readonly JsonObject $json,
    ) {
    }

    /**
     * The nonce this request went out with, empty when unsigned. An answer
     * carrying a different nonce is an answer to some other request.
     */
    public function nonce(): string
    {
        return $this->json->str('nonce');
    }

    /**
     * Builds and, when the config has a key, signs one request. Optional fields
     * are sent as given when not blank; `$stan` is padded here.
     */
    public static function build(
        EcrTransactionType $type,
        EcrConfig $config,
        string $terminalSerial,
        ?Amount $amount = null,
        string $stan = '',
        string $originalTerminalId = '',
        string $originalDate = '',
        string $originalReference = '',
        string $merchantReference = '',
    ): self {
        $reference = MerchantReference::resolve($merchantReference);
        $body = new JsonObject();
        $body->set('version', self::PROTOCOL_VERSION)
            ->set('messageType', $type->messageType())
            ->set('merchantReference', $reference)
            ->set('terminalSerial', $terminalSerial)
            ->set('currencyCode', $config->currencyCode)
            ->set('transactionDateTime', self::timestamp($config))
            ->set('ecrId', $config->ecrId);

        if ($type->requiresAmount()) {
            if ($amount === null) {
                throw new \LogicException($type->displayName() . ' needs an amount');
            }
            $body->set('amount', $amount->toWire($config->minorUnitDigits));
        }
        if ($type->requiresOriginalStan() && !Validate::isBlank($stan)) {
            $body->set('stan', Validate::paddedStan($stan));
        }
        if ($type->allowsOtherTerminal() && !Validate::isBlank($originalTerminalId)) {
            $body->set('originalTerminalId', $originalTerminalId);
        }
        if ($type->requiresOriginalDate() && !Validate::isBlank($originalDate)) {
            $body->set('originalTransactionDate', $originalDate);
        }
        // Names the transaction being looked up in place of its receipt number.
        // Only a read-only type may: acting on a transaction still goes by the
        // number on the printed receipt.
        if (!$type->movesMoney() && !Validate::isBlank($originalReference)) {
            $body->set('originalMerchantReference', MerchantReference::resolve($originalReference));
        }

        // The nonce is what makes a captured message useless a second time: the
        // terminal remembers the ones it has seen and refuses a repeat. It means
        // something only alongside a signature.
        if ($config->signsMessages()) {
            $body->set('nonce', SecureHash::newNonce());
            $body->set(SecureHash::FIELD, SecureHash::sign(SecureHash::compose($body), $config->secureHashKey));
        }

        return new self($reference, $body);
    }

    /** `yyyyMMddHHmmss` in the machine's local time (`DateTime.Now`), or in `timezone` when set. */
    public static function timestamp(EcrConfig $config): string
    {
        return LocalTime::stamp($config->timezone);
    }
}
