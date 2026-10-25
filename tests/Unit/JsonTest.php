<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonArray;
use AmwalPay\Ecr\Json\JsonException;
use AmwalPay\Ecr\Json\JsonNumber;
use AmwalPay\Ecr\Json\JsonObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reader keeps numbers as written and refuses anything ambiguous, because
 * the terminal signs the text it sends. Mirrors WireEncodingTests and
 * EcrMessageCodecTests in the Windows SDK.
 */
final class JsonTest extends TestCase
{
    public function testANumberIsReadBackExactlyAsWritten(): void
    {
        $json = Json::parseObject('{"a":0.258,"b":1E3,"c":-0.0,"d":"7","e":961.10,"big":12345678901234567890}');

        self::assertSame('0.258', $json->str('a'));
        self::assertSame('1E3', $json->str('b'));
        self::assertSame('-0.0', $json->str('c'));
        self::assertSame('7', $json->str('d'));
        self::assertSame('961.10', $json->str('e'));
        self::assertSame('12345678901234567890', $json->str('big'));
        self::assertInstanceOf(JsonNumber::class, $json->get('e'));
    }

    public function testOnlyTheLiteralsTrueAndFalseAreBooleans(): void
    {
        $json = Json::parseObject('{"a":true,"b":false,"c":"true","d":"false","e":1,"f":"yes","g":null,"h":{}}');

        self::assertTrue($json->flag('a'));
        self::assertFalse($json->flag('b'));
        self::assertTrue($json->flag('c'));
        self::assertFalse($json->flag('d'));
        self::assertFalse($json->flag('e'));
        self::assertFalse($json->flag('f'));
        self::assertFalse($json->flag('g'));
        self::assertFalse($json->flag('h'));
        self::assertFalse($json->flag('missing'));
        self::assertNull($json->strictBool('e'));
        self::assertNull($json->strictBool('missing'));
        self::assertFalse($json->strictBool('b'));
    }

    public function testAJsonNullReadsAsAbsentNotAsTheWordNull(): void
    {
        $json = Json::parseObject('{"authCode":null,"rrn":"1"}');

        self::assertSame('', $json->str('authCode'));
        self::assertSame('', $json->str('missing'));
        self::assertSame('1', $json->str('rrn'));
    }

    public function testANestedValueReadAsTextIsItsCompactJson(): void
    {
        $json = Json::parseObject('{"data":{"a":1},"list":[1,2]}');

        self::assertSame('{"a":1}', $json->str('data'));
        self::assertSame('[1,2]', $json->str('list'));
    }

    public function testNonAsciiAndMarkupAreWrittenAsThemselves(): void
    {
        $text = Json::write(new JsonObject(['terminalSerial' => 'Café ☕ تمت', 'ecrId' => "<till>&'+`/"]));

        self::assertSame('{"terminalSerial":"Café ☕ تمت","ecrId":"<till>&\'+`/"}', $text);
        self::assertStringNotContainsString('\\u', $text);
    }

    public function testQuotesBackslashesAndControlCharactersAreEscapedAndRoundTrip(): void
    {
        $original = "say \"hi\" \\ done\n\tend";
        $text = Json::write(new JsonObject(['ecrId' => $original]));

        self::assertStringContainsString('\\"hi\\"', $text);
        self::assertStringContainsString('\\n', $text);
        self::assertSame($original, Json::parseObject($text)->str('ecrId'));
    }

    public function testEscapedUnicodeIsReadAsTheTextItSpells(): void
    {
        $json = Json::parseObject('{"message":"café تم","emoji":"😀"}');

        self::assertSame('café تم', $json->str('message'));
        self::assertSame("\u{1F600}", $json->str('emoji'));
    }

    public function testKeysThatLookLikeNumbersStayText(): void
    {
        $json = Json::parseObject('{"1":"a","0":"b"}');

        self::assertSame(['1', '0'], $json->keys());
        self::assertSame('{"1":"a","0":"b"}', $json->toJson());
    }

    public function testFloatsAreRefusedWhenBuildingAMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JsonObject(['amount' => 1.234]);
    }

    public function testInvalidUtf8IsRefusedWhenWriting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Json::write(new JsonObject(['ecrId' => "\xC3\x28"]));
    }

    public function testPrettyPrintIsIndented(): void
    {
        $text = Json::pretty(new JsonObject(['a' => 1, 'b' => new JsonObject(['c' => true]), 'd' => new JsonArray(), 'e' => new JsonObject()]));

        self::assertSame("{\n  \"a\": 1,\n  \"b\": {\n    \"c\": true\n  },\n  \"d\": [],\n  \"e\": {}\n}", $text);
    }

    /** @return iterable<string, array{string}> */
    public static function notAnObject(): iterable
    {
        yield 'null' => ['null'];
        yield 'array' => ['[1,2]'];
        yield 'string' => ['"text"'];
        yield 'number' => ['42'];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('notAnObject')]
    public function testValidJsonThatIsNotAnObjectIsRefused(string $text): void
    {
        $this->expectException(JsonException::class);
        Json::parseObject($text);
    }

    /** @return iterable<string, array{string}> */
    public static function repeatedKeys(): iterable
    {
        yield 'top level' => ['{"a":1,"a":2}'];
        yield 'success twice' => ['{"success":false,"success":true,"responseCode":"00"}'];
        yield 'nested' => ['{"data":{"x":1,"x":2}}'];
        yield 'inside an array' => ['{"list":[{"x":1,"x":2}]}'];
    }

    #[DataProvider('repeatedKeys')]
    public function testARepeatedKeyAnywhereIsRefusedNeverLastWins(string $text): void
    {
        $this->expectException(JsonException::class);
        Json::parseObject($text);
    }

    /** @return iterable<string, array{string}> */
    public static function lenient(): iterable
    {
        yield 'trailing comma' => ['{"a":1,}'];
        yield 'comment' => ['{"a":1 /* note */}'];
        yield 'single quotes' => ["{'a':1}"];
        yield 'leading zero' => ['{"a":01}'];
        yield 'bare word' => ['{"a":yes}'];
        yield 'NaN' => ['{"a":NaN}'];
        yield 'raw newline in string' => ["{\"a\":\"x\ny\"}"];
        yield 'invalid escape' => ['{"a":"\x"}'];
        yield 'lone surrogate' => ['{"a":"\ud800"}'];
        yield 'invalid utf-8' => ["{\"a\":\"\xC3\x28\"}"];
        yield 'byte order mark' => ["\xEF\xBB\xBF{\"a\":1}"];
        yield 'trailing text' => ['{"a":1} x'];
        yield 'unterminated' => ['{"a":"x'];
        yield 'two objects' => ['{"a":1}{"b":2}'];
    }

    #[DataProvider('lenient')]
    public function testLenientJsonIsNotAccepted(string $text): void
    {
        $this->expectException(JsonException::class);
        Json::parseObject($text);
    }

    public function testNestingDeeperThanTheLimitIsRefused(): void
    {
        $this->expectException(JsonException::class);
        Json::parseObject(str_repeat('{"a":', 70) . '1' . str_repeat('}', 70));
    }

    public function testComposeSignsScalarsSortedOrdinallyAndSkipsTheRest(): void
    {
        $json = Json::parseObject('{"a":"1","B":"2","_c":"3","Z":"4","n":null,"o":{"x":1},"l":[1],"t":true,"secureHash":"X"}');

        self::assertSame('B=2&Z=4&_c=3&a=1&t=true', Json::compose($json, 'secureHash'));
    }
}
