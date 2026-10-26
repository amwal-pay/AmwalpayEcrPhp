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
        $body = Json::write($message->json);
        if (\strlen($body) > EcrFrames::MAX_BODY_BYTES) {
            throw new EcrIOException('Request exceeds ' . EcrFrames::MAX_BODY_BYTES . ' bytes');
        }
        return EcrFrames::wrap($body);
    }

    /**
     * One answer body, as the object it claims to be. Rejects null, arrays, bare
     * values, repeated keys and lenient JSON.
     *
     * @throws EcrIOException "not valid JSON", read as a malformed answer
     */
    public static function parse(string $body): JsonObject
    {
        // As `Encoding.UTF8.GetString`: an invalid byte becomes U+FFFD, not a refusal.
        $payload = NetText::scrubUtf8($body);
        try {
            return Json::parseObject($payload);
        } catch (JsonException $e) {
            throw new EcrIOException('Terminal returned a message that is not valid JSON: ' . $payload, 0, $e);
        }
    }
}
