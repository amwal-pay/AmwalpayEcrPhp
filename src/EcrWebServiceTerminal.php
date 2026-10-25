<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

use AmwalPay\Ecr\Exception\InvalidRequestException;
use AmwalPay\Ecr\Failure\ConnectionLost;
use AmwalPay\Ecr\Failure\Invalid;
use AmwalPay\Ecr\Failure\Malformed;
use AmwalPay\Ecr\Internal\EcrMessage;
use AmwalPay\Ecr\Internal\MerchantReference;
use AmwalPay\Ecr\Internal\ProtocolVersion;
use AmwalPay\Ecr\Internal\Validate;
use AmwalPay\Ecr\Internal\WireReaders;
use AmwalPay\Ecr\Json\Json;
use AmwalPay\Ecr\Json\JsonObject;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Result\CloseReceiptFailed;
use AmwalPay\Ecr\Result\EcrInquiry;
use AmwalPay\Ecr\Result\EcrReceiptClosed;
use AmwalPay\Ecr\Result\EcrResult;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\InquiryFailed;
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
     * A config still on the Wi-Fi answer wait (120 s) gets the Web Service one,
     * so the client deadline (connect + answer) is
     * {@see EcrConfig::WEB_SERVICE_CLIENT_TIMEOUT} however the terminal is made.
     * Any other wait is kept.
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
        $this->config = $config->responseTimeout === EcrConfig::DEFAULT_RESPONSE_TIMEOUT
            ? $config->with(responseTimeout: max(EcrConfig::WEB_SERVICE_CLIENT_TIMEOUT - $config->connectTimeout, 1.0))
            : $config;
        $config = $this->config;
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

    /** @param string|int|Amount $amount Major units as a string, e.g. "1.234". Never a float. */
    public function sale(string|int|Amount $amount, string $merchantReference = ''): EcrResult
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return self::invalidIds($merchantReference);
        }
        $minor = Amount::of($amount)->toMinorUnits($this->config->minorUnitDigits);
        return $this->money(EcrTransactionType::Sale, WebServiceOperation::Sale, $ids, $merchantReference, $minor);
    }

    public function void(string $receiptNumber, string $merchantReference = ''): EcrResult
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return self::invalidIds($merchantReference);
        }
        $stan = Validate::receiptNumber($receiptNumber, 'Void');
        return $this->money(EcrTransactionType::Void, WebServiceOperation::Void, $ids, $merchantReference, stan: $stan);
    }

    /** @param string $transactionDate The original's day as `yyyyMMdd`. */
    public function refund(string|int|Amount $amount, string $receiptNumber, string $transactionDate, string $merchantReference = ''): EcrResult
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return self::invalidIds($merchantReference);
        }
        $minor = Amount::of($amount)->toMinorUnits($this->config->minorUnitDigits);
        $stan = Validate::receiptNumber($receiptNumber, 'Refund');
        $date = Validate::transactionDate($transactionDate, true, 'A refund');
        return $this->money(EcrTransactionType::Refund, WebServiceOperation::Refund, $ids, $merchantReference, $minor, $stan, $date);
    }

    public function inquire(string $receiptNumber, string $transactionDate, string $merchantReference = ''): EcrInquiry
    {
        $ids = $this->backendIds();
        if ($ids === null) {
            return new InquiryFailed($merchantReference, new Invalid('merchantId and terminalId are required for Web Service ECR'));
        }
        [$reference, $body] = $this->build(
            EcrTransactionType::Inquiry,
            $ids,
            $merchantReference,
            stan: Validate::receiptNumber($receiptNumber, 'An inquiry'),
            originalTransactionDate: Validate::transactionDate($transactionDate, false, 'An inquiry'),
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
        if (trim($originalReference) === '') {
            throw new InvalidRequestException("An inquiry by reference needs the original's reference");
        }
        $original = MerchantReference::resolve($originalReference);
        $ids = $this->backendIds();
        if ($ids === null) {
            return new InquiryFailed($merchantReference, new Invalid('merchantId and terminalId are required for Web Service ECR'));
        }
        [$reference, $body] = $this->build(
            EcrTransactionType::Inquiry,
            $ids,
            $merchantReference,
            originalTransactionDate: Validate::transactionDate($transactionDate, false, 'An inquiry'),
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
        if (trim($originalMerchantReference) === '') {
            return new CloseReceiptFailed($merchantReference, new Invalid('Close receipt needs the original merchant reference'));
        }
        $ids = $this->backendIds();
        if ($ids === null) {
            return new CloseReceiptFailed($merchantReference, new Invalid('merchantId and terminalId are required for Web Service ECR'));
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
        $version = ProtocolVersion::problem($response);
        return $version !== null
            ? new CloseReceiptFailed($reference, new Malformed($version))
            : WireReaders::toReceiptClosed($response, $reference);
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
            function () use ($operation, $body, $reference): EcrResult {
                $response = $this->api->postJson($operation, $body);
                $version = ProtocolVersion::problem($response);
                return $version !== null
                    ? new Failed($reference, new Malformed($version))
                    : WireReaders::toResult($response, $reference, $this->config->minorUnitDigits);
            },
        );
    }

    private function readInquiry(JsonObject $response, string $reference): EcrInquiry
    {
        // A transport failure is synthesised as an answer with no data, which
        // would read as "not found", and a till told nothing was taken may
        // charge again. It is a failed lookup, not a finding.
        if ($response->str('nextStep') === WebServiceApi::UNKNOWN_OUTCOME_STEP && $response->get('data') === null) {
            $reason = $response->str('message');
            return new InquiryFailed($reference, new ConnectionLost($reason !== '' ? $reason : 'The Web Service did not answer'));
        }
        // The Hub refused the lookup itself (HTTP 4xx/5xx, no record): a failed
        // lookup, not a finding that nothing was charged.
        if (!$response->flag('success') && $response->get('data') === null && preg_match('/^[45][0-9]{2}$/', $response->str('responseCode')) === 1) {
            $reason = $response->str('message');
            return new InquiryFailed($reference, new Invalid('The Web Service refused the inquiry (HTTP ' . $response->str('responseCode') . ($reason !== '' ? ': ' . $reason : '') . ')'));
        }
        $version = ProtocolVersion::problem($response);
        if ($version !== null) {
            return new InquiryFailed($reference, new Malformed($version));
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

    /** A positive 64-bit integer written in digits, or null. */
    public static function id(string $text): ?int
    {
        $trimmed = trim($text);
        if (preg_match('/^[0-9]{1,19}$/', $trimmed) !== 1) {
            return null;
        }
        $value = filter_var($trimmed, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false) {
            $value = filter_var(ltrim($trimmed, '0') ?: '0', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        }
        return $value === false ? null : $value;
    }

    private static function invalidIds(string $merchantReference): Failed
    {
        return new Failed($merchantReference, new Invalid('merchantId and terminalId must be set on EcrConfig for Web Service ECR'));
    }
}
