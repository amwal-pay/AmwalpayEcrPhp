<?php

declare(strict_types=1);

namespace AmwalExample\Core;

use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\EcrWireResponse;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\EcrInquiry;
use AmwalPay\Ecr\Result\EcrResult;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\NextStep;
use AmwalPay\Ecr\Result\NotFound;

/**
 * Turns what the SDK returned into what the screen shows. Pure, so the
 * payment-safety rules (an unknown outcome is never a refusal, and never a
 * reason to retry) can be tested without a terminal.
 */
final class TransactionOutcomes
{
    /** The Web Service layer's code for an answer that never came: not a decline. */
    public const NO_RESPONSE_CODE = 'NO_RESPONSE';

    public static function fromResult(EcrResult $result, TransactionRequest $request): TransactionState
    {
        if ($result instanceof Failed) {
            return self::fromFailed($result, $request);
        }
        if ($result instanceof Declined && $result->responseCode === self::NO_RESPONSE_CODE) {
            return new StateFailed('No answer from the Web Service: ' . $result->reason, self::inquirableReferenceOrNull($result->merchantReference));
        }
        // The terminal answered and said it does not actually know.
        if ($result instanceof Declined && $result->nextStep === NextStep::InquireByMerchantReference) {
            return new StateFailed(
                $result->reason,
                self::inquirableReferenceOrNull($result->merchantReference),
                'The terminal reports that it does not know whether this completed.',
            );
        }
        return new StateCompleted($result, $request->type);
    }

    private static function fromFailed(Failed $failed, TransactionRequest $request): TransactionState
    {
        // The SDK asked the terminal and it knew: no longer an unknown outcome.
        if ($failed->recovered instanceof Found) {
            return new StateInquired(
                $failed->recovered,
                TransactionRequest::inquiryByReference($request->terminalSerial, $failed->merchantReference),
                recoveredFromFailure: true,
            );
        }
        $note = match (true) {
            $failed->recovered instanceof NotFound => 'The terminal was asked automatically what became of it and has no record yet. '
                . 'That does not prove nothing was charged — look it up again before taking payment again.',
            $failed->recovered instanceof InquiryFailed => 'The automatic check with the terminal could not be completed either.',
            default => null,
        };
        // Only when the request actually went out: an unreachable terminal means
        // nothing was sent, so there is nothing to ask about.
        $reference = $failed->failure->outcomeUnknown() ? self::inquirableReferenceOrNull($failed->merchantReference) : null;
        return new StateFailed($failed->failure->message, $reference, $note);
    }

    public static function fromInquiry(EcrInquiry $inquiry, TransactionRequest $request): TransactionState
    {
        if ($inquiry instanceof InquiryFailed) {
            return new StateFailed(
                $inquiry->failure->message,
                self::retryableReference($request),
                'An inquiry only reads and changes nothing, so it is safe to try again.',
            );
        }
        // A Web Service transport error that reads as "not found" would say "it did not happen".
        if ($inquiry instanceof NotFound && self::isNoResponse($inquiry->raw)) {
            return new StateFailed(
                'No answer from the Web Service: ' . $inquiry->reason,
                self::retryableReference($request),
                'An inquiry only reads and changes nothing, so it is safe to try again.',
            );
        }
        return new StateInquired($inquiry, $request);
    }

    /** Extra text for an inquiry dialog, or null. */
    public static function inquiryNote(EcrInquiry $inquiry, bool $webService): ?string
    {
        if ($inquiry instanceof NotFound && $webService) {
            return 'The Web Service did not return this transaction. That does not prove nothing was '
                . "charged — check the terminal's receipt or the merchant portal before taking payment again.";
        }
        return null;
    }

    public static function isNoResponse(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }
        try {
            return EcrWireResponse::parse(Json::parseObject($raw))->responseCode === self::NO_RESPONSE_CODE;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * What to show after the operator stopped waiting. Stopping here does not stop
     * the terminal, so a money-moving operation is an unknown outcome, never "cancelled".
     */
    public static function cancelledState(TransactionRequest $request): TransactionState
    {
        return $request->type->movesMoney()
            ? new StateFailed(
                'You stopped waiting for the terminal. This does not cancel the transaction: '
                . 'if the request had already reached the terminal, it may still complete.',
                $request->merchantReference !== '' ? $request->merchantReference : null,
            )
            : new StateIdle();
    }

    /**
     * When an inquiry by reference fails the reference stays with the result: it
     * is usually one the app generated for a lost sale, in no field the operator
     * could retype it from.
     */
    private static function retryableReference(TransactionRequest $request): ?string
    {
        return $request->looksUpByReference() ? trim($request->originalReference) : null;
    }

    private static function inquirableReferenceOrNull(string $reference): ?string
    {
        return trim($reference) === '' ? null : $reference;
    }

    /** What to tell the operator when the terminal cannot be reached, before anything is sent. */
    public static function unreachableMessage(string $endpoint, int $port, ?string $error): string
    {
        $text = $endpoint . ' is not reachable';
        if ($error !== null && $error !== '') {
            $text .= "\n(" . $error . ')';
        }
        return $text . "\n\nCheck:\n"
            . "• POS app is in ECR mode (Wi\u{2011}Fi) and shows its IP under the card logos\n"
            . '• Registered IP matches that address (port ' . $port . ")\n"
            . "• Serial number matches the terminal you selected\n"
            . "• This PC and the terminal are on the same network, and the network profile is Private\n"
            . '• If the terminal runs on this PC (tools/fake-pos-server.php), try IP 127.0.0.1';
    }

    public static function movesMoney(EcrTransactionType $type): bool
    {
        return $type->movesMoney();
    }
}
