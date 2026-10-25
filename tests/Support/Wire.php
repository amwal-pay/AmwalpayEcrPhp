<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Support;

use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonArray;
use AmwalPay\Ecr\Json\JsonObject;

/** Builders for the terminal's answers. */
final class Wire
{
    public static function obj(string $text): JsonObject
    {
        return Json::parseObject($text);
    }

    /** The nonce the till put on a request; empty when it sent none. */
    public static function nonceOf(string $request): string
    {
        return self::obj($request)->str('nonce');
    }

    /** Signs `$body` the way the terminal does and returns the wire text. */
    public static function signed(JsonObject $body, string $key): string
    {
        $body->set(SecureHash::FIELD, SecureHash::sign(SecureHash::compose($body), $key));
        return $body->toJson();
    }

    /** A unified approval envelope echoing `$nonce`. */
    public static function approval(string $nonce, string $reference = 'ORD-1'): JsonObject
    {
        return new JsonObject([
            'success' => true,
            'responseCode' => '00',
            'message' => 'Approved',
            'data' => new JsonObject([
                'merchantReference' => $reference,
                'terminalSerial' => 'TW1',
                'amount' => '1.234',
                'rrn' => '622908275947',
                'authCode' => '751486',
            ]),
            'errorList' => new JsonArray(),
            'nonce' => $nonce,
        ]);
    }

    /** A found-transaction envelope echoing `$nonce`. */
    public static function foundEnvelope(string $nonce): JsonObject
    {
        return new JsonObject([
            'success' => true,
            'responseCode' => '00',
            'message' => 'Transaction found',
            'data' => new JsonObject([
                'transactionId' => 'abc-123',
                'stan' => '000131',
                'status' => 'Approved',
                'amount' => '1.234',
                'rrn' => '622908275947',
            ]),
            'errorList' => new JsonArray(),
            'nonce' => $nonce,
        ]);
    }

    public const FOUND = '{"success":true,"responseCode":"00","message":"Transaction found",'
        . '"data":{"transactionId":"abc-123","stan":"000131","status":"Approved","amount":"1.234","rrn":"622908275947",'
        . '"authCode":"751486","transactionTypeDisplayName":"Purchase"},"errorList":[]}';
}
