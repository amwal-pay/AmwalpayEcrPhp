<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

use AmwalPay\Ecr\Failure\Failure;

/**
 * The exchange itself failed.
 *
 * When {@see Failure::outcomeUnknown()} is true the payment may have completed.
 * Read {@see $recovered} first (Wi-Fi asks the terminal once by itself), then
 * inquire by {@see $merchantReference}. Never resend.
 */
final class Failed extends EcrResult
{
    /**
     * @param EcrInquiry|null $recovered What the terminal said when asked afterwards, or null when it was
     *        not asked. {@see Found} settles it; anything else leaves it unknown. Always null on Web Service.
     */
    public function __construct(
        string $merchantReference,
        public readonly Failure $failure,
        public readonly ?EcrInquiry $recovered = null,
    ) {
        parent::__construct($merchantReference);
    }

    /** Always {@see NextStep::InquireByMerchantReference}: nothing here says the transaction did not happen. */
    public function nextStep(): NextStep
    {
        return NextStep::InquireByMerchantReference;
    }

    /** Whether the follow-up inquiry found the transaction, so the outcome is no longer unknown. */
    public function settled(): bool
    {
        return $this->recovered instanceof Found;
    }

    public function withRecovered(?EcrInquiry $recovered): self
    {
        return new self($this->merchantReference, $this->failure, $recovered);
    }

    public function outcome(): string
    {
        return 'failed';
    }

    public function toArray(): array
    {
        return [
            'outcome' => 'failed',
            'merchantReference' => $this->merchantReference,
            'failure' => $this->failure->toArray(),
            'nextStep' => $this->nextStep()->value,
            'settled' => $this->settled(),
            'recovered' => $this->recovered?->toArray(),
        ];
    }
}
