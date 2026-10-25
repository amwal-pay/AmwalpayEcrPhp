<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\EcrWireResponse;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Timeout;
use AmwalPay\Ecr\Internal\WireReaders;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\CloseReceiptRefused;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\NextStep;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Result\ReceiptClosed;
use AmwalPay\Ecr\Result\ReceiptReady;
use AmwalPay\Ecr\Result\ReceiptUnavailable;
use AmwalPay\Ecr\Tests\Support\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same approved / declined / failed / found / not-found semantics as the
 * Windows SDK, over the real terminal and Hub payloads its tests pin
 * (InquiryReadingTests, EcrWireResponseTests, TimeoutOutcomeReadingTests).
 */
final class ResultMappingTest extends TestCase
{
    private const FOUND = <<<'JSON'
        {
          "success": true, "responseCode": "00", "message": "Transaction found",
          "data": {
            "merchantReference": "A1B2C3D4E5F6", "terminalSerial": "SN-WIFI-0001",
            "transactionId": "0e9a7c00-0000-4000-8000-000000000001", "stan": "000208", "systemTraceNr": "000208",
            "transactionType": "Purchase", "transactionTypeDisplayName": "Purchase", "status": "Approved",
            "amount": "0.258", "totalAmount": "0.258", "currency": "OMR", "currencyId": "512",
            "transactionTime": "2026-08-09T16:58:16.804271", "cardNumber": "543173******5785", "cardMask": "543173******5785",
            "cardHolderName": null, "rrn": "1000000000000000000001", "authCode": null, "batchId": "00000003",
            "terminalId": 30001, "merchantId": 10001, "isRefunded": false, "canVoid": true, "canRefund": true
          },
          "errorList": []
        }
        JSON;

    // ── Sale, void, refund ───────────────────────────────────────────

    public function testAUnifiedApprovalIsReadField(): void
    {
        $answer = Wire::obj('{"success":true,"responseCode":"00","message":"Approved","data":{"merchantReference":"EA5C14BDB0A2",'
            . '"systemTraceNr":"000008","rrn":"623807390109","authCode":"713984","amount":"0.111","authorizeAmount":"0.111",'
            . '"cardMask":"543173xxxx5785","transactionTypeDisplayName":"Purchase"},"errorList":[]}');

        $approved = WireReaders::toResult($answer, 'SENT', 3);

        self::assertInstanceOf(Approved::class, $approved);
        self::assertSame('EA5C14BDB0A2', $approved->merchantReference, "the answer's own reference wins");
        self::assertSame('0.111', $approved->amount);
        self::assertSame('623807390109', $approved->rrn);
        self::assertSame('713984', $approved->authCode);
        self::assertSame('543173xxxx5785', $approved->maskedPan);
        self::assertFalse($approved->partialApproval);
    }

    public function testALegacyFlatApprovalReportsMajorUnits(): void
    {
        $answer = Wire::obj('{"responseCode":"00","responseMessage":"Approved","approved":true,"amount":"000000001234","rrn":"622908275947","authCode":"751486"}');

        $approved = WireReaders::toResult($answer, 'ORD-1', 3);

        self::assertInstanceOf(Approved::class, $approved);
        self::assertSame('1.234', $approved->amount);
        self::assertSame('ORD-1', $approved->merchantReference);
    }

    public function testAPartialApprovalIsAnApprovalThatSaysSo(): void
    {
        $answer = Wire::obj('{"success":true,"responseCode":"00","message":"Approved","data":{"merchantReference":"ORD-1","amount":"961.100","authorizeAmount":"50.000","isPartialApprove":true}}');

        $approved = WireReaders::toResult($answer, 'ORD-1', 3);

        self::assertInstanceOf(Approved::class, $approved);
        self::assertTrue($approved->partialApproval);
        self::assertSame('50.000', $approved->amount);
        self::assertSame('961.100', $approved->requestedAmount);
    }

    public function testAZeroOrEmptyAuthorizedAmountShowsTheAmount(): void
    {
        foreach (['"0"', '""', '0', '"0.000"'] as $authorized) {
            $answer = Wire::obj('{"success":true,"responseCode":"00","data":{"merchantReference":"R","amount":"0.111","authorizeAmount":' . $authorized . '}}');
            $approved = WireReaders::toResult($answer, 'R', 3);
            self::assertInstanceOf(Approved::class, $approved);
            self::assertSame('0.111', $approved->amount, 'authorizeAmount ' . $authorized);
        }
    }

    public function testADeclineUsesTheErrorListAndResponseCode(): void
    {
        $answer = Wire::obj('{"success":false,"responseCode":"909","message":"Declined","data":null,"errorList":["Insufficient funds"]}');

        $declined = WireReaders::toResult($answer, 'REF-1', 3);

        self::assertInstanceOf(Declined::class, $declined);
        self::assertSame('Insufficient funds', $declined->reason);
        self::assertSame('909', $declined->responseCode);
        self::assertSame(NextStep::None, $declined->nextStep);
    }

    public function testADeclineWithNoWordsGetsADefaultReason(): void
    {
        $declined = WireReaders::toResult(Wire::obj('{"success":false,"responseCode":"05"}'), 'R', 3);

        self::assertInstanceOf(Declined::class, $declined);
        self::assertSame('Declined', $declined->reason);
    }

    /** @return iterable<array{string}> */
    public static function ordinaryDeclines(): iterable
    {
        yield ['{"success":false,"responseCode":"51","message":"Declined"}'];
        yield ['{"success":false,"responseCode":"51","message":"Declined","nextStep":"NONE"}'];
        yield ['{"success":false,"responseCode":"51","message":"Declined","nextStep":"SOMETHING_NEW"}'];
        yield ['{"responseCode":"51","responseMessage":"DECLINED","approved":false}'];
    }

    #[DataProvider('ordinaryDeclines')]
    public function testAnUnknownOrAbsentNextStepIsAnOrdinaryDecline(string $answer): void
    {
        $declined = WireReaders::toResult(Wire::obj($answer), 'R', 3);

        self::assertInstanceOf(Declined::class, $declined);
        self::assertSame(NextStep::None, $declined->nextStep);
    }

    public function testAnAnswerAskingForAnInquiryIsAnUnknownOutcomeNeverADecline(): void
    {
        $timeout = WireReaders::toResult(Wire::obj('{"success":false,"responseCode":"NO_RESPONSE","message":"timeout","nextStep":"INQUIRE_BY_MERCHANT_REFERENCE"}'), 'ORD-TIMEOUT-01', 3);
        $unsure = WireReaders::toResult(Wire::obj('{"success":false,"responseCode":"91","message":"Issuer unavailable","nextStep":"INQUIRE_BY_MERCHANT_REFERENCE"}'), 'ORD-2', 3);

        self::assertInstanceOf(Failed::class, $timeout);
        self::assertInstanceOf(Timeout::class, $timeout->failure);
        self::assertSame('timeout', $timeout->failure->message);
        self::assertSame('ORD-TIMEOUT-01', $timeout->merchantReference);
        self::assertSame(NextStep::InquireByMerchantReference, $timeout->nextStep());
        self::assertTrue($timeout->failure->outcomeUnknown());

        self::assertInstanceOf(Failed::class, $unsure);
        self::assertInstanceOf(ConnectionLost::class, $unsure->failure);
    }

    // ── Inquiry ──────────────────────────────────────────────────────

    public function testAFoundTransactionIsReadFieldForField(): void
    {
        $found = WireReaders::toInquiry(Wire::obj(self::FOUND), 'SENT', 3);

        self::assertInstanceOf(Found::class, $found);
        $t = $found->transaction;
        self::assertSame('A1B2C3D4E5F6', $found->merchantReference);
        self::assertSame('0e9a7c00-0000-4000-8000-000000000001', $t->transactionId);
        self::assertSame('000208', $t->stan);
        self::assertSame('Purchase', $t->type);
        self::assertSame('Approved', $t->status);
        self::assertTrue($t->isApproved());
        self::assertSame('0.258', $t->amount, 'the backend reports major units: not shifted again');
        self::assertSame('0.258', $t->totalAmount);
        self::assertSame('OMR', $t->currency);
        self::assertSame('543173******5785', $t->maskedPan);
        self::assertSame('00000003', $t->batchId);
        self::assertSame('30001', $t->terminalId);
        self::assertSame('', $t->authCode, 'null reads as absent');
        self::assertSame('', $t->cardHolderName);
        self::assertTrue($t->canVoid);
        self::assertTrue($t->canRefund);
        self::assertFalse($t->isRefunded);
        self::assertSame('20260809', $t->day());
        self::assertStringContainsString('"transactionId":"0e9a7c00-0000-4000-8000-000000000001"', $found->raw);
    }

    public function testNothingFoundCarriesTheTerminalsReason(): void
    {
        $notFound = WireReaders::toInquiry(Wire::obj('{"success":false,"responseCode":"25","message":"No transactions found for the provided STAN and Terminal","data":null,"errorList":[]}'), 'R', 3);
        $silent = WireReaders::toInquiry(Wire::obj('{"success":false,"data":null}'), 'SENT', 3);

        self::assertInstanceOf(NotFound::class, $notFound);
        self::assertSame('No transactions found for the provided STAN and Terminal', $notFound->reason);
        self::assertInstanceOf(NotFound::class, $silent);
        self::assertSame('Transaction not found', $silent->reason);
        self::assertSame('SENT', $silent->merchantReference);
    }

    public function testSuccessWithoutATransactionIsNotFound(): void
    {
        self::assertInstanceOf(NotFound::class, WireReaders::toInquiry(Wire::obj('{"approved":true,"responseMessage":"Odd"}'), 'R', 3));
    }

    public function testADeclinedTransactionTheTerminalKnowsIsFoundNotNotFound(): void
    {
        $declined = WireReaders::toInquiry(Wire::obj('{"success":false,"responseCode":"51","message":"Declined","data":{"stan":"000013","status":"Declined","amount":961.1,"rrn":"624408152411"}}'), 'R', 3);
        $refused = WireReaders::toInquiry(Wire::obj('{"responseCode":"51","responseMessage":"Declined","approved":false,"ecrResponse":{"success":false,"data":{"amount":"1.478"}}}'), 'R', 3);

        self::assertInstanceOf(Found::class, $declined);
        self::assertSame('Declined', $declined->transaction->status);
        self::assertTrue($declined->transaction->isDeclined());
        self::assertSame('624408152411', $declined->transaction->rrn);
        self::assertInstanceOf(Found::class, $refused);
        self::assertSame('Declined', $refused->transaction->status);
    }

    public function testARecordWithNoStatusTakesItFromTheEnvelope(): void
    {
        $replayed = Wire::obj('{"responseCode":"00","responseMessage":"Approved","approved":true,"amount":"000000001478","rrn":"622911288892",'
            . '"ecrResponse":{"success":true,"data":{"transactionId":"20ff0b20","systemTraceNr":"000149","actionCode":"00","amount":"1.478","transactionTypeDisplayName":"Purchase"}}}');

        $found = WireReaders::toInquiry($replayed, 'R', 3);

        self::assertInstanceOf(Found::class, $found);
        self::assertSame('Approved', $found->transaction->status);
        self::assertSame('000149', $found->transaction->stan);
    }

    public function testAPartialApprovalReportsBothAmounts(): void
    {
        $partial = WireReaders::toInquiry(Wire::obj('{"success":true,"responseCode":"00","data":{"stan":"000106","status":"Approved","amount":961.1,"authorizeAmount":50.0,"isPartialApprove":true}}'), 'R', 3);
        $ordinary = WireReaders::toInquiry(Wire::obj('{"success":true,"responseCode":"00","data":{"stan":"000107","status":"Approved","amount":1.0}}'), 'R', 3);

        self::assertInstanceOf(Found::class, $partial);
        self::assertTrue($partial->transaction->partialApproval);
        self::assertSame('961.1', $partial->transaction->amount);
        self::assertSame('50.0', $partial->transaction->authorizedAmount);
        self::assertInstanceOf(Found::class, $ordinary);
        self::assertFalse($ordinary->transaction->partialApproval);
        self::assertSame('1.0', $ordinary->transaction->authorizedAmount);
    }

    public function testAFoundRecordWithoutAmountsFallsBackToTheEnvelopesMinorUnits(): void
    {
        $found = WireReaders::toInquiry(Wire::obj('{"success":true,"amount":"000000001478","data":{"stan":"1","status":"Approved"}}'), 'R', 3);

        self::assertInstanceOf(Found::class, $found);
        self::assertSame('1.478', $found->transaction->amount);
    }

    public function testHubFlatFieldsAreReadWhenNestedDataIsOnlyCorrelation(): void
    {
        $found = WireReaders::toInquiry(Wire::obj('{"success":true,"responseCode":"00","message":"Approved","data":{"merchantReference":"A9A14AE818B9","terminalSerial":"SN-WIFI-0001"},'
            . '"merchantReference":"A9A14AE818B9","systemTraceNr":"000032","status":"Approved","amount":"1.234","transactionId":"e970","rrn":"786","transactionTypeDisplayName":"Purchase","currency":"OMR"}'), 'R', 3);
        $notFound = WireReaders::toInquiry(Wire::obj('{"success":false,"responseCode":"25","message":"Transaction not found","data":{"merchantReference":"A9A14AE818B9","terminalSerial":"SN-WIFI-0001"},"errorList":[]}'), 'A9A14AE818B9', 3);

        self::assertInstanceOf(Found::class, $found);
        self::assertSame('000032', $found->transaction->stan);
        self::assertSame('1.234', $found->transaction->amount);
        self::assertSame('Purchase', $found->transaction->type);
        self::assertInstanceOf(NotFound::class, $notFound, 'correlation-only data is not a found record');
        self::assertSame('Transaction not found', $notFound->reason);
    }

    // ── Receipt and close receipt ────────────────────────────────────

    public function testAReceiptIsTheUrlTheTerminalPublished(): void
    {
        $ready = WireReaders::toReceipt(Wire::obj('{"responseCode":"00","responseMessage":"Receipt ready","approved":true,"ecrResponse":{"success":true,"data":{"receiptUrl":"https://receipts.example.invalid/receipt?transactionId=0001","stan":"000215"}}}'), 'R');
        $unavailable = WireReaders::toReceipt(Wire::obj('{"responseCode":"91","responseMessage":"The receipt could not be generated","approved":false,"ecrResponse":{"success":false,"data":null}}'), 'R');

        self::assertInstanceOf(ReceiptReady::class, $ready);
        self::assertSame('https://receipts.example.invalid/receipt?transactionId=0001', $ready->url);
        self::assertInstanceOf(ReceiptUnavailable::class, $unavailable);
        self::assertSame('The receipt could not be generated', $unavailable->reason);
    }

    public function testCloseReceiptReadsIdleOrRefused(): void
    {
        $idle = WireReaders::toReceiptClosed(Wire::obj('{"success":true,"responseCode":"00","message":"OK","data":{"merchantReference":"CLOSE-1"}}'), 'X');
        $refused = WireReaders::toReceiptClosed(Wire::obj('{"success":false,"responseCode":"96","message":"Unsupported","errorList":["CLOSE_RECEIPT not supported"]}'), 'CLOSE-1');

        self::assertInstanceOf(ReceiptClosed::class, $idle);
        self::assertSame('CLOSE-1', $idle->merchantReference);
        self::assertInstanceOf(CloseReceiptRefused::class, $refused);
        self::assertSame('96', $refused->responseCode);
        self::assertSame('CLOSE_RECEIPT not supported', $refused->reason);
    }

    // ── Envelope ─────────────────────────────────────────────────────

    public function testTheEnvelopeNormalisesLegacyShapes(): void
    {
        $flat = EcrWireResponse::parse(Wire::obj('{"responseCode":"00","responseMessage":"Approved","approved":true,"amount":"000000001111"}'));
        $nested = EcrWireResponse::parse(Wire::obj('{"approved":true,"ecrResponse":{"success":true,"responseCode":"00","message":"Approved","data":{"rrn":"623807390109"},"errorList":[]}}'));
        $signed = EcrWireResponse::parse(Wire::obj('{"success":true,"data":{"merchantReference":"EA5C14BDB0A2","terminalSerial":"QQW"},"nonce":"82D8","secureHash":"ABC123"}'));

        self::assertTrue($flat->success);
        self::assertSame('Approved', $flat->message);
        self::assertTrue($nested->success);
        self::assertSame('623807390109', $nested->data?->str('rrn'));
        self::assertSame('82D8', $signed->nonce);
        self::assertSame('ABC123', $signed->secureHash);
        self::assertSame('QQW', $signed->terminalSerial());
    }

    public function testTheMerchantReferenceFallsBackThroughLegacyKeys(): void
    {
        self::assertSame('CURRENT', EcrWireResponse::parse(Wire::obj('{"success":true,"data":{"merchantReference":"CURRENT","merchantReferenceId":"LEGACY","requestId":"OLDER"}}'))->merchantReference());
        self::assertSame('LEGACY-1', EcrWireResponse::parse(Wire::obj('{"success":true,"data":{"merchantReferenceId":"LEGACY-1"}}'))->merchantReference());
        self::assertSame('OLDER', EcrWireResponse::parse(Wire::obj('{"success":true,"data":{"requestId":"OLDER"}}'))->merchantReference());
    }

    public function testErrorListEntriesAreTrimmedAndBlankOrNonTextOnesDropped(): void
    {
        $envelope = EcrWireResponse::parse(Wire::obj('{"success":false,"errorList":["  a  ","","   ",{"x":1},null,"b"]}'));

        self::assertSame(['a', 'b'], $envelope->errorList);
        self::assertSame("a\nb", $envelope->displayMessage());
    }

    public function testSuccessFallsBackToApprovedThenToTheResponseCode(): void
    {
        self::assertTrue(EcrWireResponse::parse(Wire::obj('{"success":"yes","approved":true}'))->success);
        self::assertTrue(EcrWireResponse::parse(Wire::obj('{"success":"yes","responseCode":"00"}'))->success);
        self::assertFalse(EcrWireResponse::parse(Wire::obj('{"success":"yes","responseCode":"51"}'))->success);
        self::assertFalse(EcrWireResponse::parse(Wire::obj('{}'))->success);
    }

    public function testDisplayMessageFromRaw(): void
    {
        self::assertSame('Insufficient funds', EcrWireResponse::displayMessageFromRaw('{"success":false,"message":"Declined","errorList":["Insufficient funds"]}', 'Declined'));
        foreach (['', 'not json', '[1]', 'null', '{"success":true}'] as $raw) {
            self::assertSame('fallback', EcrWireResponse::displayMessageFromRaw($raw, 'fallback'));
        }
    }
}
