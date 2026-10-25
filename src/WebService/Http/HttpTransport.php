<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService\Http;

/**
 * Posts one JSON body and returns the answer. {@see CurlHttpTransport} is the
 * default; supply your own to route through a corporate proxy or to test. The
 * URL is always the one the SDK resolved for the environment.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param float $connectTimeout seconds to establish the connection
     * @param float $totalTimeout seconds for the whole exchange, connection included
     * @throws HttpTransportException no answer was read
     */
    public function post(string $url, string $body, array $headers, float $connectTimeout, float $totalTimeout): HttpResponse;
}
