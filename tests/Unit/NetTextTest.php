<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\Internal\EcrMessageCodec;
use AmwalPay\Ecr\Internal\NetText;
use AmwalPay\Ecr\Internal\ResponseEnvelope;
use AmwalPay\Ecr\Json\Json;
use PHPUnit\Framework\TestCase;

/** The places where .NET's string rules decide what the Windows SDK does. */
final class NetTextTest extends TestCase
{
    public function testTrimIsStringTrimUnicodeWhiteSpaceButNotNul(): void
    {
        self::assertSame('A B', NetText::trim("\xC2\xA0\f A B\u{2003}\n"));
        self::assertSame("A\0", NetText::trim(" A\0"));
        self::assertTrue(NetText::isBlank("\u{3000}\t"));
        self::assertFalse(NetText::isBlank("\0"));
    }

    public function testLengthCountsUtf16CodeUnits(): void
    {
        self::assertSame(3, NetText::length('abc'));
        self::assertSame(1, NetText::length('é'));
        self::assertSame(2, NetText::length("\u{1F600}"));
    }

    public function testInvalidUtf8InAnAnswerBecomesTheReplacementCharacter(): void
    {
        self::assertSame("a\u{FFFD}b", NetText::scrubUtf8("a\xFFb"));
        self::assertSame("\u{FFFD}", NetText::scrubUtf8("\xE2\x82"), 'a truncated sequence is one replacement');
        self::assertSame("\u{FFFD}\u{FFFD}", NetText::scrubUtf8("\xC0\xAF"));

        $answer = EcrMessageCodec::parse("{\"success\":true,\"responseCode\":\"00\",\"message\":\"\xFF\"}");
        self::assertSame("\u{FFFD}", $answer->str('message'));
    }

    public function testAnUnreadableAnswerIsQuotedInFull(): void
    {
        $body = str_repeat('x', 300);
        try {
            EcrMessageCodec::parse($body);
            self::fail('parsed');
        } catch (EcrIOException $e) {
            self::assertSame('Terminal returned a message that is not valid JSON: ' . $body, $e->getMessage());
        }
    }

    public function testEnvironmentNamesAndErrorListsAreTrimmedAsInDotNet(): void
    {
        self::assertSame(EcrEnvironment::PROD, EcrEnvironment::fromName("\xC2\xA0prod"));
        self::assertSame(['Card declined'], ResponseEnvelope::errorList(Json::parseObject("{\"errorList\":[\"\xC2\xA0\",\" Card declined \"]}")));
    }
}
