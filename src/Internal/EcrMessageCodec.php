<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Channel\EcrFrames;
use AmwalPay\Ecr\Channel\EcrIOException;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonException;
use AmwalPay\Ecr\Json\JsonObject;

/**
 * The Wi-Fi wire format: a 2-byte big-endian length, then UTF-8 JSON. The
 * terminal has one file that matches this; the two change together.
 *
 * @internal
 */
final class EcrMessageCodec
{
    /** Frames a message ready to write to the socket. */
    public static function encode(EcrMessage $message): string
    {
        return EcrFrames::wrap(Json::write($message->json));
    }

    /**
     * One answer body, as the object it claims to be. Rejects null, arrays, bare
     * values, repeated keys and lenient JSON.
     *
     * @throws EcrIOException "not valid JSON", read as a malformed answer
     */
    public static function parse(string $body): JsonObject
    {
        try {
            return Json::parseObject($body);
        } catch (JsonException $e) {
            throw new EcrIOException('Terminal returned a message that is not valid JSON: ' . self::excerpt($body), 0, $e);
        }
    }

    /** Enough of an unreadable answer to recognise it, never a whole payload. */
    private static function excerpt(string $body): string
    {
        $text = preg_match('//u', $body) === 1 ? $body : bin2hex(substr($body, 0, 64));
        return \strlen($text) > 200 ? substr($text, 0, 200) . '…' : $text;
    }
}
