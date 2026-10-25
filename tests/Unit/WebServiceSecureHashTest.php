<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\WebService\WebServiceMessage;
use AmwalPay\Ecr\WebService\WebServiceRequests;
use AmwalPay\Ecr\WebService\WebServiceSecureHash;
use PHPUnit\Framework\TestCase;

/**
 * Web Service signing and the Hub wire shapes (POS-552), pinned byte for byte
 * to the Windows SDK's expected bodies.
 */
final class WebServiceSecureHashTest extends TestCase
{
    private const SALE_SIGNING_TEXT = 'amount=000000000111&currencyCode=512&ecrId=ECR01&merchantId=8899&merchantReference=EA5C14BDB0A2'
        . '&messageType=SALE&requestDateTime=20260826115847&terminalId=999&terminalSerial=QQW&transactionDateTime=20260826115847&version=1';

    public function testKnownAnswerForTheWebServiceSigningString(): void
    {
        self::assertSame('FEF8D18506A6AC035DC825A1DBA4413EE885898BBCE27EE4A681683FFAADC61E', WebServiceSecureHash::calcHash(self::SALE_SIGNING_TEXT, TestKeys::WEB_SERVICE));
        self::assertSame('E4190003B5E8B496DAFE11C464F6BDA387735F1050C1DE69DCC4B2848D19F7C2', WebServiceSecureHash::calcHash(self::SALE_SIGNING_TEXT, TestKeys::WIFI));
    }

    public function testASaleBodyIsSignedOverExactlyTheDocumentedSigningString(): void
    {
        $body = WebServiceRequests::sale(8899, 999, 'QQW', 111, 'EA5C14BDB0A2', transactionDateTime: '20260826115847', secureHashKey: TestKeys::WEB_SERVICE);

        self::assertSame(self::SALE_SIGNING_TEXT, WebServiceSecureHash::compose($body));
        self::assertSame('FEF8D18506A6AC035DC825A1DBA4413EE885898BBCE27EE4A681683FFAADC61E', $body->str('secureHashValue'));
    }

    public function testCalcHashIsEmptyForAnInvalidSecret(): void
    {
        self::assertSame('', WebServiceSecureHash::calcHash('amount=1', 'not-hex'));
        self::assertSame('', WebServiceSecureHash::calcHash('amount=1', ''));
    }

    public function testSaleWireShape(): void
    {
        self::assertSame(
            '{"version":1,"messageType":"SALE","merchantId":8899,"terminalId":999,"terminalSerial":"QQW","ecrId":"ECR01","merchantReference":"EA5C14BDB0A2","currencyCode":"512","transactionDateTime":"20260826115847","requestDateTime":"20260826115847","amount":"000000000111"}',
            WebServiceRequests::sale(8899, 999, 'QQW', 111, 'EA5C14BDB0A2', transactionDateTime: '20260826115847')->toJson(),
        );
    }

    public function testVoidWireShape(): void
    {
        self::assertSame(
            '{"version":1,"messageType":"VOID","merchantId":8899,"terminalId":999,"terminalSerial":"QQW","ecrId":"ECR01","merchantReference":"77619797A075","currencyCode":"512","transactionDateTime":"20260826120107","requestDateTime":"20260826120107","stan":"000008","originalMerchantReference":"77619797A075","originalTerminalId":999}',
            WebServiceRequests::void(8899, 999, 'QQW', '8', '77619797A075', transactionDateTime: '20260826120107')->toJson(),
        );
    }

    public function testRefundWireShape(): void
    {
        self::assertSame(
            '{"version":1,"messageType":"REFUND","merchantId":8899,"terminalId":999,"terminalSerial":"QQW","ecrId":"ECR01","merchantReference":"3FD2197EB935","currencyCode":"512","transactionDateTime":"20260826120556","requestDateTime":"20260826120556","amount":"000000000022","stan":"000011","originalTransactionDate":"20260826","originalMerchantReference":"3FD2197EB935","originalTerminalId":999}',
            WebServiceRequests::refund(8899, 999, 'QQW', 22, '11', '20260826', '3FD2197EB935', transactionDateTime: '20260826120556')->toJson(),
        );
    }

    public function testInquiryByReceiptWireShape(): void
    {
        self::assertSame(
            '{"version":1,"messageType":"INQUIRY","merchantId":8899,"terminalId":999,"terminalSerial":"QQW","ecrId":"ECR01","merchantReference":"0B574EB48120","currencyCode":"512","transactionDateTime":"20260826120654","requestDateTime":"20260826120654","stan":"000013","originalTransactionDate":"20260826","originalMerchantReference":"","originalTerminalId":999}',
            WebServiceRequests::transactionStatus(8899, 999, 'QQW', '13', '20260826', '0B574EB48120', transactionDateTime: '20260826120654')->toJson(),
        );
    }

    public function testInquiryByMerchantReferenceWireShape(): void
    {
        self::assertSame(
            '{"version":1,"messageType":"INQUIRY","merchantId":8899,"terminalId":999,"terminalSerial":"QQW","ecrId":"ECR01","merchantReference":"INQ-NEW-REF01","currencyCode":"512","transactionDateTime":"20260826120654","requestDateTime":"20260826120654","stan":"","originalTransactionDate":"20260826","originalMerchantReference":"ORD-88231ABC","originalTerminalId":999}',
            WebServiceRequests::transactionStatus(8899, 999, 'QQW', '', '20260826', 'INQ-NEW-REF01', transactionDateTime: '20260826120654', originalMerchantReference: 'ORD-88231ABC')->toJson(),
        );
    }

    public function testCloseReceiptWireShapeHasNoMessageTypeOrCurrency(): void
    {
        $unsigned = WebServiceRequests::closeReceipt(10001, 20003, 'SN-WS-0002', 'BB13726B7E261874', 'AC4C243243C6A7C5', transactionDateTime: '20261014113326')->toJson();

        self::assertSame(
            '{"version":1,"merchantId":10001,"terminalId":20003,"terminalSerial":"SN-WS-0002","ecrId":"ECR01","merchantReference":"AC4C243243C6A7C5","transactionDateTime":"20261014113326","requestDateTime":"20261014113326","originalMerchantReference":"BB13726B7E261874"}',
            $unsigned,
        );
        $signed = WebServiceRequests::closeReceipt(10001, 20003, 'SN-WS-0002', 'BB13726B7E261874', 'AC4C243243C6A7C5', transactionDateTime: '20261014113326', secureHashKey: TestKeys::WEB_SERVICE);
        self::assertSame(64, \strlen($signed->str('secureHashValue')));
        self::assertStringNotContainsString('secureHashValue', WebServiceSecureHash::compose($signed));
    }

    public function testWithHashDoesNotMutateItsInputAndReplacesAnEarlierHash(): void
    {
        $body = WebServiceRequests::sale(8899, 999, 'QQW', 111, 'EA5C14BDB0A2', transactionDateTime: '20260826115847');

        $once = WebServiceSecureHash::withHash($body, TestKeys::WIFI);
        $twice = WebServiceSecureHash::withHash($once, TestKeys::WEB_SERVICE);

        self::assertFalse($body->has('secureHashValue'), "the caller's body is left alone");
        self::assertNotSame($once->str('secureHashValue'), $twice->str('secureHashValue'));
        self::assertSame(WebServiceSecureHash::sign($body, TestKeys::WEB_SERVICE), $twice->str('secureHashValue'));
        self::assertSame($body, WebServiceSecureHash::withHash($body, ''), 'an empty secret leaves the body unchanged');
    }

    public function testAnInvalidSecretIsRefusedRatherThanSendingAnUnsignedBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebServiceSecureHash::withHash(WebServiceRequests::sale(1, 2, 'S', 1, 'R'), 'not-hex');
    }

    public function testAReceiptIsNotAWebServiceOperation(): void
    {
        $this->expectException(\LogicException::class);
        WebServiceMessage::build(EcrTransactionType::Receipt, 1, 2, 'S', '');
    }

    public function testTheTwoTransportsSignDifferentFields(): void
    {
        self::assertSame('secureHashValue', WebServiceSecureHash::FIELD);
        self::assertSame('secureHash', SecureHash::FIELD);
    }
}
