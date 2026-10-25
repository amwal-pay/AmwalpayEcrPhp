<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/** Money moved. */
final class Approved extends EcrResult
{
    /**
     * @param string $amount What was actually taken or returned, in major units, e.g. `1.234`.
     * @param string $responseCode The backend's code, `00` on a plain approval.
     * @param string $maskedPan Masked, never the full number: `543173xxxx5785`. May be empty.
     * @param bool $partialApproval The issuer authorised less than was asked: collect the difference
     *        by other means before handing over the goods. Not a refusal.
     * @param string $requestedAmount What was asked for, when partially approved.
     * @param string $raw The terminal's full answer as JSON text, for anything not surfaced here.
     */
    public function __construct(
        string $merchantReference,
        public readonly string $amount,
        public readonly string $responseCode,
        public readonly string $rrn,
        public readonly string $authCode,
        public readonly string $maskedPan,
        public readonly bool $partialApproval,
        public readonly string $requestedAmount,
        public readonly string $raw,
    ) {
        parent::__construct($merchantReference);
    }

    public function outcome(): string
    {
        return 'approved';
    }

    public function toArray(): array
    {
        return [
            'outcome' => 'approved',
            'merchantReference' => $this->merchantReference,
            'amount' => $this->amount,
            'responseCode' => $this->responseCode,
            'rrn' => $this->rrn,
            'authCode' => $this->authCode,
            'maskedPan' => $this->maskedPan,
            'partialApproval' => $this->partialApproval,
            'requestedAmount' => $this->requestedAmount,
        ];
    }
}
