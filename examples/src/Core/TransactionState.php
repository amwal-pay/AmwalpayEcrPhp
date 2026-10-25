<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Result\EcrInquiry;
use AmwalPay\Ecr\Result\EcrReceipt;
use AmwalPay\Ecr\Result\EcrResult;

/**
 * What the transaction screen is showing: Idle, Checking, InProgress,
 * Completed, Inquired or Failed. The classes are below; PHP has no nested types.
 */
abstract class TransactionState
{
    public function isBusy(): bool
    {
        return $this instanceof StateChecking || $this instanceof StateInProgress;
    }

    /** A state the operator has to acknowledge before doing anything else. */
    public function isResult(): bool
    {
        return $this instanceof StateCompleted || $this instanceof StateInquired || $this instanceof StateFailed;
    }

    /** `idle`, `checking`, `inProgress`, `completed`, `inquired` or `failed`. */
    abstract public function name(): string;
}

/** Nothing running: stopped before anything was sent. */
final class StateIdle extends TransactionState
{
    public function name(): string
    {
        return 'idle';
    }
}

/** Probing the terminal, before anything is sent to it. */
final class StateChecking extends TransactionState
{
    public function name(): string
    {
        return 'checking';
    }
}

/** Sent, waiting on the cardholder and the terminal. */
final class StateInProgress extends TransactionState
{
    public function name(): string
    {
        return 'inProgress';
    }
}

/** The terminal answered, approved or declined. The operation is kept alongside. */
final class StateCompleted extends TransactionState
{
    public function __construct(public readonly EcrResult $result, public readonly EcrTransactionType $type)
    {
    }

    public function name(): string
    {
        return 'completed';
    }
}

/**
 * An inquiry was answered. The request is kept so the receipt can be asked for
 * from here; `recoveredFromFailure` marks a finding from the SDK's automatic
 * inquiry after a sale whose answer was lost.
 */
final class StateInquired extends TransactionState
{
    public function __construct(
        public readonly EcrInquiry $inquiry,
        public readonly TransactionRequest $request,
        public readonly ?EcrReceipt $receipt = null,
        public readonly bool $fetchingReceipt = false,
        public readonly bool $recoveredFromFailure = false,
    ) {
    }

    public function withReceipt(?EcrReceipt $receipt, bool $fetching): self
    {
        return new self($this->inquiry, $this->request, $receipt, $fetching, $this->recoveredFromFailure);
    }

    public function name(): string
    {
        return 'inquired';
    }
}

/**
 * The exchange itself failed. `inquirableReference` is set when the outcome is
 * genuinely unknown: the request went out and the transaction may have
 * completed, so the only thing offered is to look it up by that reference.
 * Null when nothing was ever sent.
 */
final class StateFailed extends TransactionState
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $inquirableReference = null,
        public readonly ?string $note = null,
    ) {
    }

    public function name(): string
    {
        return 'failed';
    }
}
