<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Result;

/**
 * How a sale, void or refund ended.
 *
 * The three outcomes call for different things from a till: {@see Approved}
 * means money moved, {@see Declined} means the terminal answered and refused,
 * and {@see Failed} means the exchange itself failed, which may still have
 * taken the customer's money and must never be treated as a refusal.
 *
 * ```php
 * match (true) {
 *     $result instanceof Approved => record($result),
 *     $result instanceof Declined => show($result->reason),
 *     $result instanceof Failed && $result->settled() => apply($result->recovered->transaction),
 *     $result instanceof Failed && $result->failure->outcomeUnknown() => markUnresolved($result->merchantReference),
 *     $result instanceof Failed => show($result->failure->message),
 * };
 * ```
 */
abstract class EcrResult
{
    /**
     * @param string $merchantReference The reference the transaction was sent with: the caller's own, or
     *        the one the SDK generated. It names this transaction to the terminal afterwards.
     */
    public function __construct(public readonly string $merchantReference)
    {
    }

    public function isApproved(): bool
    {
        return $this instanceof Approved;
    }

    public function isDeclined(): bool
    {
        return $this instanceof Declined;
    }

    public function isFailed(): bool
    {
        return $this instanceof Failed;
    }

    /** `approved`, `declined` or `failed`. */
    abstract public function outcome(): string;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;
}
