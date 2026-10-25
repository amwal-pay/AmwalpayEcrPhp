<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Unit;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Amount;
use AmwalPay\Ecr\Internal\EcrMessage;
use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Json\JsonArray;
use AmwalPay\Ecr\Json\JsonObject;
use AmwalPay\Ecr\Tests\Support\TestKeys;
use AmwalPay\Ecr\Tests\Support\Wire;
use PHPUnit\Framework\TestCase;

/**
 * The Wi-Fi signing contract with the terminal. Known answers are the Windows
 * SDK's, computed with Python's hmac and from RFC 4231, so a bug here cannot
 * also be in the oracle.
 */
final class SecureHashTest extends TestCase
{
    public function testFieldsAreSignedInSortedOrder(): void
    {
        $json = new JsonObject(['messageType' => 'SALE', 'amount' => '000000001234', 'version' => 1]);

        self::assertSame('amount=000000001234&messageType=SALE&version=1', SecureHash::compose($json));
    }

    public function testInsertionOrderDoesNotChangeTheSignature(): void
    {
        self::assertSame(
            SecureHash::compose(new JsonObject(['b' => '2', 'a' => '1'])),
            SecureHash::compose(new JsonObject(['a' => '1', 'b' => '2'])),
        );
    }

    public function testTheSortIsOrdinalNotCultureAware(): void
    {
        self::assertSame('B=2&Z=4&_c=3&a=1', SecureHash::compose(new JsonObject(['a' => '1', 'B' => '2', '_c' => '3', 'Z' => '4'])));
    }

    public function testTheSignatureFieldIsNeverSigned(): void
    {
        self::assertSame(
            SecureHash::compose(new JsonObject(['amount' => '1'])),
            SecureHash::compose(new JsonObject(['amount' => '1', 'secureHash' => 'WHATEVER'])),
        );
    }

    public function testNestedDataNullsAndArraysAreLeftOut(): void
    {
        $json = new JsonObject([
            'amount' => '1',
            'data' => new JsonObject(['transactionId' => 'x']),
            'list' => new JsonArray(['a', 'b']),
            'missing' => null,
        ]);

        self::assertSame('amount=1', SecureHash::compose($json));
    }

    public function testBooleansAndNumbersAreSignedAsWritten(): void
    {
        self::assertSame('responseCode=00&success=true', SecureHash::compose(new JsonObject(['success' => true, 'responseCode' => '00'])));
        self::assertSame(
            'amount=961.1&authorizeAmount=50.0&big=12345678901234567890&terminalId=30001&version=1',
            SecureHash::compose(Wire::obj('{"version":1,"terminalId":30001,"amount":961.1,"authorizeAmount":50.0,"big":12345678901234567890}')),
        );
    }

    public function testKnownAnswerForASignedSaleUnderTheWifiKey(): void
    {
        $payload = 'amount=000000001234&messageType=SALE&version=1';

        self::assertSame('9425F9125C871197E5D0A6B88E4F9E6E29D1E0E49C96AA3D88A5E34D6FB9D45A', SecureHash::sign($payload, TestKeys::WIFI));
        self::assertSame('0DBE81E20B691AF85CE3A70E4047D6B10DC40514674E59876DF8200745AEA220', SecureHash::sign($payload, TestKeys::WIFI_OTHER));
    }

    public function testKnownAnswerForASignedResponseEnvelope(): void
    {
        $envelope = new JsonObject([
            'success' => true,
            'responseCode' => '00',
            'message' => 'Approved',
            'nonce' => '82D83D6C05C1FBFA6AE72AE7C644D614',
            'data' => new JsonObject(['merchantReference' => 'EA5C14BDB0A2']),
            'errorList' => new JsonArray(),
        ]);
        $payload = SecureHash::compose($envelope);

        self::assertSame('message=Approved&nonce=82D83D6C05C1FBFA6AE72AE7C644D614&responseCode=00&success=true', $payload);
        self::assertSame('0E060F03F6A461735CB6C9A82AF3D3AD1EAB983359C2B7ED3D58E0AE76C15A41', SecureHash::sign($payload, TestKeys::WIFI));
    }

    public function testRfc4231Cases(): void
    {
        self::assertSame('B0344C61D8DB38535CA8AFCEAF0BF12B881DC200C9833DA726E9376C2E32CFF7', SecureHash::sign('Hi There', str_repeat('0b', 20)));
        self::assertSame('5BDCC146BF60754E6A042426089575C75A003F089D2739839DEC58B964EC3843', SecureHash::sign('what do ya want for nothing?', '4a656665'));
    }

    public function testTheKeyIsDecodedFromHexNotUsedAsText(): void
    {
        $asHex = SecureHash::sign('a=1', TestKeys::WIFI);

        self::assertSame(strtoupper(hash_hmac('sha256', 'a=1', str_repeat("\xAA", 16))), $asHex);
        self::assertNotSame(strtoupper(hash_hmac('sha256', 'a=1', TestKeys::WIFI)), $asHex);
    }

    public function testAKeyThatIsNotHexIsRefused(): void
    {
        foreach (['not-hex!!', 'abc', ''] as $key) {
            try {
                SecureHash::sign('a=1', $key);
                self::fail('accepted ' . $key);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testVerification(): void
    {
        $signed = self::signed(new JsonObject(['amount' => '000000001234']), TestKeys::WIFI);
        $tampered = new JsonObject(['amount' => '000000009999', 'secureHash' => $signed->str('secureHash')]);
        $lower = new JsonObject(['amount' => '000000001234', 'secureHash' => strtolower($signed->str('secureHash'))]);
        $truncated = new JsonObject(['amount' => '000000001234', 'secureHash' => substr($signed->str('secureHash'), 0, 62)]);

        self::assertTrue(SecureHash::verify($signed, TestKeys::WIFI));
        self::assertFalse(SecureHash::verify($signed, TestKeys::WIFI_OTHER));
        self::assertFalse(SecureHash::verify($tampered, TestKeys::WIFI));
        self::assertTrue(SecureHash::verify($lower, TestKeys::WIFI), 'hex case does not matter');
        self::assertFalse(SecureHash::verify($truncated, TestKeys::WIFI));
        self::assertFalse(SecureHash::verify(new JsonObject(['amount' => '1']), TestKeys::WIFI), 'unsigned never verifies');
    }

    public function testASignedAnswerSurvivesAWireRoundTripButNestedDataIsNotCovered(): void
    {
        $answer = self::signed(Wire::approval('82D83D6C05C1FBFA6AE72AE7C644D614'), TestKeys::WIFI);
        $reparsed = Wire::obj($answer->toJson());
        $altered = clone $answer;
        $altered->obj('data')?->set('amount', '999.999');

        self::assertTrue(SecureHash::verify($reparsed, TestKeys::WIFI));
        // Documented limitation of the agreed format: `data` is not signed.
        self::assertTrue(SecureHash::verify($altered, TestKeys::WIFI));
    }

    public function testASignatureOverEscapedUnicodeVerifiesAgainstTheDecodedText(): void
    {
        $signature = SecureHash::sign('message=café&nonce=N1', TestKeys::WIFI);
        $wire = '{"message":"café","nonce":"N1","secureHash":"' . $signature . '"}';

        self::assertTrue(SecureHash::verify(Wire::obj($wire), TestKeys::WIFI));
    }

    public function testNoncesAre128BitsOfUppercaseHexAndDoNotRepeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $seen[SecureHash::newNonce()] = true;
        }

        self::assertCount(500, $seen);
        self::assertMatchesRegularExpression('/^[0-9A-F]{32}$/', SecureHash::newNonce());
    }

    public function testAMessageIsUnsignedWithoutAKeyAndSignedWithANonceWithOne(): void
    {
        $unsigned = self::build(new EcrConfig())->json;
        $signed = self::build(new EcrConfig(secureHashKey: TestKeys::WIFI));

        self::assertFalse($unsigned->has('secureHash'));
        self::assertFalse($unsigned->has('nonce'), 'a nonce without a signature could just be changed');
        self::assertSame(32, \strlen($signed->json->str('nonce')));
        self::assertSame($signed->json->str('nonce'), $signed->nonce());
        self::assertTrue(SecureHash::verify($signed->json, TestKeys::WIFI));
    }

    public function testASignedMessageIsIndependentlyReproducibleFromTheWireText(): void
    {
        $wire = self::build(new EcrConfig(secureHashKey: TestKeys::WIFI))->json->toJson();
        $parsed = json_decode($wire, true);
        $hash = $parsed['secureHash'];
        unset($parsed['secureHash']);
        ksort($parsed, \SORT_STRING);
        $pairs = [];
        foreach ($parsed as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        self::assertSame(strtoupper(hash_hmac('sha256', implode('&', $pairs), str_repeat("\xAA", 16))), $hash);
    }

    public function testTwoIdenticalSalesAreSignedDifferentlyAndTamperingBreaksThem(): void
    {
        $config = new EcrConfig(secureHashKey: TestKeys::WIFI);
        $first = self::build($config)->json;
        $second = self::build($config)->json;
        $tampered = (clone $first)->set('amount', '000000999999');

        self::assertNotSame($first->str('secureHash'), $second->str('secureHash'));
        self::assertFalse(SecureHash::verify($tampered, TestKeys::WIFI));
    }

    private static function signed(JsonObject $body, string $key): JsonObject
    {
        return $body->set('secureHash', SecureHash::sign(SecureHash::compose($body), $key));
    }

    private static function build(EcrConfig $config): EcrMessage
    {
        return EcrMessage::build(EcrTransactionType::Sale, $config, 'TW1', Amount::of('1.234'));
    }
}
