<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\Amount;
use AmwalPay\Ecr\Channel\EcrFrames;
use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Internal\EcrMessage;
use AmwalPay\Ecr\Internal\EcrMessageCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The framing is a contract with the terminal, which has its own copy: these pin the bytes. */
final class FramesTest extends TestCase
{
    public function testWrapPutsABigEndianLengthInFrontOfTheBody(): void
    {
        self::assertSame("\x00\x03\x01\x02\x03", EcrFrames::wrap("\x01\x02\x03"));
        self::assertSame("\x00\x00", EcrFrames::wrap(''));
    }

    /** @return iterable<array{int, int, int}> */
    public static function boundaries(): iterable
    {
        yield [255, 0x00, 0xFF];
        yield [256, 0x01, 0x00];
        yield [300, 0x01, 0x2C];
        yield [65535, 0xFF, 0xFF];
    }

    #[DataProvider('boundaries')]
    public function testTheHeaderUsesBothBytesAtTheBoundaries(int $length, int $high, int $low): void
    {
        $packet = EcrFrames::wrap(str_repeat("\0", $length));

        self::assertSame($length + 2, \strlen($packet));
        self::assertSame($high, \ord($packet[0]));
        self::assertSame($low, \ord($packet[1]));
        self::assertSame($length, EcrFrames::bodyLength($packet));
    }

    public function testABodyTooLongForTheHeaderIsRefusedNotTruncated(): void
    {
        $this->expectException(EcrIOException::class);
        $this->expectExceptionMessage('does not fit a frame');
        EcrFrames::wrap(str_repeat('x', EcrFrames::MAX_BODY_BYTES + 1));
    }

    public function testBodyLengthIsUnsigned(): void
    {
        self::assertSame(32768, EcrFrames::bodyLength("\x80\x00"));
        self::assertSame(65535, EcrFrames::bodyLength("\xFF\xFF"));
    }

    public function testReadBodyReturnsExactlyOneMessageAndLeavesTheRest(): void
    {
        $bytes = "\x00\x02AB\x00\x01C";
        $offset = 0;

        self::assertSame('AB', EcrFrames::readBodyFrom($bytes, $offset));
        self::assertSame(4, $offset);
        self::assertSame('C', EcrFrames::readBodyFrom($bytes, $offset));
    }

    public function testTheLengthCountsBytesNotCharacters(): void
    {
        $packet = EcrFrames::wrap('é€');

        self::assertSame(5, \ord($packet[1]));
        self::assertSame('é€', EcrFrames::readBodyFrom($packet));
    }

    public function testAStreamThatEndsEarlyIsAClosedConnection(): void
    {
        foreach (["\x00" => 'response header', '' => 'response header', "\x00\x08AB" => 'response body'] as $bytes => $what) {
            try {
                EcrFrames::readBodyFrom((string) $bytes);
                self::fail('read a truncated frame');
            } catch (EcrIOException $e) {
                self::assertStringContainsString('closed the connection while sending the ' . $what, $e->getMessage());
            }
        }
    }

    public function testAZeroLengthHeaderIsAnEmptyMessage(): void
    {
        $this->expectExceptionMessage('empty message');
        EcrFrames::readBodyFrom("\x00\x00");
    }

    public function testAnEncodedMessageRoundTripsAndALargeOneUsesTheHighByte(): void
    {
        $big = EcrMessage::build(EcrTransactionType::Sale, new EcrConfig(ecrId: str_repeat('E', 400)), 'TW1', Amount::of('1.234'));
        $packet = EcrMessageCodec::encode($big);

        self::assertNotSame(0, \ord($packet[0]));
        $decoded = EcrMessageCodec::parse(EcrFrames::readBodyFrom($packet));
        self::assertSame(str_repeat('E', 400), $decoded->str('ecrId'));
        self::assertSame('000000001234', $decoded->str('amount'));
    }

    public function testARequestOverTheFrameLimitIsRefused(): void
    {
        $huge = EcrMessage::build(EcrTransactionType::Sale, new EcrConfig(ecrId: str_repeat('E', EcrFrames::MAX_BODY_BYTES)), 'TW1', Amount::of('1'));

        $this->expectException(EcrIOException::class);
        EcrMessageCodec::encode($huge);
    }

    public function testAnUnreadableAnswerIsReportedAsNotValidJson(): void
    {
        foreach (['not json', 'null', '[1,2]', '"text"', '42', '', '{"a":1,"a":2}', "\xFF\xFE"] as $body) {
            try {
                EcrMessageCodec::parse($body);
                self::fail('parsed ' . $body);
            } catch (EcrIOException $e) {
                self::assertStringContainsString('not valid JSON', $e->getMessage());
            }
        }
    }

    public function testUnknownFieldsAreKept(): void
    {
        self::assertSame('x', EcrMessageCodec::parse('{"responseCode":"00","somethingNew":"x"}')->str('somethingNew'));
    }
}
