<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Internal;

use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonArray;
use AmwalPay\Ecr\Json\JsonObject;

/**
 * Reads the unified response envelope, and the legacy shapes that nest it
 * under `ecrResponse` or flatten it into `approved` / `responseMessage`.
 *
 * @internal
 */
final class ResponseEnvelope
{
    private const APPROVED = '00';

    public static function root(JsonObject $json): JsonObject
    {
        if ($json->has('success') || $json->has('data')) {
            return $json;
        }
        return $json->obj('ecrResponse') ?? $json;
    }

    public static function isSuccess(JsonObject $json): bool
    {
        $success = self::root($json)->strictBool('success');
        if ($success !== null) {
            return $success;
        }
        $approved = $json->strictBool('approved');
        if ($approved !== null) {
            return $approved;
        }
        return self::responseCode($json) === self::APPROVED;
    }

    public static function responseCode(JsonObject $json): string
    {
        $code = self::root($json)->str('responseCode');
        return $code !== '' ? $code : $json->str('responseCode');
    }

    /** Top-level `message`, or legacy `responseMessage`. */
    public static function wireMessage(JsonObject $json): string
    {
        $message = self::root($json)->str('message');
        return $message !== '' ? $message : $json->str('responseMessage');
    }

    public static function data(JsonObject $json): ?JsonObject
    {
        return self::root($json)->obj('data');
    }

    /** @return list<string> Non-blank scalar entries, trimmed. */
    public static function errorList(JsonObject $json): array
    {
        $raw = self::root($json)->get('errorList') ?? $json->get('errorList');
        if (!$raw instanceof JsonArray) {
            return [];
        }
        $result = [];
        foreach ($raw as $element) {
            if (!Json::isScalar($element)) {
                continue;
            }
            $text = NetText::trim(Json::content($element));
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return $result;
    }
}
