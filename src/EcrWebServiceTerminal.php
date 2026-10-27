<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Exception\InvalidRequestException;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Internal\EcrMessage;
use AmwalPay\Ecr\Internal\MerchantReference;
use AmwalPay\Ecr\Internal\Validate;
use AmwalPay\Ecr\Internal\WireReaders;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonObject;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Result\CloseReceiptFailed;
use AmwalPay\Ecr\Result\EcrInquiry;
use AmwalPay\Ecr\Result\EcrReceiptClosed;
use AmwalPay\Ecr\Result\EcrResult;
use AmwalPay\Ecr\Result\EcrSettlement;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\SettlementFailed;
use AmwalPay\Ecr\Safety\FinancialGuard;
use AmwalPay\Ecr\WebService\Http\HttpTransport;
use AmwalPay\Ecr\WebService\WebServiceApi;
use AmwalPay\Ecr\WebService\WebServiceHttpLog;
use AmwalPay\Ecr\WebService\WebServiceMessage;
use AmwalPay\Ecr\WebService\WebServiceOperation;

/**
 * ECR Web Service client: REST over HTTPS to the Amwal Hub, with signed JSON
 * bodies. Use it when the terminal is configured for Web Service mode
 * (`ecrMode: 4`).
 *
 * A lost or unreadable answer arrives as {@see Failed} with an outcome-unknown
 * failure, and is never followed by an automatic inquiry
 * (`Failed::$recovered` is always null): inquire by reference yourself.
 */
final class EcrWebServiceTerminal
{
    private readonly EcrConfig $config;
    private readonly EcrLogger $logger;
    private readonly WebServiceApi $api;
    private readonly FinancialGuard $guard;

    /**
     * The config is used as given: the HTTP deadline is connect + answer
     * timeout. {@see EcrSessions::plan()} raises the Wi-Fi answer wait to
     * {@see EcrConfig::WEB_SERVICE_RESPONSE_TIMEOUT}; a terminal built directly
     * keeps whatever the config says, as in the Windows SDK.
     *
     * @param HttpTransport|null $http How the HTTPS post is made. Null: cURL. The URL is always the SDK's.
     */
    public function __construct(
        private readonly string $terminalSerial,
        EcrConfig $config = new EcrConfig(),
        ?EcrLogger $logger = null,
        ?HttpTransport $http = null,
        ?FinancialGuard $guard = null,
    ) {
        $this->config = $config;
        $this->logger = $logger ?? EcrLogger::none();
        $this->api = new WebServiceApi($config, $this->logger, $http);
        $this->guard = $guard ?? FinancialGuard::fromConfig($config, $this->logger);
    }

    /** Identifies this terminal to the guard's lock and journal. */
    public function terminalKey(): string
    {
        return 'ws|' . $this->config->environment->value . '|' . trim($this->config->merchantId) . '|' . trim($this->config->terminalId);
    }

    public function guard(): FinancialGuard
    {
        return $this->guard;
    }

    public function config(): EcrConfig
    {
        return $this->config;
    }

    /** @param string|int|float|Amount $amount Major units, e.g. "1.234". */
    public function sale(string|int|float|Amount $amount, string $merchantReference = ''): EcrResult
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return self::invalidIds();
        }
        $minor = Amount::of($amount)->toMinorUnits($this->config->minorUnitDigits);
        return $this->money(EcrTransactionType::Sale, WebServiceOperation::Sale, $ids, $merchantReference, $minor);
    }

    public function void(string $receiptNumber, string $merchantReference = ''): EcrResult
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return self::invalidIds();
        }
        return $this->money(EcrTransactionType::Void, WebServiceOperation::Void, $ids, $merchantReference, stan: $receiptNumber);
    }

    /** @param string $transactionDate The original's day as `yyyyMMdd`. */
    public function refund(string|int|float|Amount $amount, string $receiptNumber, string $transactionDate, string $merchantReference = ''): EcrResult
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return self::invalidIds();
        }
        $minor = Amount::of($amount)->toMinorUnits($this->config->minorUnitDigits);
        return $this->money(EcrTransactionType::Refund, WebServiceOperation::Refund, $ids, $merchantReference, $minor, $receiptNumber, $transactionDate);
    }

    public function inquire(string $receiptNumber, string $transactionDate, string $merchantReference = ''): EcrInquiry
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return new InquiryFailed($merchantReference, new Malformed('merchantId and terminalId are required for Web Service ECR'));
        }
        [$reference, $body] = $this->build(
            EcrTransactionType::Inquiry,
            $ids,
            $merchantReference,
            stan: $receiptNumber,
            originalTransactionDate: $transactionDate,
        );
        return $this->readInquiry($this->api->postJson(WebServiceOperation::TransactionStatus, $body), $reference);
    }

    /**
     * Looks up the transaction sent as `$originalReference`. The answer to an
     * outcome you never received.
     *
     * @param string $transactionDate Optional. Do not invent "today": the Hub filters by that day.
     */
    public function inquireByReference(string $originalReference, string $transactionDate = '', string $merchantReference = ''): EcrInquiry
    {
        if (Validate::isBlank($originalReference)) {
            throw new InvalidRequestException("An inquiry by reference needs the original's reference");
        }
        $original = MerchantReference::resolve($originalReference);
        $ids = $this->backendIds();
        if ($ids === null) {
            return new InquiryFailed($merchantReference, new Malformed('merchantId and terminalId are required for Web Service ECR'));
        }
        [$reference, $body] = $this->build(
            EcrTransactionType::Inquiry,
            $ids,
            $merchantReference,
            originalTransactionDate: $transactionDate,
            originalMerchantReference: $original,
        );
        $inquiry = $this->readInquiry($this->api->postJson(WebServiceOperation::TransactionStatus, $body), $reference);
        $this->guard->observeInquiry($this->terminalKey(), $original, $inquiry);
        return $inquiry;
    }

    /**
     * Posts `/Ecr/closeReceipt` for the sale, void or refund sent as
     * `$originalMerchantReference`. Call it from the Close receipt action; it is
     * never sent automatically.
     *
     * @param string $merchantReference This close request's own id. Blank: generated.
     */
    public function closeReceipt(string $originalMerchantReference, string $merchantReference = ''): EcrReceiptClosed
    {
        if (Validate::isBlank($originalMerchantReference)) {
            return new CloseReceiptFailed($merchantReference, new Malformed('Close receipt needs the original merchant reference'));
        }
        $ids = $this->backendIds();
        if ($ids === null) {
            return new CloseReceiptFailed($merchantReference, new Malformed('merchantId and terminalId are required for Web Service ECR'));
        }
        [$reference, $body] = WebServiceMessage::buildCloseReceipt(
            $ids[0],
            $ids[1],
            $this->terminalSerial,
            $this->config->secureHashKey,
            $this->config->ecrId,
            $merchantReference,
            $originalMerchantReference,
            EcrMessage::timestamp($this->config),
        );
        Json::write($body);
        WebServiceHttpLog::operation($this->logger, EcrTransactionType::CloseReceipt->messageType(), $reference, $this->terminalSerial, $ids[0], $ids[1]);
        $response = $this->api->postJson(WebServiceOperation::CloseReceipt, $body);
        return WireReaders::toReceiptClosed($response, $reference);
    }

    /**
     * Posts `/Ecr/settle`. Confirm with the cashier before calling: the Hub
     * starts the close as soon as the request arrives. The answer is read the
     * same way as a Wi-Fi `SETTLEMENT`.
     *
     * @param string $merchantReference This request's own id. Blank: generated.
     */
    public function settlement(string $merchantReference = ''): EcrSettlement
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return new SettlementFailed($merchantReference, new Malformed('merchantId and terminalId are required for Web Service ECR'));
        }
        [$reference, $body] = $this->plain($ids, $merchantReference, WebServiceOperation::Settle);
        $response = $this->guard->protected(
            $this->config->connectTimeout + $this->config->responseTimeout,
            fn (): JsonObject => $this->api->postJson(WebServiceOperation::Settle, $body),
        );
        if (self::lost($response)) {
            $reason = $response->str('message');
            return new SettlementFailed($reference, new ConnectionLost($reason !== '' ? $reason : 'The Web Service did not answer'));
        }
        return WireReaders::toSettlement($response, $reference, $this->config->minorUnitDigits);
    }

    /**
     * Posts `/Ecr/CheckReachability` with the probe budget, so a Hub that does
     * not answer does not consume the sale's response timeout. A sale does not
     * call this.
     */
    public function probeReachability(): EcrReachability
    {
        $origin = $this->api->routes()->hub();
        $parts = parse_url($origin);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return new EcrReachability(false, $origin, 0, 'The Web Service address is not a URL', $origin);
        }
        $host = $parts['host'];
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);

        $ids = $this->backendIds();
        if ($ids === null) {
            return new EcrReachability(false, $host, $port, 'merchantId and terminalId are required for Web Service ECR', $origin);
        }

        [, $body] = $this->plain($ids, '', WebServiceOperation::CheckReachability);
        $response = $this->api->postJson(WebServiceOperation::CheckReachability, $body, $this->config->probeTimeout);
        $envelope = EcrWireResponse::parse($response);
        if (self::lost($response) || !$envelope->success) {
            $reason = $response->str('message');
            if ($reason === '') {
                $reason = $envelope->displayMessage();
            }
            return new EcrReachability(false, $host, $port, $reason !== '' ? $reason : 'The terminal is not reachable', $origin);
        }
        return new EcrReachability(true, $host, $port, null, $origin);
    }

    /**
     * @param array{0: int, 1: int} $ids
     * @return array{0: string, 1: JsonObject}
     */
    private function plain(array $ids, string $merchantReference, WebServiceOperation $operation): array
    {
        [$reference, $body] = WebServiceMessage::buildPlain(
            $ids[0],
            $ids[1],
            $this->terminalSerial,
            $this->config->secureHashKey,
            $this->config->currencyCode,
            $this->config->ecrId,
            $merchantReference,
            EcrMessage::timestamp($this->config),
        );
        Json::write($body); // refuses text that is not UTF-8, before anything is sent
        WebServiceHttpLog::operation($this->logger, $operation->label(), $reference, $this->terminalSerial, $ids[0], $ids[1]);
        return [$reference, $body];
    }

    /** A transport failure synthesised as an answer: no data, and a request to inquire. */
    private static function lost(JsonObject $response): bool
    {
        return $response->str('nextStep') === WebServiceApi::UNKNOWN_OUTCOME_STEP && $response->get('data') === null;
    }

    /** @param array{0: int, 1: int} $ids */
    private function money(
        EcrTransactionType $type,
        WebServiceOperation $operation,
        array $ids,
        string $merchantReference,
        ?int $minor = null,
        string $stan = '',
        string $date = '',
    ): EcrResult {
        [$reference, $body] = $this->build(
            $type,
            $ids,
            MerchantReference::resolve($merchantReference),
            amountMinorUnits: $minor,
            stan: $stan,
            originalTransactionDate: $date,
        );
        return $this->guard->run(
            $this->terminalKey(),
            $type,
            $reference,
            $minor === null ? '' : (string) $minor,
            $this->config->currencyCode,
            $this->config->connectTimeout + $this->config->responseTimeout,
            fn (): EcrResult => WireReaders::toResult($this->api->postJson($operation, $body), $reference, $this->config->minorUnitDigits),
        );
    }

    private function readInquiry(JsonObject $response, string $reference): EcrInquiry
    {
        // A transport failure is synthesised as an answer with no data, which
        // would read as "not found", and a till told nothing was taken may
        // charge again. It is a failed lookup, not a finding.
        if (self::lost($response)) {
            $reason = $response->str('message');
            return new InquiryFailed($reference, new ConnectionLost($reason !== '' ? $reason : 'The Web Service did not answer'));
        }
        return WireReaders::toInquiry($response, $reference, $this->config->minorUnitDigits);
    }

    /**
     * @param array{0: int, 1: int} $ids
     * @return array{0: string, 1: JsonObject}
     */
    private function build(
        EcrTransactionType $type,
        array $ids,
        string $merchantReference = '',
        ?int $amountMinorUnits = null,
        string $stan = '',
        string $originalTransactionDate = '',
        string $originalMerchantReference = '',
    ): array {
        [$reference, $body] = WebServiceMessage::build(
            $type,
            $ids[0],
            $ids[1],
            $this->terminalSerial,
            $this->config->secureHashKey,
            $this->config->currencyCode,
            $this->config->ecrId,
            $amountMinorUnits,
            $stan,
            $originalTransactionDate,
            $originalMerchantReference,
            $merchantReference,
            EcrMessage::timestamp($this->config),
        );
        Json::write($body); // refuses text that is not UTF-8, before anything is sent
        WebServiceHttpLog::operation($this->logger, $type->messageType(), $reference, $this->terminalSerial, $ids[0], $ids[1]);
        return [$reference, $body];
    }

    /** @return array{0: int, 1: int}|null */
    private function backendIds(): ?array
    {
        $merchant = self::id($this->config->merchantId);
        $terminal = self::id($this->config->terminalId);
        return $merchant === null || $terminal === null ? null : [$merchant, $terminal];
    }

    /**
     * The ID as a 64-bit integer, or null: `long.TryParse` with
     * `NumberStyles.Integer`, so surrounding white space and a leading sign are
     * accepted.
     */
    public static function id(string $text): ?int
    {
        if (preg_match('/^([+-]?)([0-9]+)$/', trim($text, " \t\n\v\f\r"), $m) !== 1) {
            return null;
        }
        $digits = ltrim($m[2], '0');
        $value = filter_var($m[1] === '-' ? '-' . ($digits === '' ? '0' : $digits) : ($digits === '' ? '0' : $digits), \FILTER_VALIDATE_INT);
        return $value === false ? null : $value;
    }

    private static function invalidIds(): Failed
    {
        return new Failed('', new Malformed('merchantId and terminalId must be set on EcrConfig for Web Service ECR'));
    }
}
