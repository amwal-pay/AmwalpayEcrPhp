<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Exception;

use AmwalPay\Ecr\Safety\JournalEntry;

/**
 * A sale, void or refund was asked for with a merchant reference this terminal
 * has already been sent.
 *
 * Reusing a reference is how a till resends a payment it is unsure about, and
 * resending is how a cardholder is charged twice. When the earlier attempt's
 * outcome is unknown, inquire by this reference; when it was declined, start
 * the new attempt with a new reference.
 */
final class DuplicateMerchantReferenceException extends InvalidRequestException
{
    public function __construct(public readonly JournalEntry $previous)
    {
        $advice = $previous->isUnresolved()
            ? 'Its outcome is still unknown: inquire by this reference, never resend.'
            : 'A new attempt needs a new reference.';
        parent::__construct(sprintf(
            'Merchant reference %s was already sent to this terminal (%s, %s). %s',
            $previous->reference,
            $previous->type,
            $previous->state,
            $advice,
        ));
    }
}
