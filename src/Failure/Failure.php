<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Failure;

/**
 * Why an exchange could not be completed.
 *
 * The one distinction that matters after a failure is {@see outcomeUnknown()}.
 * When it is false nothing reached the terminal, so there is nothing to
 * reconcile. When it is true the request went out and the terminal may have
 * acted on it, whatever came back or did not: inquire by merchant reference,
 * never resend.
 */
abstract class Failure
{
    final public function __construct(public readonly string $message)
    {
    }

    /** Whether the request may have reached the terminal, leaving the outcome unknown. */
    public function outcomeUnknown(): bool
    {
        return true;
    }

    /** A short name for the kind of failure: `timeout`, `unreachable`, ... */
    abstract public function kind(): string;

    /** @return array{kind: string, message: string, outcomeUnknown: bool} */
    public function toArray(): array
    {
        return ['kind' => $this->kind(), 'message' => $this->message, 'outcomeUnknown' => $this->outcomeUnknown()];
    }
}
