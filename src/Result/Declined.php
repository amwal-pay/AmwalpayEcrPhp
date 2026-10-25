<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * The terminal answered and refused. No money moved.
 *
 * An answer that says the outcome is unknown (`nextStep`
 * `INQUIRE_BY_MERCHANT_REFERENCE`) never arrives as a decline: it is a
 * {@see Failed}. So {@see $nextStep} is always {@see NextStep::None}; it is kept
 * for parity with the Windows SDK.
 */
final class Declined extends EcrResult
{
    /** @param string $reason The text to show: the backend's error list in preference to its message. */
    public function __construct(
        string $merchantReference,
        public readonly string $responseCode,
        public readonly string $reason,
        public readonly NextStep $nextStep,
        public readonly string $raw,
    ) {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'declined';
    }

    public function toArray(): array
    {
        return [
            'outcome' => 'declined',
            'merchantReference' => $this->merchantReference,
            'responseCode' => $this->responseCode,
            'reason' => $this->reason,
            'nextStep' => $this->nextStep->value,
        ];
    }
}
