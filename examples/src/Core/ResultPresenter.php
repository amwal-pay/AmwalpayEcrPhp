<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\EcrWireResponse;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\EcrResult;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\NotFound;

/**
 * How a transaction ended: the outcome at a glance, the fields that matter to a
 * cashier, and the terminal's full answer tucked away. The wording is the
 * Windows samples' ResultPresenter, word for word.
 *
 * Returns the dialog model as an array:
 * headline, tone (approved|declined), amount, message, note, fields
 * [{label, value}], hints, rawPayload, inquirableReference, offersReceipt,
 * receiptNote.
 */
final class ResultPresenter
{
    public const PARTIAL_APPROVAL_HEADLINE = 'Partially approved';

    public const UNKNOWN_OUTCOME_WARNING = 'The transaction may have completed. Check before taking payment again — '
        . 'sending it a second time would charge the cardholder twice.';

    /** @return array<string, mixed>|null */
    public static function for(TransactionState $state, bool $usesWebService): ?array
    {
        return match (true) {
            $state instanceof StateCompleted => self::forResult($state->result, $state->type),
            $state instanceof StateInquired => self::forInquiry($state, !$usesWebService, $usesWebService),
            $state instanceof StateFailed => self::forFailure($state),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public static function forResult(EcrResult $result, EcrTransactionType $type): array
    {
        if ($result instanceof Approved) {
            $fields = [];
            // A void carries no authorisation of its own (the terminal returns a
            // placeholder 000000), so showing one would suggest an approval.
            if ($type !== EcrTransactionType::Void) {
                $fields[] = ['label' => 'Auth code', 'value' => $result->authCode];
            }
            $fields[] = ['label' => 'Card', 'value' => $result->maskedPan];
            $fields[] = ['label' => 'Reference', 'value' => $result->merchantReference];
            return self::model(
                headline: $result->partialApproval ? self::PARTIAL_APPROVAL_HEADLINE : 'Approved',
                tone: 'approved',
                amount: $result->amount,
                message: $result->partialApproval ? self::partialMessage($result->amount, $result->requestedAmount) : null,
                fields: self::nonEmpty($fields),
                rawPayload: self::pretty($result->raw),
            );
        }
        if ($result instanceof Declined) {
            return self::model(
                headline: 'Declined',
                tone: 'declined',
                message: EcrWireResponse::displayMessageFromRaw($result->raw, $result->reason),
                rawPayload: $result->raw !== '' ? self::pretty($result->raw) : null,
            );
        }
        \assert($result instanceof Failed);
        return self::forFailure(new StateFailed($result->failure->message));
    }

    /**
     * What the terminal knows about an earlier transaction. The headline is the
     * transaction's own status: an inquiry that finds a declined sale succeeded,
     * and the till needs to read "Declined".
     *
     * @return array<string, mixed>
     */
    public static function forInquiry(StateInquired $state, bool $receiptSupported, bool $webService): array
    {
        $inquiry = $state->inquiry;
        if ($inquiry instanceof InquiryFailed) {
            return self::forFailure(new StateFailed($inquiry->failure->message));
        }
        if ($inquiry instanceof NotFound) {
            return self::model(
                headline: 'Not found',
                tone: 'declined',
                message: EcrWireResponse::displayMessageFromRaw($inquiry->raw, $inquiry->reason),
                note: TransactionOutcomes::inquiryNote($inquiry, $webService),
                rawPayload: self::pretty($inquiry->raw),
            );
        }
        \assert($inquiry instanceof Found);
        $txn = $inquiry->transaction;
        $settled = $txn->isApproved();
        // A partial approval read back has to read like the sale did.
        $partial = $settled && $txn->partialApproval;
        $hints = [];
        if ($txn->isRefunded) {
            $hints[] = 'Already refunded';
        }
        if ($txn->canVoid) {
            $hints[] = 'Can be voided';
        }
        if ($txn->canRefund) {
            $hints[] = 'Can be refunded';
        }
        $message = null;
        if ($partial) {
            $message = self::partialMessage($txn->authorizedAmount, $txn->amount);
        } elseif (!$settled) {
            $message = EcrWireResponse::displayMessageFromRaw($inquiry->raw, $txn->status);
        }
        return self::model(
            headline: $partial ? self::PARTIAL_APPROVAL_HEADLINE : ($txn->status === '' ? 'Found' : $txn->status),
            tone: $settled ? 'approved' : 'declined',
            amount: $partial ? $txn->authorizedAmount : $txn->amount,
            message: $message,
            fields: self::nonEmpty([
                ['label' => 'Receipt number', 'value' => $txn->stan],
                ['label' => 'Type', 'value' => $txn->type],
                ['label' => 'Card', 'value' => $txn->maskedPan],
                ['label' => 'Date/Time', 'value' => self::readableTime($txn->transactionTime)],
            ]),
            hints: $hints,
            note: $state->recoveredFromFailure
                ? "The terminal's answer to this request was lost, but it was asked automatically "
                    . 'and knows this transaction. Do not send it again.'
                : null,
            rawPayload: self::pretty($inquiry->raw),
            offersReceipt: $receiptSupported,
            receiptNote: $receiptSupported ? null : "The e-receipt QR is available over Wi\u{2011}Fi.",
        );
    }

    /**
     * The exchange itself failed. Not "Declined": no answer came back, so nobody
     * knows yet, and the wording has to stop an operator concluding it did not happen.
     *
     * @return array<string, mixed>
     */
    public static function forFailure(StateFailed $failed): array
    {
        return self::model(
            headline: $failed->inquirableReference === null ? 'Not completed' : 'Outcome unknown',
            tone: 'declined',
            message: $failed->message,
            note: $failed->note,
            inquirableReference: $failed->inquirableReference,
        );
    }

    public static function partialMessage(string $approved, string $requested): string
    {
        return 'Only ' . $approved . ' of ' . $requested . ' was approved. Collect the difference by another means.';
    }

    /** `2026-08-09T16:58:16.804271` as `09 Aug 2026 16:58`; anything else as it arrived. */
    public static function readableTime(string $value): string
    {
        $head = substr($value, 0, 19);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $head);
        return $parsed !== false && $parsed->format('Y-m-d\TH:i:s') === $head ? $parsed->format('d M Y H:i') : $value;
    }

    /** Indents JSON for reading; anything that is not JSON is returned as it arrived. */
    public static function pretty(string $raw): string
    {
        if ($raw === '') {
            return $raw;
        }
        try {
            return Json::pretty(Json::parse($raw));
        } catch (\Throwable) {
            return $raw;
        }
    }

    /**
     * @param list<array{label: string, value: string}> $fields
     * @return list<array{label: string, value: string}>
     */
    private static function nonEmpty(array $fields): array
    {
        return array_values(array_filter($fields, static fn (array $f): bool => $f['value'] !== ''));
    }

    /**
     * @param list<array{label: string, value: string}> $fields
     * @param list<string> $hints
     * @return array<string, mixed>
     */
    private static function model(
        string $headline,
        string $tone,
        string $amount = '',
        ?string $message = null,
        array $fields = [],
        array $hints = [],
        ?string $note = null,
        ?string $rawPayload = null,
        ?string $inquirableReference = null,
        bool $offersReceipt = false,
        ?string $receiptNote = null,
    ): array {
        return [
            'headline' => $headline,
            'tone' => $tone,
            'amount' => $amount,
            'message' => $message,
            'note' => $note,
            'fields' => $fields,
            'hints' => $hints,
            'rawPayload' => $rawPayload,
            'isUnknownOutcome' => $inquirableReference !== null,
            'inquirableReference' => $inquirableReference,
            'offersReceipt' => $offersReceipt,
            'receiptNote' => $receiptNote,
        ];
    }
}
