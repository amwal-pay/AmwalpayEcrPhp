<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\Amount;
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrSdk;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Exception\InvalidRequestException;
use AmwalPay\Ecr\Internal\EcrMessage;
use AmwalPay\Ecr\Internal\MerchantReference;
use AmwalPay\Ecr\Internal\Validate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Amounts, references, receipt numbers and dates: what goes on the wire, and what is refused before it. */
final class AmountAndMessageTest extends TestCase
{
    /** @return iterable<array{string, int, string}> */
    public static function minorUnits(): iterable
    {
        yield ['1.234', 3, '000000001234'];
        yield ['0.001', 3, '000000000001'];
        yield ['961.100', 3, '000000961100'];
        yield ['961.1', 3, '000000961100'];
        yield ['1.23', 2, '000000000123'];
        yield ['1.2345', 3, '000000001235'];  // half away from zero
        yield ['1.2344', 3, '000000001234'];
        yield ['1.005', 2, '000000000101'];   // exact decimal: a float would give 100
        yield ['1234.5', 0, '000000001235'];
        yield ['0.0005', 3, '000000000001'];
        yield ['9.9995', 3, '000000010000'];
        yield ['  5  ', 3, '000000005000'];
        yield ['.5', 3, '000000000500'];
        yield ['999999999.999', 3, '999999999999'];
    }

    #[DataProvider('minorUnits')]
    public function testAmountsTravelAsTwelveDigitsOfMinorUnitsRoundedHalfAwayFromZero(string $amount, int $digits, string $wire): void
    {
        self::assertSame($wire, Amount::of($amount)->toWire($digits));
    }

    public function testWholeNumbersAreMajorUnits(): void
    {
        self::assertSame(5000, Amount::of(5)->toMinorUnits(3));
        self::assertSame('1.234', Amount::fromMinorUnits(1234, 3)->decimal);
        self::assertSame('1.230', Amount::of('1.23')->format(3));
    }

    /** @return iterable<string, array{string|int|float, int, string}> */
    public static function amountsTheWindowsSdkSendsAsTheyAre(): iterable
    {
        yield 'zero' => ['0', 3, '000000000000'];
        yield 'zero int' => [0, 3, '000000000000'];
        yield 'negative' => ['-1.000', 3, '0000000-1000'];
        yield 'negative int' => [-5, 3, '0000000-5000'];
        yield 'rounds to nothing' => ['0.0004', 3, '000000000000'];
        yield 'exponent' => ['1e3', 3, '000001000000'];
        yield 'more than 12 digits' => ['1000000000.000', 3, '1000000000000'];
        yield 'float' => [1.234, 3, '000000001234'];
        yield 'float half away from zero' => [1.005, 2, '000000000101'];
    }

    #[DataProvider('amountsTheWindowsSdkSendsAsTheyAre')]
    public function testAmountsAreSentAsTheWindowsSdkWritesThemWithoutJudgingThem(string|int|float $amount, int $digits, string $wire): void
    {
        self::assertSame($wire, Amount::of($amount)->toWire($digits));
    }

    /** @return iterable<string, array{string|float}> */
    public static function notNumbers(): iterable
    {
        yield 'thousands separator' => ['1,000.000'];
        yield 'comma decimal' => ['1,5'];
        yield 'currency sign' => ['OMR 1.000'];
        yield 'empty' => [''];
        yield 'NaN' => ['NaN'];
        yield 'NaN float' => [NAN];
    }

    #[DataProvider('notNumbers')]
    public function testTextThatIsNotANumberIsRefused(string|float $amount): void
    {
        $this->expectException(InvalidRequestException::class);
        Amount::of($amount);
    }

    public function testTheWebServiceAmountIsA64BitIntegerAsTheWindowsSdksCheckedCast(): void
    {
        self::assertSame(-1000, Amount::of('-1')->toMinorUnits(3));
        $this->expectException(\OverflowException::class);
        Amount::of('99999999999999999999')->toMinorUnits(3);
    }

    /** @return iterable<array{string, int, string}> */
    public static function majorUnits(): iterable
    {
        yield ['000000000365', 3, '0.365'];
        yield ['000000961100', 3, '961.100'];
        yield ['000000000123', 2, '1.23'];
        yield ['000000000123', 0, '123'];
        yield ['000000000000', 3, '0.000'];
        yield ['', 3, ''];
        yield ['1.234', 3, '1.234'];       // already major units: not shifted again
        yield ['5', 3, '0.005'];
        yield ['-500', 2, '-5.00'];
        yield ['-5', 3, '-0.005'];
        yield ['99999999999999999999', 3, '99999999999999999.999'];
    }

    #[DataProvider('majorUnits')]
    public function testMinorUnitsComeBackAsMajorUnitsKeepingEveryDigit(string $minor, int $digits, string $major): void
    {
        self::assertSame($major, Amount::majorUnits($minor, $digits));
    }

    public function testASaleCarriesAnAmountAndNoOriginal(): void
    {
        $json = self::build(EcrTransactionType::Sale, amount: '1.234')->json;

        self::assertSame('SALE', $json->str('messageType'));
        self::assertSame('000000001234', $json->str('amount'));
        self::assertSame('1', $json->str('version'));
        self::assertMatchesRegularExpression('/^[0-9]{14}$/', $json->str('transactionDateTime'));
        foreach (['stan', 'originalTerminalId', 'originalTransactionDate', 'originalMerchantReference', 'nonce', 'secureHash'] as $absent) {
            self::assertFalse($json->has($absent), $absent . ' must be left out, not sent empty');
        }
    }

    public function testAVoidCarriesTheOriginalButNoAmount(): void
    {
        $json = self::build(EcrTransactionType::Void, stan: '000024', originalTerminalId: '30001')->json;

        self::assertSame('VOID', $json->str('messageType'));
        self::assertSame('000024', $json->str('stan'));
        self::assertSame('30001', $json->str('originalTerminalId'));
        self::assertFalse($json->has('amount'), 'a void takes the original amount');
    }

    public function testARefundCarriesAmountOriginalAndDate(): void
    {
        $json = self::build(EcrTransactionType::Refund, amount: '0.500', stan: '000024', originalDate: '20260806')->json;

        self::assertSame('000000000500', $json->str('amount'));
        self::assertSame('000024', $json->str('stan'));
        self::assertSame('20260806', $json->str('originalTransactionDate'));
    }

    public function testOnlyAReadOnlyOperationNamesItsOriginalByReference(): void
    {
        $inquiry = self::build(EcrTransactionType::Inquiry, originalReference: 'ORD-88231', merchantReference: 'LOOKUP-1');
        $void = self::build(EcrTransactionType::Void, stan: '000024', originalReference: 'ORD-88231');

        self::assertSame('ORD-88231', $inquiry->json->str('originalMerchantReference'));
        self::assertSame('LOOKUP-1', $inquiry->merchantReference);
        self::assertFalse($void->json->has('originalMerchantReference'));
    }

    public function testTheConfigIdentityCurrencyAndTimezoneAreCarried(): void
    {
        $message = EcrMessage::build(
            EcrTransactionType::Sale,
            new EcrConfig(ecrId: 'TILL-7', currencyCode: '840', minorUnitDigits: 2, timezone: 'Asia/Muscat'),
            'TW9',
            Amount::of('1.23'),
        );

        self::assertSame('TILL-7', $message->json->str('ecrId'));
        self::assertSame('840', $message->json->str('currencyCode'));
        self::assertSame('TW9', $message->json->str('terminalSerial'));
        self::assertSame('000000000123', $message->json->str('amount'));
        $muscat = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Muscat')))->format('YmdH');
        self::assertStringStartsWith(substr($muscat, 0, 8), $message->json->str('transactionDateTime'));
    }

    public function testTheStampIsTheMachinesLocalTimeNotPhpsDefaultZone(): void
    {
        $default = date_default_timezone_get();
        // A zone no till runs in: the stamp must not follow it.
        date_default_timezone_set('Pacific/Kiritimati');
        try {
            $message = EcrMessage::build(EcrTransactionType::Sale, new EcrConfig(), 'TW9', Amount::of('1.23'));
        } finally {
            date_default_timezone_set($default);
        }

        $local = (new \DateTimeImmutable('now', EcrSdk::localTimeZone()))->format('YmdHi');
        self::assertNotSame('Pacific/Kiritimati', EcrSdk::localTimeZone()->getName());
        self::assertSame(substr($local, 0, 10), substr($message->json->str('transactionDateTime'), 0, 10));
    }

    public function testAReferenceIsTheCallersOwnTrimmedOrAFreshOne(): void
    {
        self::assertSame('ORDER-2026-0817-001', MerchantReference::resolve('ORDER-2026-0817-001'));
        self::assertSame('ORDER-1', MerchantReference::resolve('  ORDER-1  '));
        // string.Trim(): Unicode white space goes, NUL stays and is refused.
        self::assertSame('ORDER-1', MerchantReference::resolve("\xC2\xA0ORDER-1\f"));
        self::assertMatchesRegularExpression('/^[0-9A-F]{12}$/', MerchantReference::resolve('   '));
        self::assertNotSame(MerchantReference::resolve(''), MerchantReference::resolve(''));
        self::assertSame(str_repeat('X', 32), MerchantReference::resolve(str_repeat('X', 32)));
    }

    /** @return iterable<string, array{string}> */
    public static function badReferences(): iterable
    {
        yield 'too long' => [str_repeat('X', 33)];
        yield 'ampersand' => ['ORDER&1'];
        yield 'equals' => ['ORDER=1'];
        yield 'space' => ['ORDER 1'];
        yield 'newline' => ["ORDER\n1"];
        yield 'non-ascii' => ['ORDERé1'];
        yield 'control' => ["ORDER\x011"];
        yield 'trailing NUL' => ["ORDER-1\0"];
    }

    #[DataProvider('badReferences')]
    public function testAReferenceTheWireCannotCarryIsRefused(string $reference): void
    {
        $this->expectException(InvalidRequestException::class);
        MerchantReference::resolve($reference);
    }

    /** @return iterable<string, array{string, string}> */
    public static function receiptNumbers(): iterable
    {
        yield 'short' => ['24', '000024'];
        yield 'padded' => [' 000024 ', '000024'];
        yield 'six digits' => ['123456', '123456'];
        yield 'stray characters are dropped' => ['A-24', '000024'];
        yield 'longer than six' => ['1234567', '1234567'];
        yield 'no digits' => ['abc', ''];
        yield 'only zeros' => ['0000', ''];
        yield 'arabic-indic digits' => ['٢٤', '0000٢٤'];
    }

    #[DataProvider('receiptNumbers')]
    public function testReceiptNumbersArePaddedLikeTheWindowsSdk(string $stan, string $padded): void
    {
        self::assertSame($padded, Validate::paddedStan($stan));
    }

    public function testOptionalFieldsAreSentAsGivenWhenNotBlank(): void
    {
        $json = self::build(EcrTransactionType::Refund, amount: '1', stan: 'abc', originalTerminalId: ' T1 ', originalDate: '2026-08-06')->json;

        // A receipt number with no digits still goes, empty, as in the Windows SDK.
        self::assertSame('', $json->str('stan'));
        self::assertSame(' T1 ', $json->str('originalTerminalId'));
        self::assertSame('2026-08-06', $json->str('originalTransactionDate'));
        self::assertFalse(self::build(EcrTransactionType::Refund, amount: '1', stan: '24', originalDate: " \u{A0}")->json->has('originalTransactionDate'));
    }

    public function testTransactionTypeFlagsMatchTheWindowsSdk(): void
    {
        self::assertSame([EcrTransactionType::Sale, EcrTransactionType::Void, EcrTransactionType::Refund, EcrTransactionType::Inquiry], EcrTransactionType::menuOptions());
        self::assertSame('CLOSE_RECEIPT', EcrTransactionType::CloseReceipt->messageType());
        self::assertTrue(EcrTransactionType::Refund->requiresAmount());
        self::assertFalse(EcrTransactionType::Void->requiresAmount());
        self::assertTrue(EcrTransactionType::Receipt->requiresOriginalStan());
        self::assertFalse(EcrTransactionType::Void->requiresOriginalDate());
        self::assertFalse(EcrTransactionType::Inquiry->movesMoney());
        self::assertFalse(EcrTransactionType::CloseReceipt->allowsOtherTerminal());
    }

    private static function build(
        EcrTransactionType $type,
        ?string $amount = null,
        string $stan = '',
        string $originalTerminalId = '',
        string $originalDate = '',
        string $originalReference = '',
        string $merchantReference = '',
    ): EcrMessage {
        return EcrMessage::build($type, new EcrConfig(), 'TW1', $amount === null ? null : Amount::of($amount), $stan, $originalTerminalId, $originalDate, $originalReference, $merchantReference);
    }
}
