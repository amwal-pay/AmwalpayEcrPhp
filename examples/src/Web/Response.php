<?php

declare(strict_types=1);

namespace AmwalExample\Web;

/** An HTTP response, built by the API and sent by the front controller. */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function json(int $status, mixed $data): self
    {
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    public static function empty(int $status): self
    {
        return new self($status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
