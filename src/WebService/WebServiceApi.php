<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\WebService;

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrTerminal;
use AmwalPay\Ecr\EcrWireResponse;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonException;
use AmwalPay\Ecr\Json\JsonObject;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\WebService\Http\CurlHttpTransport;
use AmwalPay\Ecr\WebService\Http\HttpTransport;
use AmwalPay\Ecr\WebService\Http\HttpTransportException;

/**
 * Posts signed JSON bodies to the Web Service ECR endpoints.
 *
 * Every transport or HTTP failure (no response, an empty body, invalid JSON,
 * an HTTP error with nothing readable, an HttpTransportException) comes back as a `success: false` envelope rather than an
 * exception, so callers read every outcome the same way. When the request may
 * have reached the Hub the envelope carries `nextStep`
 * `INQUIRE_BY_MERCHANT_REFERENCE`: a till must inquire, not treat it as a
 * refusal and send the sale again.
 */
final class WebServiceApi
{
    /** The wire value {@see \AmwalPay\Ecr\Result\NextStep::InquireByMerchantReference} is read from. */
    public const UNKNOWN_OUTCOME_STEP = 'INQUIRE_BY_MERCHANT_REFERENCE';

    private readonly HttpTransport $http;
    private readonly WebServiceRoutes $routes;

    public function __construct(
        private readonly EcrConfig $config,
        private readonly EcrLogger $logger,
        ?HttpTransport $http = null,
    ) {
        $this->http = $http ?? new CurlHttpTransport($config->caBundlePath);
        $this->routes = new WebServiceRoutes($config->environment);
    }

    public function routes(): WebServiceRoutes
    {
        return $this->routes;
    }

    /** Posts `$body` and returns the answer as JSON, never throwing for a transport failure. */
    public function postJson(WebServiceOperation $operation, JsonObject $body): JsonObject
    {
        $started = hrtime(true);
        $payload = Json::write($body);
        $url = $this->routes->url($operation);

        WebServiceHttpLog::request($this->logger, $operation, $url, $this->config->environment, $payload);
        $this->logSigning($body);

        $deadline = $this->config->connectTimeout + $this->config->responseTimeout;
        try {
            $response = $this->http->post(
                $url,
                $payload,
                ['Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json'],
                $this->config->connectTimeout,
                $deadline,
            );
        } catch (HttpTransportException $e) {
            $message = $e->timedOut
                ? 'Timed out after ' . EcrTerminal::formatDuration($deadline)
                : ($e->getMessage() !== '' ? $e->getMessage() : 'Web Service request failed');
            WebServiceHttpLog::error($this->logger, $operation, $message, self::elapsed($started));
            return self::errorBody('NO_RESPONSE', $message);
        }

        $status = $response->status;
        $text = $response->body;
        $elapsed = self::elapsed($started);
        WebServiceHttpLog::response($this->logger, $operation, $status, $elapsed, trim($text) === '' ? '<empty>' : $text);

        if (trim($text) === '') {
            WebServiceHttpLog::error($this->logger, $operation, 'Empty response (' . $response->reasonPhrase . ')', $elapsed);
            return self::errorBody((string) $status, 'Empty response from Web Service (' . $response->reasonPhrase . ')');
        }

        try {
            $parsed = Json::parseObject($text);
        } catch (JsonException $e) {
            WebServiceHttpLog::error($this->logger, $operation, 'Invalid JSON: ' . $e->getMessage(), $elapsed);
            return self::errorBody((string) $status, 'Response was not valid JSON: ' . $e->getMessage());
        }

        if ($status < 200 || $status > 299) {
            $wire = EcrWireResponse::parse($parsed);
            if ($wire->message !== '' || $wire->errorList !== []) {
                WebServiceHttpLog::error($this->logger, $operation, 'HTTP ' . $status . ' — ' . $wire->displayMessage(), $elapsed);
                return $parsed;
            }
            $reason = $response->reasonPhrase !== '' ? $response->reasonPhrase : 'HTTP ' . $status;
            WebServiceHttpLog::error($this->logger, $operation, $reason, $elapsed);
            return self::errorBody((string) $status, $reason, $status >= 500);
        }

        return $parsed;
    }

    private function logSigning(JsonObject $body): void
    {
        if ($this->config->secureHashKey === '') {
            $this->logger->debug('[Web Service] calcHash skipped — no ' . WebServiceSecureHash::KEY_LABEL . ' configured');
            return;
        }
        $hash = $body->str(WebServiceSecureHash::FIELD);
        if ($hash !== '') {
            WebServiceHttpLog::signing($this->logger, WebServiceSecureHash::compose($body), $hash);
        }
    }

    /** The `success: false` envelope that stands in for an answer that never came. */
    private static function errorBody(string $responseCode, string $message, bool $outcomeUnknown = true): JsonObject
    {
        $body = (new JsonObject())
            ->set('success', false)
            ->set('responseCode', $responseCode)
            ->set('message', $message);
        if ($outcomeUnknown) {
            $body->set('nextStep', self::UNKNOWN_OUTCOME_STEP);
        }
        return $body;
    }

    private static function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
