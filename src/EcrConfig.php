<?php

declare(strict_types=1);

namespace AmwalPay\Ecr;

/**
 * How this ECR identifies itself and how it talks to a terminal.
 *
 * Immutable. Invalid values are refused the moment the config is built, so a
 * key with a stray space fails at start-up rather than on the shop floor as a
 * decline the cashier cannot explain.
 *
 * ```php
 * $config = new EcrConfig(ecrId: 'TILL-01', secureHashKey: $wifiKey);
 * $stricter = $config->with(responseTimeout: 60);
 * ```
 *
 * Timeouts are seconds. The defaults describe an Omani deployment (OMR, three
 * decimals) on the standard port.
 */
final class EcrConfig
{
    /** The port Amwal POS terminals listen on for ECR requests. */
    public const DEFAULT_PORT = 9100;

    /** Wi-Fi wait for the terminal's answer: card present plus PIN. */
    public const DEFAULT_RESPONSE_TIMEOUT = 120.0;

    public const DEFAULT_CONNECT_TIMEOUT = 10.0;

    /**
     * HTTP read timeout for Web Service calls, which {@see EcrSessions::plan()}
     * gives a Web Service link when the caller left the Wi-Fi default (120 s).
     * Longer than the Wi-Fi wait: the Hub's wait includes card present plus PIN.
     * With the default connect timeout the HTTP deadline is 140 s.
     */
    public const WEB_SERVICE_RESPONSE_TIMEOUT = 130.0;

    /** 64 bits is the shortest key worth calling a secret. */
    private const MIN_SECRET_LENGTH = 16;

    public readonly string $secureHashKey;

    /**
     * @param string $ecrId Identifies this cash register to the terminal and on its records.
     * @param string $currencyCode ISO 4217 numeric code: `512` for OMR.
     * @param int $minorUnitDigits Decimal places the currency has (0 to 4): 3 for OMR, 2 for USD.
     * @param int $port The terminal's ECR listener port.
     * @param float $connectTimeout Seconds to wait for the terminal to accept a connection.
     * @param float $responseTimeout Seconds to wait for the result once the request is sent.
     * @param float $probeTimeout Seconds a reachability probe waits.
     * @param string $secureHashKey The signing secret, as hex. Wi-Fi and Web Service use different secrets.
     * @param string $merchantId Backend merchant ID, for Web Service.
     * @param string $terminalId Backend terminal ID, for Web Service.
     * @param EcrEnvironment $environment Hub deployment for Web Service.
     * @param bool $autoInquireOnFailure Follow a lost Wi-Fi answer with one inquiry by merchant reference.
     * @param bool $guardFinancialOperations Opt in to one money-moving call per terminal at a time, unique
     *        references, and no new money movement while an earlier outcome is unknown. Off by default, as the
     *        Windows SDK has no such guard. See {@see Safety\FinancialGuard}.
     * @param string|null $stateDirectory Where the guard keeps its locks and journal. Null: the system temp folder.
     *        Use a durable, private folder in production.
     * @param bool $extendTimeLimit Raise PHP's `max_execution_time` so it cannot kill a payment mid-wait.
     * @param string|null $caBundlePath CA bundle for the Web Service TLS check, when PHP has none configured.
     * @param string|null $timezone Time zone for `transactionDateTime`. Null: the machine's local time, as the
     *        Windows SDK (`DateTime.Now`); php.ini's `date.timezone` is not used. Set it only to override the machine.
     */
    public function __construct(
        public readonly string $ecrId = 'ECR01',
        public readonly string $currencyCode = '512',
        public readonly int $minorUnitDigits = 3,
        public readonly int $port = self::DEFAULT_PORT,
        public readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public readonly float $responseTimeout = self::DEFAULT_RESPONSE_TIMEOUT,
        public readonly float $probeTimeout = 3.0,
        #[\SensitiveParameter] ?string $secureHashKey = '',
        public readonly string $merchantId = '',
        public readonly string $terminalId = '',
        public readonly EcrEnvironment $environment = EcrEnvironment::SIT,
        public readonly bool $autoInquireOnFailure = true,
        public readonly bool $guardFinancialOperations = false,
        public readonly ?string $stateDirectory = null,
        public readonly bool $extendTimeLimit = true,
        public readonly ?string $caBundlePath = null,
        public readonly ?string $timezone = null,
    ) {
        if ($minorUnitDigits < 0 || $minorUnitDigits > 4) {
            throw new \InvalidArgumentException('minorUnitDigits must be between 0 and 4');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('port must be between 1 and 65535');
        }
        foreach (['connectTimeout' => $connectTimeout, 'responseTimeout' => $responseTimeout, 'probeTimeout' => $probeTimeout] as $name => $seconds) {
            if (!is_finite($seconds) || $seconds <= 0) {
                throw new \InvalidArgumentException($name . ' must be a positive number of seconds');
            }
        }
        if ($timezone !== null && !\in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException('timezone must be a time zone identifier such as Asia/Muscat');
        }
        $key = $secureHashKey ?? '';
        if ($key !== '' && !self::isValidSecret($key)) {
            throw new \InvalidArgumentException(
                'secureHashKey must be an even-length hex string of at least 16 characters, or empty to send unsigned messages',
            );
        }
        $this->secureHashKey = $key;
    }

    /**
     * A copy with some values changed. Validated like the constructor.
     *
     * ```php
     * $config->with(responseTimeout: 60, ecrId: 'TILL-02')
     * ```
     */
    public function with(mixed ...$changes): self
    {
        foreach (array_keys($changes) as $name) {
            if (!\is_string($name) || !\array_key_exists($name, $this->arguments())) {
                throw new \InvalidArgumentException('EcrConfig has no setting named ' . $name);
            }
        }
        return new self(...array_merge($this->arguments(), $changes));
    }

    /** Whether this till signs what it sends. */
    public function signsMessages(): bool
    {
        return $this->secureHashKey !== '';
    }

    /** Whether `$key` is acceptable for {@see $secureHashKey}: empty, or even-length hex of at least 16 characters. */
    public static function isValidSecureHashKey(#[\SensitiveParameter] ?string $key): bool
    {
        return $key === null || $key === '' || self::isValidSecret($key);
    }

    /** @internal */
    public static function isValidSecret(#[\SensitiveParameter] string $key): bool
    {
        return \strlen($key) >= self::MIN_SECRET_LENGTH && \strlen($key) % 2 === 0 && ctype_xdigit($key);
    }

    /** Never prints the secret. */
    public function __toString(): string
    {
        return sprintf(
            'EcrConfig(ecrId=%s, port=%d, environment=%s, merchantId=%s, terminalId=%s, secureHashKey=%s)',
            $this->ecrId,
            $this->port,
            $this->environment->value,
            $this->merchantId,
            $this->terminalId,
            $this->secureHashKey === '' ? 'empty' : '<redacted>',
        );
    }

    /** What `var_dump()` and `print_r()` show: everything but the secret. */
    public function __debugInfo(): array
    {
        $info = $this->arguments();
        $info['secureHashKey'] = $this->secureHashKey === '' ? 'empty' : '<redacted>';
        return $info;
    }

    /** A config holds a signing secret; it is not written to sessions, caches or queues. */
    public function __serialize(): array
    {
        throw new \LogicException('EcrConfig holds a signing secret and cannot be serialized');
    }

    /** @return array<string, mixed> */
    private function arguments(): array
    {
        return [
            'ecrId' => $this->ecrId,
            'currencyCode' => $this->currencyCode,
            'minorUnitDigits' => $this->minorUnitDigits,
            'port' => $this->port,
            'connectTimeout' => $this->connectTimeout,
            'responseTimeout' => $this->responseTimeout,
            'probeTimeout' => $this->probeTimeout,
            'secureHashKey' => $this->secureHashKey,
            'merchantId' => $this->merchantId,
            'terminalId' => $this->terminalId,
            'environment' => $this->environment,
            'autoInquireOnFailure' => $this->autoInquireOnFailure,
            'guardFinancialOperations' => $this->guardFinancialOperations,
            'stateDirectory' => $this->stateDirectory,
            'extendTimeLimit' => $this->extendTimeLimit,
            'caBundlePath' => $this->caBundlePath,
            'timezone' => $this->timezone,
        ];
    }
}
