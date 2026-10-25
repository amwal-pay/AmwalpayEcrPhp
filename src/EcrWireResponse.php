<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Internal\ResponseEnvelope;
use AmwalPay\Ecr\Internal\SecureHash;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonObject;

/**
 * The unified ECR response envelope:
 * `{ success, responseCode, message, data, errorList, nonce?, secureHash? }`.
 *
 * Legacy answers that nest the envelope under `ecrResponse`, or carry flat
 * `approved` / `responseMessage` fields, are normalised.
 */
final class EcrWireResponse
{
    /** @param list<string> $errorList */
    public function __construct(
        public readonly bool $success,
        public readonly string $responseCode,
        public readonly string $message,
        public readonly ?JsonObject $data,
        public readonly array $errorList,
        public readonly string $nonce = '',
        public readonly string $secureHash = '',
    ) {
    }

    public static function parse(JsonObject $json): self
    {
        return new self(
            ResponseEnvelope::isSuccess($json),
            ResponseEnvelope::responseCode($json),
            ResponseEnvelope::wireMessage($json),
            ResponseEnvelope::data($json),
            ResponseEnvelope::errorList($json),
            $json->str('nonce'),
            $json->str(SecureHash::FIELD),
        );
    }

    /** `data.merchantReference`, then legacy `merchantReferenceId`, then `requestId`. */
    public function merchantReference(): string
    {
        if ($this->data === null) {
            return '';
        }
        foreach (['merchantReference', 'merchantReferenceId', 'requestId'] as $key) {
            $value = $this->data->str($key);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    public function terminalSerial(): string
    {
        return $this->data?->str('terminalSerial') ?? '';
    }

    /** User-facing text: `errorList` entries first, then the message. */
    public function displayMessage(): string
    {
        $errors = implode("\n", $this->errorList);
        return $errors !== '' ? $errors : $this->message;
    }

    /**
     * User-facing text from raw JSON: `errorList` first, then `message`.
     * `$fallback` when the text is empty, not a JSON object, or says nothing.
     */
    public static function displayMessageFromRaw(string $raw, string $fallback = ''): string
    {
        if ($raw === '') {
            return $fallback;
        }
        try {
            $text = self::parse(Json::parseObject($raw))->displayMessage();
            return $text !== '' ? $text : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
