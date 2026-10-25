<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService\Http;

/** One HTTP answer: status, reason phrase (may be empty on HTTP/2) and body text. */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $reasonPhrase = '',
    ) {
    }
}
