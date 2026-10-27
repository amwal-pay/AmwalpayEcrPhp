<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * What a settlement came back as: {@see Settled}, {@see NothingToSettle},
 * {@see NotSettled} or {@see SettlementFailed}. The till already confirmed
 * with the cashier; this is only the terminal's answer.
 */
abstract class EcrSettlement
{
    /** @param string $merchantReference The reference the settlement was sent with, or the answer's own. */
    public function __construct(public readonly string $merchantReference)
    {
    }

    /** `settled`, `nothing_to_settle`, `not_settled` or `failed`. */
    abstract public function outcome(): string;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;
}
