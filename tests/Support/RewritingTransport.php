<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Support;

use AmwalPay\Ecr\WebService\Http\HttpResponse;
use AmwalPay\Ecr\WebService\Http\HttpTransport;

/**
 * Sends the SDK's real cURL request to the local fake Hub instead of Amwal:
 * same path, same body, same headers and deadlines, another host. Test-only.
 */
final class RewritingTransport implements HttpTransport
{
    /** @var list<string> */
    public array $urls = [];

    public function __construct(private readonly HttpTransport $inner, private readonly string $base, private string $mode)
    {
    }

    public function mode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function post(string $url, string $body, array $headers, float $connectTimeout, float $totalTimeout): HttpResponse
    {
        $this->urls[] = $url;
        $path = (string) parse_url($url, \PHP_URL_PATH);
        return $this->inner->post($this->base . $path . '?mode=' . rawurlencode($this->mode), $body, $headers, $connectTimeout, $totalTimeout);
    }
}
