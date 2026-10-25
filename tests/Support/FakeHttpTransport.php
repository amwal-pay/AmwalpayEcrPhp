<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Support;

use AmwalPay\Ecr\WebService\Http\HttpResponse;
use AmwalPay\Ecr\WebService\Http\HttpTransport;

/** A scripted HTTP transport: records each post and answers from a closure. */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<array{url: string, body: string, headers: array<string, string>, connect: float, total: float}> */
    public array $posts = [];

    /** @param \Closure(string $url, string $body, int $n): HttpResponse $respond may throw HttpTransportException */
    public function __construct(private readonly \Closure $respond)
    {
    }

    public static function reply(int $status, string $body, string $reason = ''): self
    {
        return new self(static fn (): HttpResponse => new HttpResponse($status, $body, $reason));
    }

    public function post(string $url, string $body, array $headers, float $connectTimeout, float $totalTimeout): HttpResponse
    {
        $n = \count($this->posts);
        $this->posts[] = ['url' => $url, 'body' => $body, 'headers' => $headers, 'connect' => $connectTimeout, 'total' => $totalTimeout];
        return ($this->respond)($url, $body, $n);
    }
}
