<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService\Http;

/**
 * HTTPS with cURL. The TLS certificate and host name are always verified.
 * Plain HTTP is accepted only because the URL is never the caller's: the SDK
 * resolves it (always https), or a custom transport rewrites it, e.g. in tests.
 *
 * On Windows, PHP often ships without a CA bundle: cURL then fails with "SSL
 * certificate problem: unable to get local issuer certificate". Set
 * `curl.cainfo` in php.ini, or pass `caBundlePath` in the config. Never turn
 * verification off: the Hub's answer is what decides whether goods are handed
 * over, and only TLS proves it came from the Hub.
 */
final class CurlHttpTransport implements HttpTransport
{
    public function __construct(private readonly ?string $caBundlePath = null)
    {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException('The curl extension is required for ECR Web Service');
        }
    }

    public function post(string $url, string $body, array $headers, float $connectTimeout, float $totalTimeout): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new HttpTransportException('cURL could not be initialised');
        }
        $reason = '';
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Expect:'; // no 100-continue round trip
        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeout * 1000)),
            \CURLOPT_TIMEOUT_MS => max(1, (int) round($totalTimeout * 1000)),
            \CURLOPT_NOSIGNAL => true,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS | \CURLPROTO_HTTP,
            \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$reason): int {
                if (preg_match('#^HTTP/[0-9.]+\s+[0-9]{3}\s*(.*)$#', trim($line), $m) === 1) {
                    $reason = trim($m[1]);
                }
                return \strlen($line);
            },
        ];
        if ($this->caBundlePath !== null && $this->caBundlePath !== '') {
            $options[\CURLOPT_CAINFO] = $this->caBundlePath;
        }
        curl_setopt_array($handle, $options);

        try {
            $text = curl_exec($handle);
            if ($text === false) {
                $errno = curl_errno($handle);
                $error = curl_error($handle);
                throw new HttpTransportException(
                    $error !== '' ? $error : 'Web Service request failed',
                    $errno === \CURLE_OPERATION_TIMEDOUT,
                );
            }
            $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
            return new HttpResponse($status, (string) $text, $reason);
        } finally {
            curl_close($handle);
        }
    }
}
