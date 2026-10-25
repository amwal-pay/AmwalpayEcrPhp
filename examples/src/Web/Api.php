<?php

declare(strict_types=1);

namespace AmwalExample\Web;

use AmwalExample\Core\AmountEntry;
use AmwalExample\Core\EcrMode;
use AmwalExample\Core\TerminalValidator;
use AmwalExample\Core\TransactionFormState;
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\EcrTransactionType;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The JSON API, route for route the web sample's minimal API: meta, terminals,
 * settings, transactions, log and QR. Handlers only translate; the flow is the
 * Core engine run by the {@see TransactionRegistry}.
 */
final class Api
{
    /** A receipt URL is short; this is far above one and well below what a QR can carry. */
    public const MAX_QR_TEXT_LENGTH = 1024;

    public function __construct(private readonly EcrApp $app)
    {
    }

    /** @param array<string, mixed> $body */
    public function handle(string $method, string $path, array $query, array $body): Response
    {
        // Split before decoding, so an encoded "/" in a serial stays part of it.
        $segments = array_map('rawurldecode', \array_slice(explode('/', trim($path, '/')), 1));
        $a = $segments[1] ?? '';
        $b = $segments[2] ?? '';

        return match (true) {
            $method === 'GET' && $segments === ['meta'] => Response::json(200, Views::meta()),

            $method === 'GET' && $segments === ['terminals'] => $this->terminals(),
            $method === 'POST' && $segments === ['terminals'] => $this->saveTerminal($body, null),
            $method === 'PUT' && \count($segments) === 2 && $segments[0] === 'terminals' => $this->app->terminals->findBySerial($a) === null
                ? self::error(404, 'No such terminal')
                : $this->saveTerminal($body, $a),
            $method === 'DELETE' && \count($segments) === 2 && $segments[0] === 'terminals' => $this->deleteTerminal($a),
            $method === 'GET' && \count($segments) === 3 && $segments[0] === 'terminals' && $b === 'config' => $this->config($a, ($query['announce'] ?? '') === 'true'),

            $method === 'GET' && $segments === ['settings'] => Response::json(200, Views::settings($this->app->settings)),
            $method === 'PUT' && $segments === ['settings', 'environment'] => $this->setEnvironment($body),
            $method === 'PUT' && \count($segments) === 3 && $segments[0] === 'settings' && $a === 'keys' => $this->setKey($b, $body),
            $method === 'DELETE' && \count($segments) === 3 && $segments[0] === 'settings' && $a === 'keys' => $this->clearKey($b),

            $method === 'GET' && $segments === ['transactions'] => Response::json(200, ['transactions' => array_map(Views::transaction(...), $this->app->registry->pending())]),
            $method === 'POST' && $segments === ['transactions'] => $this->start($body),
            $method === 'GET' && \count($segments) === 2 && $segments[0] === 'transactions' => $this->find($a),
            $method === 'POST' && \count($segments) === 3 && $segments[0] === 'transactions' && $b === 'cancel' => $this->cancel($a),
            $method === 'POST' && \count($segments) === 3 && $segments[0] === 'transactions' && $b === 'inquire' => $this->inquire($a),
            $method === 'POST' && \count($segments) === 3 && $segments[0] === 'transactions' && $b === 'receipt' => $this->receipt($a),
            $method === 'POST' && \count($segments) === 3 && $segments[0] === 'transactions' && $b === 'close-receipt' => $this->closeReceipt($a),
            $method === 'DELETE' && \count($segments) === 2 && $segments[0] === 'transactions' => $this->app->registry->acknowledge($a)
                ? Response::empty(204)
                : self::error(409, 'The transaction is still running'),

            $method === 'GET' && $segments === ['qr'] => $this->qr((string) ($query['text'] ?? '')),
            $method === 'GET' && $segments === ['log'] => Response::json(200, ['text' => $this->app->log->snapshot(), 'count' => $this->app->log->count(), 'capacity' => \AmwalExample\Core\LogBuffer::CAPACITY]),
            $method === 'DELETE' && $segments === ['log'] => $this->clearLog(),

            default => self::error(404, 'No such endpoint'),
        };
    }

    // ── Terminals ────────────────────────────────────────────────────

    private function terminals(): Response
    {
        return Response::json(200, [
            'terminals' => array_map(Views::terminal(...), $this->app->terminals->getAll()),
            'notice' => $this->app->terminals->loadWarning,
        ]);
    }

    /** @param array<string, mixed> $form */
    private function saveTerminal(array $form, ?string $originalSerial): Response
    {
        $value = $form['ecrMode'] ?? null;
        $mode = \is_int($value) ? EcrMode::fromValue($value) : null;
        if ($mode === null) {
            return self::fields(['mode' => 'Choose an ECR mode']);
        }
        [$name, $serial, $ip, $port, $terminalId, $merchantId] = array_map(
            static fn (string $k): string => \is_scalar($form[$k] ?? null) ? (string) $form[$k] : '',
            ['name', 'serialNumber', 'ipAddress', 'port', 'terminalId', 'merchantId'],
        );
        $errors = TerminalValidator::validate($name, $serial, $mode, $ip, $port, $terminalId, $merchantId);
        if ($errors !== []) {
            return self::fields($errors);
        }
        $terminal = TerminalValidator::toTerminal($name, $serial, $mode, $ip, $port, $terminalId, $merchantId);
        $failure = $this->app->terminals->save($terminal, $originalSerial);
        // A duplicate serial is shown against the serial field, where it can be fixed.
        return $failure !== null ? self::fields(['serial' => $failure]) : Response::json(200, Views::terminal($terminal));
    }

    private function deleteTerminal(string $serial): Response
    {
        if ($this->app->terminals->findBySerial($serial) === null) {
            return self::error(404, 'No such terminal');
        }
        $failure = $this->app->terminals->delete($serial);
        return $failure !== null ? self::error(500, 'Could not delete the terminal: ' . $failure) : Response::empty(204);
    }

    /** announce=true is the "selection changed" log line: only when the operator picks a terminal. */
    private function config(string $serial, bool $announce): Response
    {
        $terminal = $this->app->terminals->findBySerial($serial);
        if ($terminal === null) {
            return self::error(404, 'No such terminal');
        }
        $config = $this->app->engine->resolveConfig($terminal);
        if ($announce) {
            $this->app->engine->logSelectedConfig($config);
        }
        return Response::json(200, Views::config($config));
    }

    // ── Settings: keys are write-only ─────────────────────────────────

    /** @param array<string, mixed> $body */
    private function setEnvironment(array $body): Response
    {
        $name = strtoupper(trim(\is_string($body['environment'] ?? null) ? $body['environment'] : ''));
        $environment = EcrEnvironment::tryFrom($name);
        if ($environment === null) {
            return self::fields(['environment' => 'Choose SIT, UAT or PROD']);
        }
        $this->app->settings->setEnvironment($environment);
        return $this->savedSettings();
    }

    /** @param array<string, mixed> $body */
    private function setKey(string $kind, array $body): Response
    {
        if ($kind !== 'wifi' && $kind !== 'webService') {
            return self::error(404, 'No such key');
        }
        $key = trim(\is_string($body['value'] ?? null) ? $body['value'] : '');
        // Blank is what Clear is for: replacing a key with nothing by accident would
        // quietly disable the terminals that use it.
        if ($key === '') {
            return self::fields(['value' => 'Enter the key, or use Clear to remove it']);
        }
        if (!EcrConfig::isValidSecureHashKey($key)) {
            return self::fields(['value' => 'Must be an even-length hex string of at least 16 characters']);
        }
        $kind === 'webService' ? $this->app->settings->setWebServiceSecureHashKey($key) : $this->app->settings->setWifiSecureHashKey($key);
        return $this->savedSettings();
    }

    private function clearKey(string $kind): Response
    {
        if ($kind !== 'wifi' && $kind !== 'webService') {
            return self::error(404, 'No such key');
        }
        $kind === 'webService' ? $this->app->settings->setWebServiceSecureHashKey('') : $this->app->settings->setWifiSecureHashKey('');
        return $this->savedSettings();
    }

    /** The value stays in memory when the file cannot be written, so say so instead of a false "saved". */
    private function savedSettings(): Response
    {
        $error = $this->app->settings->saveError;
        return $error !== null ? self::error(500, $error) : Response::json(200, Views::settings($this->app->settings));
    }

    // ── Transactions ──────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function start(array $body): Response
    {
        $serial = self::text($body, 'terminalSerial');
        $terminal = $serial !== '' ? $this->app->terminals->findBySerial($serial) : null;
        if ($terminal === null) {
            return self::fields(['terminal' => $serial !== '' ? 'Terminal ' . $serial . ' is no longer registered' : 'Choose a POS terminal']);
        }
        // The Start button is disabled until the plan is ready, so the operator sees
        // what is missing before paying. The server holds the same line.
        $config = $this->app->engine->resolveConfig($terminal);
        if (!$config->isReady()) {
            return Response::json(422, ['errors' => new \stdClass(), 'message' => implode("\n", $config->issues())]);
        }
        $type = null;
        foreach (EcrTransactionType::menuOptions() as $option) {
            if (strcasecmp($option->value, trim(self::text($body, 'type'))) === 0) {
                $type = $option;
            }
        }
        if ($type === null) {
            return self::fields(['type' => 'Choose a transaction type']);
        }
        $digits = static fn (string $v): string => preg_replace('/[^0-9]/', '', $v) ?? '';
        $originalReference = self::text($body, 'originalReference');
        $form = new TransactionFormState(
            type: $type,
            // The digits the amount field keeps: ASCII digits, no leading zeros, capped.
            amountDigits: AmountEntry::keyedDigits(self::text($body, 'amountDigits')),
            receiptNumber: substr($digits(self::text($body, 'receiptNumber')), 0, TransactionFormState::RECEIPT_NUMBER_MAX_LENGTH),
            originalTerminalId: $digits(self::text($body, 'originalTerminalId')),
            originalDate: self::date(self::text($body, 'originalDate')),
            useOtherTerminal: ($body['useOtherTerminal'] ?? false) === true,
            lookUpByReference: ($body['lookUpByReference'] ?? false) === true,
            // Capped at 32 as it is typed. The sale's own reference is not cut:
            // validation reports an over-long one, with its message.
            originalReference: substr($originalReference, 0, TransactionFormState::MERCHANT_REFERENCE_MAX_LENGTH),
            merchantReference: self::text($body, 'merchantReference'),
        );
        [$request, $errors] = $form->validated($terminal->serialNumber);
        if ($request === null) {
            return self::fields($errors);
        }
        $ecr = $config->ecrConfig();
        // The countdown covers the whole wait: 120 s Wi-Fi, 160 s (connect + answer) Web Service.
        $timeout = (int) round($config->usesWebService() ? $ecr->connectTimeout + $ecr->responseTimeout : $ecr->responseTimeout);
        $started = $this->app->registry->start($request, $config->usesWebService(), max(1, $timeout));
        return $started[0] === 'busy'
            ? self::error(409, 'A transaction is already running on this terminal', $started[1])
            : Response::json(202, Views::transaction($started[1]));
    }

    private function find(string $id): Response
    {
        $run = $this->app->registry->find($id);
        return $run === null ? self::error(404, 'No such transaction') : Response::json(200, Views::transaction($run));
    }

    private function cancel(string $id): Response
    {
        $run = $this->app->registry->cancel($id);
        return $run === null ? self::error(404, 'No such transaction') : Response::json(200, Views::transaction($run));
    }

    private function inquire(string $id): Response
    {
        $outcome = $this->app->registry->inquire($id);
        return match ($outcome[0]) {
            'started' => Response::json(202, Views::transaction($outcome[1])),
            'notFound' => self::error(404, 'No such transaction'),
            'busy' => self::error(409, 'Another transaction is running on this terminal', $outcome[1]),
            default => self::error(409, 'This transaction has no unknown outcome to look up'),
        };
    }

    private function receipt(string $id): Response
    {
        $outcome = $this->app->registry->requestReceipt($id);
        return match ($outcome[0]) {
            'started' => Response::json(202, Views::transaction($outcome[1])),
            'notFound' => self::error(404, 'No such transaction'),
            default => self::error(409, 'No receipt can be requested for this transaction'),
        };
    }

    private function closeReceipt(string $id): Response
    {
        $outcome = $this->app->registry->closeReceipt($id);
        return match ($outcome[0]) {
            'done' => Response::json(200, ['message' => $outcome[2], 'transaction' => Views::transaction($outcome[1])]),
            'notFound' => self::error(404, 'No such transaction'),
            default => self::error(409, $outcome[1] ?? 'Close receipt is not available for this transaction'),
        };
    }

    // ── Log and QR ────────────────────────────────────────────────────

    private function clearLog(): Response
    {
        $this->app->log->clear();
        return Response::empty(204);
    }

    /** Drawn here rather than by a script on the page: nothing to fetch and no client library. */
    private function qr(string $text): Response
    {
        if (trim($text) === '') {
            return self::error(400, 'Nothing to encode');
        }
        if (\strlen($text) > self::MAX_QR_TEXT_LENGTH) {
            return self::error(400, 'The text is longer than ' . self::MAX_QR_TEXT_LENGTH . ' characters');
        }
        try {
            // A receipt URL is scanned off a screen at arm's length; M keeps it readable.
            $writer = new Writer(new ImageRenderer(new RendererStyle(200, 2), new SvgImageBackEnd()));
            return new Response(200, ['Content-Type' => 'image/svg+xml'], $writer->writeString($text, 'UTF-8', ErrorCorrectionLevel::M()));
        } catch (\Throwable) {
            return self::error(400, 'The text is too long for a QR code');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private static function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : '');
    }

    /** yyyy-MM-dd (a date input) or yyyyMMdd; blank or unreadable is today, and the future is today. */
    private static function date(string $value): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable('today');
        $value = trim($value);
        foreach (['!Y-m-d', '!Ymd'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date > $today ? $today : $date;
            }
        }
        return $today;
    }

    /** @param array<string, string> $errors */
    private static function fields(array $errors): Response
    {
        return Response::json(422, ['errors' => (object) $errors, 'message' => null]);
    }

    private static function error(int $status, string $message, ?string $transactionId = null): Response
    {
        return Response::json($status, ['error' => $message, 'transactionId' => $transactionId]);
    }
}
