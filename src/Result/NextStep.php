<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * What the till should do about an outcome it cannot act on directly.
 *
 * Stated rather than left for the till to infer from a response code, because
 * the wrong inference is expensive: reading an unknown outcome as a refusal and
 * sending the sale again charges the cardholder twice.
 */
enum NextStep: string
{
    /** The answer settles the matter. */
    case None = 'NONE';

    /** Ask what became of the transaction, quoting the reference it was sent with. Never retry instead. */
    case InquireByMerchantReference = 'INQUIRE_BY_MERCHANT_REFERENCE';

    /** Reads the wire value. Unknown values read as None: a till must not act on a step it does not understand. */
    public static function fromWire(string $value): self
    {
        return $value === self::InquireByMerchantReference->value ? self::InquireByMerchantReference : self::None;
    }
}
