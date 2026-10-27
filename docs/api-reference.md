# amwalpay/ecr-php API reference

This page lists the public API of the Composer package `amwalpay/ecr-php` (namespace `AmwalPay\Ecr`, PHP >= 8.1). The package connects a merchant's till to an Amwal POS terminal over Wi-Fi, over a USB cable, or through the Amwal Hub (ECR Web Service). For every public class, enum, interface, constant, constructor and method it gives the signature, what it returns and what it throws. Everything under `AmwalPay\Ecr\Internal\`, and anything marked `@internal`, is not API and can change without notice.

> **Amounts are major units.** Pass a decimal string (`'1.234'`), a whole number (`5`), a float (read through its shortest exact form, so `1.005` stays `1.005`) or an `Amount`. Text that is not a number throws `InvalidRequestException`.

## Contents

- [Sessions](#sessions): `EcrSessions`, `EcrSessionPlan`, `EcrOpenedSession`
- [Configuration](#configuration): `EcrConfig`, `EcrEnvironment`, `EcrSdk`, `Amount`
- [Links](#links): `Link\EcrLink`, `Link\LanLink`, `Link\UsbCableLink`, `Link\WebServiceLink`
- [Terminals](#terminals): `EcrTerminal`, `EcrWebServiceTerminal`, `EcrReachability`
- [Results](#results): `EcrResult`, `EcrInquiry`, `EcrReceipt`, `EcrReceiptClosed`, `EcrSettlement` families, `EcrTransaction`, `NextStep`
- [Failures](#failures): `Failure\*`
- [Exceptions](#exceptions): `Exception\*`
- [Safety](#safety): `FinancialGuard`, `JournalEntry`, `TransactionJournal`, `FileTransactionJournal`, `TerminalLock`, `FileTerminalLock`
- [Logging](#logging): `Log\*`
- [Channel](#channel): `EcrChannel`, `TcpEcrChannel`, `SerialEcrChannel`, `EcrFrames`, `EcrIOException`, `EcrChannelTimeout`
- [Serial (USB cable)](#serial-usb-cable): `SerialPorts`, `SerialPortChoice`, `ConnectedPorts`, `SerialPortBinding`, `SerialLine`, `SerialLines`, `WindowsSerialLine`, `PosixSerialLine`
- [Web Service](#web-service): `WebServiceRequests`, `WebServiceSecureHash`, `WebServiceRoutes`, `WebServiceOperation`, `WebServiceApi`, `Http\*`
- [EcrWireResponse](#ecrwireresponse)
- [EcrTransactionType](#ecrtransactiontype)
- [Json (supporting types)](#json-supporting-types)

---

## Sessions

The way in. Validate a link and its credentials into a plan, then open one session for that plan. The session handles every operation the same way over Wi-Fi, USB cable and Web Service.

### `EcrSessions`

```php
namespace AmwalPay\Ecr;

final class EcrSessions
{
    public static function plan(EcrLink $link, EcrConfig $config, #[\SensitiveParameter] ?string $rawSecureHashKey = null): EcrSessionPlan;
    public static function open(
        string $terminalSerial,
        EcrSessionPlan $plan,
        ?EcrLogger $logger = null,
        ?HttpTransport $httpTransport = null,
        ?FinancialGuard $guard = null,
        ?\Closure $usbChannel = null,      // Closure(): EcrChannel
    ): EcrOpenedSession;
    public static function lanTerminal(string $terminalSerial, EcrSessionPlan $plan, ?EcrLogger $logger = null, ?FinancialGuard $guard = null): EcrTerminal;
    public static function usbCableTerminal(string $terminalSerial, EcrSessionPlan $plan, EcrChannel $channel, ?EcrLogger $logger = null, ?FinancialGuard $guard = null): EcrTerminal;
    public static function webServiceTerminal(string $terminalSerial, EcrSessionPlan $plan, ?EcrLogger $logger = null, ?HttpTransport $httpTransport = null, ?FinancialGuard $guard = null): EcrWebServiceTerminal;
    public static function newMerchantReference(): string;
}
```

| Method | Returns | Notes |
|---|---|---|
| `plan()` | `EcrSessionPlan` | Validates `$link` and `$config` without opening a connection. Problems come back as the plan's `issues()` instead of exceptions, so a settings screen can show what is missing. `$rawSecureHashKey` is the key as the operator typed it: it is trimmed and checked here, and defaults to the config's key. For a `LanLink`, the link's port replaces the config's port. For a `WebServiceLink`, the merchant and terminal IDs are copied into the config. If the response timeout is still the Wi-Fi default, it becomes `EcrConfig::WEB_SERVICE_RESPONSE_TIMEOUT` (130 s). Hosts and IDs are kept as given; an ID is numeric when `long.TryParse` would read it (surrounding white space and a sign are allowed). A `UsbCableLink` has nothing to validate: whether a cable is plugged in is answered by the probe. A missing signing key is reported as an issue (`Wi-Fi/CABLE secure hash key is not configured` for Wi-Fi and USB). Throws `\InvalidArgumentException` only for a link type it does not support. |
| `open()` | `EcrOpenedSession` | Opens the transport the plan describes. There is one dispatch for Wi-Fi, USB cable and Web Service, so sale, inquiry and recovery always build the same client. `$terminalSerial` is the terminal's serial number as registered. `$httpTransport` applies to Web Service only and sets how the HTTPS post is made; `null` uses cURL. `$guard` replaces the file-based guard built from the config. `$usbChannel` is required for a USB cable plan and ignored otherwise: it builds the channel, e.g. `fn () => new SerialEcrChannel('COM8')`. `$logger` defaults to `EcrLogger::none()`. **Throws** `\InvalidArgumentException` if the plan has issues, `\LogicException` ("USB cable sessions require a channel factory") for a cable plan without `$usbChannel`. The serial is sent as given. |
| `lanTerminal()` | `EcrTerminal` | Builds the Wi-Fi terminal directly. Throws `\InvalidArgumentException` if the plan is not for Wi-Fi. |
| `usbCableTerminal()` | `EcrTerminal` | Builds the terminal down a USB cable on `$channel`. Everything above the channel is identical to Wi-Fi. Throws `\InvalidArgumentException` if the plan is not for USB cable. |
| `webServiceTerminal()` | `EcrWebServiceTerminal` | Builds the Web Service terminal directly. Throws `\InvalidArgumentException` if the plan is not for Web Service. |
| `newMerchantReference()` | `string` | A fresh merchant reference in the SDK's own format: 12 uppercase hex characters. Generate it, save it with the order, then send it. |

```php
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Link\LanLink;

$plan = EcrSessions::plan(
    new LanLink('192.168.1.50'),
    new EcrConfig(ecrId: 'TILL-01', secureHashKey: $wifiKey),
);
if (!$plan->isReady()) {
    exit(implode("\n", $plan->issues()));
}
$session = EcrSessions::open('SN-WIFI-0001', $plan);
```

### `EcrSessionPlan`

A validated configuration for one terminal link, built by `EcrSessions::plan()` so the app does not have to repeat transport rules. `issues()` lists what is wrong. An empty list means the plan can be opened.

```php
final class EcrSessionPlan
{
    public function __construct(
        public readonly EcrLink $link,
        public readonly EcrConfig $config,
        array $issues = [],          // list<string>
    );
    public function issues(): array;              // list<string>
    public function isReady(): bool;
    public function usesLan(): bool;
    public function usesWebService(): bool;
    public function usesUsbCable(): bool;
    public function signingKeyLabel(): string;
    public function signingKeyConfigured(): bool;
    public function connectionSummary(): string;
}
```

- `issues()`: what is wrong with this plan; empty when it can be opened.
- `isReady()`: `true` when `issues()` is empty.
- `usesLan()` / `usesUsbCable()` / `usesWebService()`: which kind of link the plan uses.
- `signingKeyLabel()`: which of the two secrets this plan expects: `Wi-Fi/CABLE secure hash key` (Wi-Fi and USB) or `Web Service secure hash key`. They are issued separately.
- `signingKeyConfigured()`: whether the resolved config has a key.
- `connectionSummary()`: a summary for the operator that never contains a secret, e.g. `192.168.1.50:9100`, `COM8` (`USB cable` when no port is chosen) or `UAT · merchant 10001 · terminal 20002`. Throws `\LogicException` for a link type it does not support.

### `EcrOpenedSession`

One opened transport for a validated plan. Every sale, void, refund, inquiry, e-receipt, close receipt and settlement for that terminal goes through it, the same way over Wi-Fi, USB cable and Web Service. Get one from `EcrSessions::open()`. The constructor is `@internal`.

```php
final class EcrOpenedSession
{
    public readonly EcrSessionPlan $plan;

    public function usesWebService(): bool;
    public function usesLocalTerminal(): bool;
    public function supportsReceipt(): bool;
    public function supportsSettlement(): bool;
    public function lanTerminal(): ?EcrTerminal;
    public function webServiceTerminal(): ?EcrWebServiceTerminal;
    public function probeReachability(): ?EcrReachability;
    public function checkReachability(): ?EcrReachability;

    public function sale(string|int|Amount $amount, string $merchantReference = ''): EcrResult;
    public function void(string $receiptNumber, string $originalTerminalId = '', string $merchantReference = ''): EcrResult;
    public function refund(string|int|Amount $amount, string $receiptNumber, string $transactionDate, string $originalTerminalId = '', string $merchantReference = ''): EcrResult;
    public function inquire(string $receiptNumber, string $transactionDate, string $originalTerminalId = ''): EcrInquiry;
    public function inquireByReference(string $originalReference, string $transactionDate = '', string $originalTerminalId = '', string $merchantReference = ''): EcrInquiry;
    public function receipt(string $receiptNumber, string $transactionDate, string $originalTerminalId = ''): EcrReceipt;
    public function closeReceipt(string $merchantReference = '', string $originalMerchantReference = ''): EcrReceiptClosed;
    public function settlement(string $merchantReference = ''): EcrSettlement;

    public function terminalKey(): string;
    public function guard(): FinancialGuard;
    public function pendingOutcomes(): array;   // list<JournalEntry>
    public function reconcile(): array;         // array<string, EcrInquiry>
    public function markResolved(string $merchantReference, string $note): JournalEntry;
}
```

| Method | Returns | Notes |
|---|---|---|
| `usesWebService()` / `usesLocalTerminal()` | `bool` | Which transport the session uses. Wi-Fi and USB cable are both the local terminal. |
| `supportsReceipt()` | `bool` | Receipt URLs exist only on the local protocol (Wi-Fi and USB cable). |
| `supportsSettlement()` | `bool` | Always `true`: Wi-Fi and USB send `SETTLEMENT`, Web Service posts `/Ecr/settle`. |
| `lanTerminal()` | `?EcrTerminal` | The Wi-Fi or USB cable terminal behind the session, or `null` on Web Service. |
| `webServiceTerminal()` | `?EcrWebServiceTerminal` | The Web Service terminal behind the session, or `null` on Wi-Fi and USB. |
| `probeReachability()` | `?EcrReachability` | Checks before a sale whether the Wi-Fi port or the cable's COM port answers. Always `null` on Web Service, so a sale is never held for a Hub probe. |
| `checkReachability()` | `?EcrReachability` | Web Service: posts `/Ecr/CheckReachability` with `probeTimeout` as its whole deadline. `null` on Wi-Fi and USB. A sale does not call it. |
| `sale()` | `EcrResult` | `$amount` is in major units: a string such as `"1.234"`, an int or a float. `$merchantReference` is this attempt's own reference: save it before calling. |
| `void()` | `EcrResult` | `$originalTerminalId` is used on Wi-Fi only; Web Service uses the terminal in the plan. |
| `refund()` | `EcrResult` | `$transactionDate` is the original transaction's day as `yyyyMMdd`. `$originalTerminalId` is used on Wi-Fi only. |
| `inquire()` | `EcrInquiry` | Looks up a transaction by receipt number and date. `$originalTerminalId` is used on Wi-Fi only. |
| `inquireByReference()` | `EcrInquiry` | Reads the attempt you sent as `$originalReference`. Use it to get the result of a sale, void or refund that never answered. `$originalTerminalId` is used on Wi-Fi only. |
| `receipt()` | `EcrReceipt` | Wi-Fi only. On Web Service it returns `ReceiptUnavailable` ("Receipt fetch is only supported over Wi-Fi ECR"). |
| `closeReceipt()` | `EcrReceiptClosed` | Asks the terminal to put its receipt away and return to idle. Call it from the till's Close receipt action, never automatically. Pass both arguments by name, since both are strings. `$merchantReference` is this close request's own ID; when blank, one is generated. `$originalMerchantReference` is the sale, void or refund being dismissed; Web Service requires it and Wi-Fi ignores it. On Web Service, if only `$merchantReference` is given, it is used as the original and the SDK generates the close request's own ID. If neither is given, the result is `CloseReceiptFailed` with a `Malformed` failure. |
| `settlement()` | `EcrSettlement` | Closes the current batch. Wi-Fi and USB send `SETTLEMENT`; Web Service posts `/Ecr/settle`. Confirm with the cashier first: the close starts as soon as the request arrives. Uses the full response timeout. Safe to repeat when the answer is lost. Never blocked by the guard. |
| `terminalKey()` | `string` | Identifies this terminal to the guard's lock and journal. |
| `guard()` | `FinancialGuard` | The guard the terminal uses. |
| `pendingOutcomes()` | `list<JournalEntry>` | Sales, voids and refunds on this terminal whose outcome is still unknown. While any are listed, a new money-moving call returns `Failed` with `Failure\Unresolved`. |
| `reconcile()` | `array<string, EcrInquiry>` | Inquires by reference for every unknown outcome on this terminal and lets the answers settle what they can. The result is keyed by the original merchant reference. Run it at start-up and after a failure. It is safe to repeat, because an inquiry moves no money. |
| `markResolved()` | `JournalEntry` | Settles an unknown outcome by hand once it has been confirmed on the terminal or with Amwal, for example an inquiry that keeps answering "not found". `$note` records how it was confirmed. Throws `\InvalidArgumentException` if no transaction with that reference was sent to this terminal, or if the note is blank. |

Money-moving calls (`sale`, `void`, `refund`) throw `Exception\InvalidRequestException` when a request cannot be built, and nothing is sent in that case. With the guard enabled they also throw `Exception\DuplicateMerchantReferenceException` (see [Safety](#safety)). `inquireByReference()` throws `InvalidRequestException` if `$originalReference` is blank or malformed.

```php
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Result\InquiryFailed;

foreach ($session->reconcile() as $reference => $inquiry) {
    if ($inquiry instanceof Found) {
        // $inquiry->transaction->status says what became of it (Approved, Declined, ...).
        updateOrder($reference, $inquiry->transaction);
    } elseif ($inquiry instanceof NotFound) {
        // Not proof that nothing was charged. Confirm on the terminal or with Amwal, then:
        // $session->markResolved($reference, 'Confirmed on the terminal transaction list');
    } elseif ($inquiry instanceof InquiryFailed) {
        // Nothing was learned. Run reconcile() again later.
    }
}
```

---

## Configuration

### `EcrConfig`

Defines how this ECR identifies itself and how it talks to a terminal. The object is immutable. Invalid values are refused when the config is built, so a key with a stray space fails at start-up instead of showing up later as a decline the cashier cannot explain. Timeouts are in seconds. The defaults describe an Omani deployment (OMR, three decimals) on the standard port.

```php
final class EcrConfig
{
    public const DEFAULT_PORT = 9100;
    public const DEFAULT_RESPONSE_TIMEOUT = 120.0;
    public const DEFAULT_CONNECT_TIMEOUT = 10.0;
    public const WEB_SERVICE_RESPONSE_TIMEOUT = 130.0;

    public readonly string $secureHashKey;

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
    );

    public function with(mixed ...$changes): self;
    public function signsMessages(): bool;
    public static function isValidSecureHashKey(#[\SensitiveParameter] ?string $key): bool;
    public function __toString(): string;
    public function __debugInfo(): array;
    public function __serialize(): array;
}
```

| Constant | Value | Meaning |
|---|---|---|
| `DEFAULT_PORT` | `9100` | The port Amwal POS terminals listen on for ECR requests. |
| `DEFAULT_RESPONSE_TIMEOUT` | `120.0` | Wi-Fi wait for the terminal's answer: card present plus PIN. |
| `DEFAULT_CONNECT_TIMEOUT` | `10.0` | Connect wait. |
| `WEB_SERVICE_RESPONSE_TIMEOUT` | `130.0` | The answer wait `EcrSessions::plan()` gives a Web Service link when the caller left the Wi-Fi default (`EcrConfig.WebServiceResponseTimeout` in the Windows SDK). With the default connect timeout, the HTTP deadline is 140 s. |

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `ecrId` | `string` | `'ECR01'` | Identifies this cash register to the terminal and on its records. |
| `currencyCode` | `string` | `'512'` | ISO 4217 numeric code: `512` for OMR. Sent as given. |
| `minorUnitDigits` | `int` | `3` | Decimal places the currency has (0 to 4): 3 for OMR, 2 for USD. |
| `port` | `int` | `9100` | The terminal's ECR listener port (1 to 65535). |
| `connectTimeout` | `float` | `10.0` | Seconds to wait for the terminal to accept a connection. |
| `responseTimeout` | `float` | `120.0` | Seconds to wait for the result once the request is sent. |
| `probeTimeout` | `float` | `3.0` | Seconds a reachability probe waits. |
| `secureHashKey` | `?string` | `''` | The signing secret, as hex. Wi-Fi and Web Service use different secrets. It must be empty or an even-length hex string of at least 16 characters. Empty sends unsigned messages. |
| `merchantId` | `string` | `''` | Backend merchant ID, for Web Service. |
| `terminalId` | `string` | `''` | Backend terminal ID, for Web Service. |
| `environment` | `EcrEnvironment` | `SIT` | Hub deployment for Web Service. |
| `autoInquireOnFailure` | `bool` | `true` | After a lost Wi-Fi answer, sends one inquiry by merchant reference. |
| `guardFinancialOperations` | `bool` | `false` | Opt-in (the Windows SDK has no guard). Allows one money-moving call per terminal at a time, requires unique references, and blocks new money movement while an earlier outcome is unknown. See `Safety\FinancialGuard`. |
| `stateDirectory` | `?string` | `null` | Where the guard keeps its locks and journal. `null` uses the system temp folder. Use a durable, private folder in production. |
| `extendTimeLimit` | `bool` | `true` | Raises PHP's `max_execution_time` so it cannot kill a payment mid-wait. |
| `caBundlePath` | `?string` | `null` | CA bundle for the Web Service TLS check, for when PHP has none configured. |
| `timezone` | `?string` | `null` | Time zone for `transactionDateTime`. `null` uses the machine's local time (the operating system's time zone, as the Windows SDK's `DateTime.Now`), not php.ini's `date.timezone`. When set, must be a valid identifier such as `Asia/Muscat`. |

**Throws** `\InvalidArgumentException` for any value outside the rules above, including timeouts that are not positive and finite.

- `with(...$changes)`: returns a copy with some values changed, validated like the constructor, e.g. `$config->with(responseTimeout: 60, ecrId: 'TILL-02')`. Throws `\InvalidArgumentException` for an unknown setting name.
- `signsMessages()`: whether this till signs what it sends (the key is not empty).
- `isValidSecureHashKey($key)`: whether `$key` is acceptable: `null`, empty, or even-length hex of at least 16 characters.
- `__toString()` / `__debugInfo()`: never show the secret. They print `<redacted>` or `empty` in its place.
- `__serialize()`: always throws `\LogicException`. A config holds a signing secret, so it is never written to sessions, caches or queues.

### `EcrEnvironment`

The deployment environment for Web Service ECR. The SDK resolves the Amwal Hub address itself; you pass only this value in `EcrConfig::$environment`.

```php
enum EcrEnvironment: string
{
    case SIT = 'SIT';
    case UAT = 'UAT';
    case PROD = 'PROD';

    public const DEFAULT = self::SIT;

    public static function fromName(?string $name): self;
}
```

- `fromName()`: reads an environment by name, ignoring case and surrounding spaces. Unknown names read as `SIT`.

### `EcrSdk`

Facts about this build of the SDK, for logs and support tickets.

```php
final class EcrSdk
{
    public const VERSION = '1.0.0-rc.1';
    public const PROTOCOL_VERSION = 1;
    public const PARITY_WITH = 'AmwalPay.Ecr 1.0.2 (.NET)';
    public const MINIMUM_PHP = '8.1.0';

    /** The machine's time zone, which `transactionDateTime` uses when `EcrConfig::$timezone` is null. */
    public static function localTimeZone(): \DateTimeZone;
}
```

- `VERSION`: the package version, kept in step with the Git tag and `CHANGELOG.md`.
- `PROTOCOL_VERSION`: the ECR message format this SDK writes and reads (`version` on the wire).
- `PARITY_WITH`: the Windows SDK release whose behaviour this one matches.
- `MINIMUM_PHP`: the lowest supported PHP version.

### `Amount`

Money as exact decimal text. PHP has no decimal type and a float cannot hold `1.005` exactly, so amounts enter the SDK as strings (`"1.234"`) or whole numbers of major units (`5`), never as floats. The SDK rounds half away from zero to the currency's decimal places, then sends minor units: `"1.234"` OMR travels as `000000001234`.

```php
final class Amount
{
    public const MAX_MINOR_UNITS = 999_999_999_999;

    public readonly string $decimal;

    public static function of(string|int|self $amount): self;
    public static function fromMinorUnits(int $minorUnits, int $digits): self;
    public function toMinorUnits(int $digits): int;
    public function toWire(int $digits): string;
    public function format(int $digits): string;
    public function __toString(): string;
    public static function majorUnits(string $minorUnits, int $digits): string;
}
```

| Member | Returns | Notes |
|---|---|---|
| `MAX_MINOR_UNITS` | | The wire carries 12 digits of minor units (ISO 8583 field 4). |
| `of($amount)` | `Amount` | An amount in major units, e.g. `Amount::of('1.234')`. Throws `InvalidRequestException` for an int that is not greater than zero, or for text that is not a positive decimal with no sign, exponent or separators. |
| `fromMinorUnits($minorUnits, $digits)` | `Amount` | An amount already in minor units: `Amount::fromMinorUnits(1234, 3)` is `1.234`. Throws `InvalidRequestException` if not greater than zero. |
| `toMinorUnits($digits)` | `int` | Minor units for a currency with `$digits` decimal places, rounded half away from zero. **Throws** `InvalidRequestException` for anything that would round to nothing or does not fit the 12-digit wire field. |
| `toWire($digits)` | `string` | Twelve zero-padded digits of minor units, as the Wi-Fi and Web Service messages carry them. |
| `format($digits)` | `string` | The amount rounded to `$digits` places, e.g. `1.234`. |
| `__toString()` | `string` | The decimal text as given. |
| `majorUnits($minorUnits, $digits)` | `string` | Reads minor units from the wire as major units: `000000000365` is `0.365` with three decimals. Text that is not an integer is returned unchanged, because a terminal that sends `1.234` is already reporting major units. |

---

## Links

A link says how this till reaches a POS terminal. The app supplies addresses and backend IDs; the SDK handles the protocol, signing and transport.

```php
namespace AmwalPay\Ecr\Link;

abstract class EcrLink {}

final class LanLink extends EcrLink
{
    public function __construct(
        public readonly string $host,
        public readonly int $port = EcrConfig::DEFAULT_PORT,
    );
}

final class UsbCableLink extends EcrLink
{
    public function __construct(public readonly string $comPort = '');
}

final class WebServiceLink extends EcrLink
{
    public function __construct(
        public readonly string $merchantId,
        public readonly string $terminalId,
    );
}
```

- `EcrLink`: the base type: `LanLink` (Wi-Fi), `UsbCableLink` (a USB cable) or `WebServiceLink` (the Amwal Hub over HTTPS).
- `LanLink`: TCP to the IP address and port the terminal shows on its idle screen. The till and the terminal must be on the same network. `$host` is a host name or IP address; only a blank one is a plan issue.
- `UsbCableLink`: a USB cable straight from this till to the terminal. `$comPort` is the virtual serial port the operating system assigned to it, such as `COM8` ("MediaTek USB VCOM (Android)") or `/dev/ttyACM0`; the line is 115200 8N1. Empty means the operator has not chosen a port yet. The signing key is the Wi-Fi key. Open the session with `usbChannel: fn () => new SerialEcrChannel($port)`.
- `WebServiceLink`: HTTPS to the Amwal Hub, which relays to the terminal configured for Web Service mode (`ecrMode: 4`). The Hub address comes from `EcrConfig::$environment` and is resolved by the SDK. `$merchantId` and `$terminalId` are the numeric backend IDs, e.g. `'10001'` and `'20002'`.

---

## Terminals

The two transport clients behind a session. You usually reach them through `EcrOpenedSession`, but you can also use them directly.

### `EcrTerminal`

A POS terminal reachable over Wi-Fi, or down a USB cable through a `SerialEcrChannel`: the same signing, messages and answers either way. One instance addresses one terminal and holds no connection between calls, so it is cheap to keep and safe to reuse.

When the config has a key, every request is signed, and every answer must be signed with the same key and echo the request's nonce. Otherwise the answer is discarded as `Failure\Unauthenticated`. Without this check, anything on the network that answers on the terminal's port could claim "approved" for a payment that never happened.

```php
final class EcrTerminal
{
    public function __construct(
        EcrChannel|string $channel,
        string $serialNumber = '',
        EcrConfig $config = new EcrConfig(),
        ?EcrLogger $logger = null,
        ?FinancialGuard $guard = null,
    );

    public function terminalKey(): string;
    public function guard(): FinancialGuard;
    public function config(): EcrConfig;
    public function isReachable(): bool;
    public function probeReachability(): EcrReachability;

    public function sale(string|int|Amount $amount, string $merchantReference = ''): EcrResult;
    public function void(string $receiptNumber, string $originalTerminalId = '', string $merchantReference = ''): EcrResult;
    public function refund(string|int|Amount $amount, string $receiptNumber, string $transactionDate, string $originalTerminalId = '', string $merchantReference = ''): EcrResult;
    public function inquire(string $receiptNumber, string $transactionDate, string $originalTerminalId = '', string $merchantReference = ''): EcrInquiry;
    public function inquireByReference(string $originalReference, string $transactionDate = '', string $originalTerminalId = '', string $merchantReference = ''): EcrInquiry;
    public function receipt(string $receiptNumber, string $transactionDate, string $originalTerminalId = '', string $merchantReference = ''): EcrReceipt;
    public function closeReceipt(string $merchantReference = ''): EcrReceiptClosed;
    public function settlement(string $merchantReference = ''): EcrSettlement;
    public function run(EcrTransactionType $type, string|int|Amount|null $amount = null, string $originalStan = '', string $originalTerminalId = '', string $originalDate = '', string $merchantReference = ''): EcrResult;

    public static function formatDuration(float $seconds): string;
}
```

Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$channel` | `EcrChannel\|string` | | The terminal's host or IP address, or a channel that carries the bytes. A string becomes a `TcpEcrChannel` on `$config->port` with `$config->connectTimeout`. That channel throws `\InvalidArgumentException` for an empty host. |
| `$serialNumber` | `string` | `''` | Identifies the terminal in the message: the serial the operator registered. |
| `$config` | `EcrConfig` | `new EcrConfig()` | Settings. |
| `$logger` | `?EcrLogger` | `null` | `null` uses `EcrLogger::none()`. |
| `$guard` | `?FinancialGuard` | `null` | `null` uses `FinancialGuard::fromConfig($config, $logger)`. |

| Method | Returns | Notes |
|---|---|---|
| `terminalKey()` | `string` | Identifies this physical terminal to the guard's lock and journal (`wifi\|host\|port`, or `cable\|com8\|0` on a USB cable). |
| `isReachable()` | `bool` | Whether the terminal is listening. It proves the port is open, not that the terminal is idle. |
| `probeReachability()` | `EcrReachability` | Asks the channel whether the terminal is there, before sending anything. Worth calling before a sale: a wrong address then gets an immediate answer instead of a failure the cardholder has to wait through. |
| `sale()` | `EcrResult` | Takes a payment; the cardholder presents the card at the terminal. `$amount` is in major units: a string, an int or a float. `$merchantReference` is this attempt's own reference: save it before calling. When blank, one is generated. **Throws** `InvalidRequestException`, in which case nothing was sent. |
| `void()` | `EcrResult` | Cancels an earlier transaction for its full amount, by its receipt number. `$originalTerminalId` is the terminal the original was taken on, when that is not this one. `$merchantReference` is the void's own reference, not the original's. |
| `refund()` | `EcrResult` | Returns money against an earlier transaction, in full or in part. `$transactionDate` is the original's day as `yyyyMMdd`, because a receipt number is unique only within a day. |
| `inquire()` | `EcrInquiry` | Asks what became of an earlier transaction by its receipt number, without touching it. Safe to repeat, and answered even while the terminal is busy. `$merchantReference` is the inquiry's own reference, not a way to look a transaction up. |
| `inquireByReference()` | `EcrInquiry` | Asks what became of the transaction this till sent as `$originalReference`. Use it for an outcome you never received: after a timeout or a lost connection there is no receipt number to quote, but you still have the reference you sent the request with. **Never retry instead.** `$transactionDate` is optional, because a reference is not scoped to a day; do not fill in "today". **Throws** `InvalidRequestException` if `$originalReference` is blank or malformed. The answer is passed to the guard, which settles the journal entry where it can. |
| `receipt()` | `EcrReceipt` | Fetches an earlier transaction's e-receipt URL, for a QR code. Non-financial and repeatable. |
| `closeReceipt()` | `EcrReceiptClosed` | Asks the terminal to put its receipt away and return to its idle screen. Call it from the till's Close receipt action when the cashier is finished, not as a side effect of dismissing a dialog. Firmware too old to know this command answers `CloseReceiptRefused`. |
| `settlement()` | `EcrSettlement` | Sends `SETTLEMENT`: closes the current batch and prints the settlement receipt. The terminal starts as soon as it arrives, so confirm with the cashier first. Uses the full response timeout, because a close can wait on pending reversals, and raises PHP's time limit for the wait. Safe to repeat when the answer is lost: a batch that closed answers `NothingToSettle` the next time, and one that did not settles on the retry. Never followed by an automatic inquiry or resend. |
| `run()` | `EcrResult` | Runs any money-moving operation. The typed methods above are the usual way in; this one is for a till that sets the type from its own form. **Throws** `InvalidRequestException` (nothing was sent) if the type does not move money, an amount is missing, or an argument is malformed. The request is built and signed before the guard records anything. |
| `formatDuration()` | `string` | Formats seconds as `45s`, `2m` or `1m 30s`. |

Arguments follow the Windows SDK. A blank receipt number throws `InvalidRequestException` for a void, refund, inquiry or receipt; otherwise every character that is not a digit is dropped and the rest is padded to six (`24` → `000024`). Dates and the original terminal ID are sent as given when not blank; a refund without a date is sent without one. Amounts are rounded half away from zero and not judged: zero and negative amounts are sent. A merchant reference is at most 32 printable ASCII characters, with no spaces, `&` or `=`; text that is not a number is the only amount refused.

When a Wi-Fi or USB sale, void or refund loses its answer with an unknown outcome and `autoInquireOnFailure` is on, the SDK asks the terminal once, on a fresh connection, what became of the transaction. An inquiry only reads, so this follow-up cannot itself move money. The finding is attached to `Failed::$recovered`; it does not replace the failure, because the exchange still failed.

### `EcrWebServiceTerminal`

The ECR Web Service client: REST over HTTPS to the Amwal Hub, with signed JSON bodies. Use it when the terminal is configured for Web Service mode (`ecrMode: 4`).

A lost or unreadable answer arrives as `Failed` with an outcome-unknown failure, and is **never** followed by an automatic inquiry (`Failed::$recovered` is always `null`). Inquire by reference yourself.

```php
final class EcrWebServiceTerminal
{
    public function __construct(
        string $terminalSerial,
        EcrConfig $config = new EcrConfig(),
        ?EcrLogger $logger = null,
        ?HttpTransport $http = null,
        ?FinancialGuard $guard = null,
    );

    public function terminalKey(): string;
    public function guard(): FinancialGuard;
    public function config(): EcrConfig;

    public function sale(string|int|Amount $amount, string $merchantReference = ''): EcrResult;
    public function void(string $receiptNumber, string $merchantReference = ''): EcrResult;
    public function refund(string|int|Amount $amount, string $receiptNumber, string $transactionDate, string $merchantReference = ''): EcrResult;
    public function inquire(string $receiptNumber, string $transactionDate, string $merchantReference = ''): EcrInquiry;
    public function inquireByReference(string $originalReference, string $transactionDate = '', string $merchantReference = ''): EcrInquiry;
    public function closeReceipt(string $originalMerchantReference, string $merchantReference = ''): EcrReceiptClosed;
    public function settlement(string $merchantReference = ''): EcrSettlement;
    public function probeReachability(): EcrReachability;

    public static function id(string $text): ?int;
}
```

Constructor: the config is used as given, so the HTTP deadline is `connectTimeout + responseTimeout` (130 s with the defaults). `EcrSessions::plan()` raises an untouched 120 s answer wait to 130 s (140 s deadline), as the Windows SDK. `$http` sets how the HTTPS post is made; `null` uses cURL. The URL is always the SDK's. `$logger` defaults to `EcrLogger::none()`, and `$guard` to `FinancialGuard::fromConfig()`.

| Method | Returns | Notes |
|---|---|---|
| `terminalKey()` | `string` | Identifies this terminal to the guard (`ws\|environment\|merchantId\|terminalId`). |
| `sale()` | `EcrResult` | `$amount` is in major units: a string, an int or a float. |
| `void()` | `EcrResult` | By receipt number. |
| `refund()` | `EcrResult` | `$transactionDate` is the original's day as `yyyyMMdd`. |
| `inquire()` | `EcrInquiry` | By receipt number and date. |
| `inquireByReference()` | `EcrInquiry` | Looks up the transaction sent as `$originalReference`; use it for an outcome you never received. `$transactionDate` is optional; do not fill in "today", because the Hub filters by that day. Throws `InvalidRequestException` if `$originalReference` is blank or malformed. |
| `closeReceipt()` | `EcrReceiptClosed` | Posts `/Ecr/closeReceipt` for the sale, void or refund sent as `$originalMerchantReference`. Call it from the Close receipt action; it is never sent automatically. `$merchantReference` is this close request's own ID; when blank, one is generated. A blank original returns `CloseReceiptFailed` with `Malformed`. |
| `settlement()` | `EcrSettlement` | Posts `/Ecr/settle` with the identity body (no `messageType`). Confirm with the cashier first: the Hub starts the close as soon as the request arrives. The answer is read the same way as a Wi-Fi `SETTLEMENT`. A Hub that never answered (timeout, network error, empty body) is `SettlementFailed` with `ConnectionLost`, not `NotSettled`. |
| `probeReachability()` | `EcrReachability` | Posts `/Ecr/CheckReachability` with the identity body and `probeTimeout` as the whole deadline, so a Hub that does not answer does not hold the till for a sale's timeout. `reachable` is true when the Hub answers with a success envelope. `host` and `port` are the Hub's, `endpoint` is its origin (`https://test.amwalpg.com:25453`). `EcrOpenedSession::checkReachability()` calls this. |
| `id()` | `?int` | The ID as `long.TryParse` reads it (white space and a sign allowed), or `null`. |

If `merchantId` or `terminalId` on the config is not numeric, money-moving calls return `Failed` (with an empty merchant reference) and `Failure\Malformed`, inquiries return `InquiryFailed` with `Malformed`, close receipt returns `CloseReceiptFailed` with `Malformed`, settlement returns `SettlementFailed` with `Malformed`, and the reachability check returns `reachable: false`, as the Windows SDK. Nothing is sent in any of these cases. Receipt numbers and dates are sent as given (receipt numbers padded); a malformed reference or an amount that is not a number throws `InvalidRequestException`. If a Hub transport failure comes back to an inquiry as an answer with no data, it is reported as `InquiryFailed` (`ConnectionLost`), never as `NotFound`.

### `EcrReachability`

Whether the terminal's ECR port (Wi-Fi), its COM port (USB cable) or the Hub (Web Service `CheckReachability`) answers. It proves the link is up, not that the terminal is idle: a terminal already taking a payment answers too. A USB cable reports its COM port as `host` and a port of 0.

```php
final class EcrReachability
{
    public readonly string $endpoint;

    public function __construct(
        public readonly bool $reachable,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $error = null,
        ?string $endpoint = null,
    );

    public function toArray(): array; // {reachable, host, port, error, endpoint}
}
```

- `$error`: the reason when the terminal is not reachable.
- `$endpoint`: defaults to `host:port`, or `host` when the port is 0.

---

## Results

Every operation returns a result object instead of throwing. Each family has an abstract base with a public `string $merchantReference`, `outcome(): string` and `toArray(): array`. Tell the subclasses apart with `instanceof`.

### EcrResult family

How a sale, void or refund ended. The three outcomes need different handling from a till. `Approved` means money moved. `Declined` means the terminal answered and refused. `Failed` means the exchange itself failed: it may still have taken the customer's money, so **never treat it as a refusal**.

```php
namespace AmwalPay\Ecr\Result;

abstract class EcrResult
{
    public function __construct(public readonly string $merchantReference);
    public function isApproved(): bool;
    public function isDeclined(): bool;
    public function isFailed(): bool;
    abstract public function outcome(): string;   // 'approved' | 'declined' | 'failed'
    abstract public function toArray(): array;
}

final class Approved extends EcrResult
{
    public function __construct(
        string $merchantReference,
        public readonly string $amount,
        public readonly string $responseCode,
        public readonly string $rrn,
        public readonly string $authCode,
        public readonly string $maskedPan,
        public readonly bool $partialApproval,
        public readonly string $requestedAmount,
        public readonly string $raw,
    );
}

final class Declined extends EcrResult
{
    public function __construct(
        string $merchantReference,
        public readonly string $responseCode,
        public readonly string $reason,
        public readonly NextStep $nextStep,
        public readonly string $raw,
    );
}

final class Failed extends EcrResult
{
    public function __construct(
        string $merchantReference,
        public readonly Failure $failure,
        public readonly ?EcrInquiry $recovered = null,
    );
    public function nextStep(): NextStep;
    public function settled(): bool;
    public function withRecovered(?EcrInquiry $recovered): self;
}
```

- `EcrResult::$merchantReference`: the reference the transaction was sent with, either the caller's own or one the SDK generated. It identifies this transaction to the terminal afterwards.
- **`Approved`**: money moved.
  - `$amount`: what was actually taken or returned, in major units, e.g. `1.234`.
  - `$responseCode`: the backend's code, `00` on a plain approval.
  - `$maskedPan`: masked, never the full number, e.g. `543173xxxx5785`. May be empty.
  - `$partialApproval`: the issuer authorised less than was asked for. Collect the difference by other means before handing over the goods. This is not a refusal.
  - `$requestedAmount`: what was asked for, when partially approved.
  - `$raw`: the terminal's full answer as JSON text, for anything not exposed as a property.
- **`Declined`**: the terminal answered and refused. **No money moved.** `$reason` is the text to show: the backend's error list in preference to its message. An answer that says the outcome is unknown (`nextStep` `INQUIRE_BY_MERCHANT_REFERENCE`) never arrives as a decline; it arrives as `Failed`. So `$nextStep` is always `NextStep::None` and is kept only for parity with the Windows SDK.
- **`Failed`**: the exchange itself failed. When `$failure->outcomeUnknown()` is `true`, the payment may have completed. Read `$recovered` first (on Wi-Fi the SDK asks the terminal once by itself), then inquire by `$merchantReference`. **Never resend.**
  - `$recovered`: what the terminal said when asked afterwards, or `null` if it was not asked. `Found` settles the outcome; anything else leaves it unknown. Always `null` on Web Service.
  - `nextStep()`: always `NextStep::InquireByMerchantReference`, because nothing here says the transaction did not happen.
  - `settled()`: whether the follow-up inquiry found the transaction (`$recovered instanceof Found`), so the outcome is no longer unknown.
  - `withRecovered()`: a copy with `$recovered` set.

```php
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;

$reference = EcrSessions::newMerchantReference();
saveOrderReference($orderId, $reference);          // save before sending

$result = $session->sale('1.234', $reference);

if ($result instanceof Approved) {
    recordPayment($orderId, $result->amount, $result->rrn);
    if ($result->partialApproval) {
        collectDifference($orderId, $result->requestedAmount, $result->amount);
    }
} elseif ($result instanceof Declined) {
    show($result->reason);                         // no money moved
} elseif ($result instanceof Failed) {
    if ($result->settled()) {
        applyTransaction($orderId, $result->recovered->transaction);
    } elseif ($result->failure->outcomeUnknown()) {
        markUnresolved($orderId, $result->merchantReference); // inquire later, never resend
    } else {
        show($result->failure->message);           // nothing was sent
    }
}
```

### EcrInquiry family

The answer to "what became of this transaction": `Found`, `NotFound` or `InquiryFailed`. It is kept separate from `EcrResult` because it reports on a transaction instead of performing one. A successful inquiry says nothing by itself about whether money moved; `EcrTransaction::$status` says that.

`InquiryFailed` corresponds to .NET `EcrInquiry.Failed`.

```php
abstract class EcrInquiry
{
    public function __construct(public readonly string $merchantReference);
    abstract public function outcome(): string;   // 'found' | 'not_found' | 'failed'
    abstract public function toArray(): array;
}

final class Found extends EcrInquiry
{
    public function __construct(string $merchantReference, public readonly EcrTransaction $transaction, public readonly string $raw);
}

final class NotFound extends EcrInquiry
{
    public function __construct(string $merchantReference, public readonly string $reason, public readonly string $raw);
}

final class InquiryFailed extends EcrInquiry
{
    public function __construct(string $merchantReference, public readonly Failure $failure);
}
```

- `EcrInquiry::$merchantReference`: the reference the inquiry was sent with, or the answer's own.
- **`Found`**: the transaction was found. `$transaction->status` says what became of it.
- **`NotFound`**: this lookup returned no record. After a lost answer, that is **not proof nothing was charged**: the same answer follows a terminal reset or a record that has aged out. Confirm on the terminal, or with Amwal, before taking payment again.
- **`InquiryFailed`**: the lookup itself failed, so nothing was learned. Harmless to repeat, because an inquiry moves no money.

### EcrReceipt family

A transaction's e-receipt: `ReceiptReady`, `ReceiptUnavailable` or `ReceiptFailed`. The URL is shown as a QR code, so a till with no printer can still hand over a receipt. Wi-Fi only.

`ReceiptReady` corresponds to .NET `EcrReceipt.Ready`.

```php
abstract class EcrReceipt
{
    public function __construct(public readonly string $merchantReference);
    abstract public function outcome(): string;   // 'ready' | 'unavailable' | 'failed'
    abstract public function toArray(): array;
}

final class ReceiptReady extends EcrReceipt
{
    public function __construct(string $merchantReference, public readonly string $url, public readonly string $raw);
}

final class ReceiptUnavailable extends EcrReceipt
{
    public function __construct(string $merchantReference, public readonly string $reason, public readonly string $raw);
}

final class ReceiptFailed extends EcrReceipt
{
    public function __construct(string $merchantReference, public readonly Failure $failure);
}
```

- **`ReceiptReady`**: the receipt is published. Put `$url` in a QR code exactly as it is.
- **`ReceiptUnavailable`**: no receipt to show: not found, not published, or not offered on this transport. Asking again is safe.
- **`ReceiptFailed`**: the exchange failed. Nothing changed, and the call can be repeated.

### EcrReceiptClosed family

The answer to a request to put the terminal's receipt away: `ReceiptClosed`, `CloseReceiptRefused` or `CloseReceiptFailed`. Success means the terminal is idle again, whether or not it had a receipt up. Safe to repeat.

`ReceiptClosed` corresponds to .NET `EcrReceiptClosed.Idle`; `CloseReceiptRefused` corresponds to .NET `EcrReceiptClosed.Refused`.

```php
abstract class EcrReceiptClosed
{
    public function __construct(public readonly string $merchantReference);
    abstract public function outcome(): string;   // 'idle' | 'refused' | 'failed'
    abstract public function toArray(): array;
}

final class ReceiptClosed extends EcrReceiptClosed
{
    public function __construct(string $merchantReference, public readonly string $raw);
}

final class CloseReceiptRefused extends EcrReceiptClosed
{
    public function __construct(string $merchantReference, public readonly string $responseCode, public readonly string $reason, public readonly string $raw);
}

final class CloseReceiptFailed extends EcrReceiptClosed
{
    public function __construct(string $merchantReference, public readonly Failure $failure);
}
```

- **`ReceiptClosed`**: the terminal is on its idle screen.
- **`CloseReceiptRefused`**: the terminal answered and refused, e.g. its firmware is too old for `CLOSE_RECEIPT`. Treat it as "cannot be asked" and carry on; the receipt stays up.
- **`CloseReceiptFailed`**: the exchange failed. Nothing changed, and the call can be repeated.

### EcrSettlement family

What a settlement came back as: `Settled`, `NothingToSettle`, `NotSettled` or `SettlementFailed`. The till already confirmed with the cashier; this is only the terminal's answer. The terminal's `data.settlementStatus` decides the branch, not the envelope alone: an empty batch is a success with nothing in it, and a refusal before the settlement started (busy, not idle, firmware too old) carries no status at all.

`Settled`, `NothingToSettle` and `NotSettled` correspond to the .NET records of the same names; `SettlementFailed` corresponds to .NET `EcrSettlement.Failed`.

```php
namespace AmwalPay\Ecr\Result;

abstract class EcrSettlement
{
    public function __construct(public readonly string $merchantReference);
    abstract public function outcome(): string;   // 'settled' | 'nothing_to_settle' | 'not_settled' | 'failed'
    abstract public function toArray(): array;
}

final class Settled extends EcrSettlement
{
    public function __construct(
        string $merchantReference,
        public readonly string $batchId,
        public readonly string $currency,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly int $totalTransactions,
        public readonly string $netAmount,
        public readonly array $totals,             // array<string, EcrSettlementTotal>
        public readonly string $raw,
    );
}

final class NothingToSettle extends EcrSettlement
{
    public function __construct(string $merchantReference, public readonly string $message, public readonly string $raw);
}

final class NotSettled extends EcrSettlement
{
    public function __construct(
        string $merchantReference,
        public readonly string $responseCode,
        public readonly string $status,
        public readonly string $reason,
        public readonly ?int $uploadedTransactions,
        public readonly string $raw,
    );
}

final class SettlementFailed extends EcrSettlement
{
    public function __construct(string $merchantReference, public readonly Failure $failure);
}

final class EcrSettlementTotal
{
    public function __construct(public readonly int $count, public readonly string $amount);
    public function toArray(): array;   // {count, amount}
}
```

| Result | When | Fields |
|---|---|---|
| **`Settled`** | `success` and `settlementStatus` `SETTLED`. The batch closed. | Amounts are major units (`netAmount` `000000012500` with 3 digits reads `12.500`). `totals` always has `SALE`, `REFUND`, `VOID`, `PRE_AUTH`, `CAPTURE`, in that order, with `EcrSettlementTotal(0, '0.000')` where the terminal omitted a row. `totalTransactions` and counts are read as `int.TryParse` reads them; unreadable counts are 0. |
| **`NothingToSettle`** | `success` and `settlementStatus` `NO_TRANSACTIONS`. | `message`: the terminal's text, or "There are no transactions to settle". |
| **`NotSettled`** | Anything else that answered: `success: false`, no `data`, or another status (e.g. `MISMATCH`). The batch is still open. | `responseCode`, `status` (empty when the terminal refused before starting), `reason` (`errorList` first, then `message`, else "The batch was not settled"), `uploadedTransactions` (`null` when absent). |
| **`SettlementFailed`** | The exchange failed: timeout, lost connection or cable, unreadable or unauthenticated answer, a Hub that never answered, or missing Web Service IDs (`Malformed`, nothing sent). | `failure`. The batch may or may not have closed; repeating the call is safe. |

### `EcrTransaction`

A transaction as the terminal's backend records it. Found in `Found::$transaction`.

```php
final class EcrTransaction
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $stan,
        public readonly string $type,
        public readonly string $status,
        public readonly string $amount,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly string $transactionTime,
        public readonly string $maskedPan,
        public readonly string $cardHolderName,
        public readonly string $rrn,
        public readonly string $authCode,
        public readonly string $batchId,
        public readonly string $terminalId,
        public readonly bool $isRefunded,
        public readonly bool $canVoid,
        public readonly bool $canRefund,
        public readonly bool $partialApproval = false,
        public readonly string $authorizedAmount = '',
    );

    public function isApproved(): bool;
    public function isDeclined(): bool;
    public function day(): string;
    public function toArray(): array;
}
```

| Property | Meaning |
|---|---|
| `transactionId` | The backend's identifier, unique across terminals and days. |
| `stan` | The receipt number, unique only within one terminal's day. |
| `type` | `Purchase`, `Void`, `Refund`, as the backend names it. |
| `status` | What became of it: `Approved`, `Declined`, `Voided`... Compare ignoring case. |
| `amount` | In major units: the amount that was requested. |
| `totalAmount` | Including tips and fees, where there were any. |
| `transactionTime` | As recorded, e.g. `2026-08-09T12:41:07`. |
| `maskedPan` | Masked, never the full number. May be empty. |
| `canVoid` | Whether the terminal would accept a void for it now. |
| `canRefund` | Whether the terminal would accept a refund against it. |
| `partialApproval` | The issuer approved less than was asked for; `authorizedAmount` is what was charged. |
| `authorizedAmount` | What was actually authorised. Equal to the amount unless partially approved. |

- `isApproved()` / `isDeclined()`: whether `status` reads `Approved` / `Declined`, ignoring case.
- `day()`: the day of `transactionTime` as `yyyyMMdd`, or `""` if it has none.
- `toArray()`: all properties.

### `NextStep`

What the till should do about an outcome it cannot act on directly. The SDK states this explicitly instead of leaving the till to infer it from a response code, because the wrong inference is expensive: reading an unknown outcome as a refusal and sending the sale again charges the cardholder twice.

```php
enum NextStep: string
{
    case None = 'NONE';
    case InquireByMerchantReference = 'INQUIRE_BY_MERCHANT_REFERENCE';

    public static function fromWire(string $value): self;
}
```

- `None`: the answer settles the matter.
- `InquireByMerchantReference`: ask what became of the transaction, quoting the reference it was sent with. **Never retry instead.**
- `fromWire()`: reads the wire value. Unknown values read as `None`, because a till must not act on a step it does not understand.

---

## Failures

Why an exchange could not be completed. Found on `Failed::$failure`, `InquiryFailed::$failure`, `ReceiptFailed::$failure` and `CloseReceiptFailed::$failure`.

The one distinction that matters after a failure is `outcomeUnknown()`. When it is `false`, nothing reached the terminal, so there is nothing to reconcile. When it is `true`, the request went out and the terminal may have acted on it, whatever came back or did not: **inquire by merchant reference, never resend.**

```php
namespace AmwalPay\Ecr\Failure;

abstract class Failure
{
    final public function __construct(public readonly string $message);
    public function outcomeUnknown(): bool;      // true unless a subclass says otherwise
    abstract public function kind(): string;
    public function toArray(): array;            // {kind, message, outcomeUnknown}
}
```

| Class | `kind()` | `outcomeUnknown()` | Meaning |
|---|---|---|---|
| `Timeout` | `timeout` | `true` | The request went out and no answer came in time. The transaction may still have completed: inquire before retrying. |
| `ConnectionLost` | `connection_lost` | `true` | The connection broke after the request went out. The transaction may still have completed: inquire before retrying. |
| `Malformed` | `malformed` | `true` | The answer could not be read. The transaction may still have completed: inquire before retrying. |
| `Unauthenticated` | `unauthenticated` | `true` | The answer could not be shown to come from the terminal: it was not signed with this till's key, or it answers a different request. It is discarded, not believed. The terminal may have taken the payment: inquire before retrying. If this happens repeatedly, the key on the till and the key on the terminal differ. |
| `Unreachable` | `unreachable` | `false` | Nothing is listening: wrong address, terminal off, or a different network. Nothing was sent. |
| `Busy` | `busy` | `false` | Another sale, void or refund is already running on this terminal, from this process or another. Nothing was sent. |
| `Invalid` | `invalid` | `false` | No longer produced: the SDK reports these cases as `Malformed`, as the Windows SDK. Kept so stored results still read. |
| `Unresolved` | `unresolved` | `false` | An earlier sale, void or refund on this terminal has an unknown outcome and must be reconciled by inquiry before a new one is sent. Nothing was sent. |

`Busy`, `Invalid` and `Unresolved` exist only in the PHP SDK. `Busy` and `Unresolved` come only from the opt-in financial guard.

---

## Exceptions

Exceptions are thrown only for calls that are refused before anything is sent.

```php
namespace AmwalPay\Ecr\Exception;

class InvalidRequestException extends \InvalidArgumentException {}

final class DuplicateMerchantReferenceException extends InvalidRequestException
{
    public function __construct(public readonly JournalEntry $previous);
}
```

- **`InvalidRequestException`**: the call was refused before anything was built or sent. Causes include a merchant reference the wire cannot carry, an amount that is not a positive figure in the currency's precision, and a receipt number or date in the wrong format. Nothing reached the terminal, so there is no outcome to reconcile. The Windows SDK throws `ArgumentException` for the same cases.
- **`DuplicateMerchantReferenceException`**: a sale, void or refund was requested with a merchant reference this terminal has already been sent. Reusing a reference is how a till resends a payment it is unsure about, and resending is how a cardholder is charged twice. If the earlier attempt's outcome is unknown, inquire by this reference. If it was declined, start the new attempt with a new reference. `$previous` is the journal entry for the earlier attempt. The message names its type and state, and adds either "inquire by this reference, never resend" (outcome unknown) or "A new attempt needs a new reference".

---

## Safety

Payment-safety rules for a PHP till that serves many requests at once. They are off by default, as the Windows SDK has no such guard. Turn them on with `EcrConfig(guardFinancialOperations: true)` when your own system does not already enforce them.

### `FinancialGuard`

1. **One financial transaction per terminal at a time.** A second sale, void or refund while one is running, from any PHP process on the machine, gets `Busy` and nothing is sent.
2. **A merchant reference is used once.** Sending a reference this terminal has already been sent throws `DuplicateMerchantReferenceException`, because that is how a till resends a payment it is unsure about.
3. **No new money movement while an outcome is unknown.** After a timeout, a lost connection, or an unreadable or unauthenticated answer, the reference is kept as unresolved. The next sale, void or refund on that terminal gets `Unresolved` until an inquiry by reference settles it, or an operator settles it with `markResolved()`.
4. **The reference is saved before the request leaves**, so a PHP process killed mid-wait leaves an unresolved entry, not a forgotten payment.

Inquiries, receipts, close receipt, settlement and reachability checks are never blocked. The Windows SDK leaves these rules to the till (its web sample enforces rule 1); the PHP SDK enforces them when `guardFinancialOperations` is on.

```php
namespace AmwalPay\Ecr\Safety;

final class FinancialGuard
{
    public function __construct(
        TransactionJournal $journal,
        TerminalLock $lock,
        bool $enabled = true,
        bool $extendTimeLimit = true,
        ?EcrLogger $logger = null,
    );

    public static function fromConfig(EcrConfig $config, ?EcrLogger $logger = null): self;
    public function enabled(): bool;
    public function run(string $terminalKey, EcrTransactionType $type, string $reference, string $amountMinorUnits, string $currencyCode, float $budgetSeconds, callable $send): EcrResult;
    public function protected(float $budgetSeconds, callable $send): mixed;
    public function observeInquiry(string $terminalKey, string $originalReference, EcrInquiry $inquiry): void;
    public function unresolved(string $terminalKey): array;   // list<JournalEntry>
    public function entry(string $terminalKey, string $reference): ?JournalEntry;
    public function markResolved(string $terminalKey, string $reference, string $note): JournalEntry;
}
```

| Method | Returns | Notes |
|---|---|---|
| `fromConfig()` | `FinancialGuard` | Uses `FileTransactionJournal` and `FileTerminalLock` in `$config->stateDirectory`, or in an `amwalpay-ecr` folder under the system temp directory if that is `null`. Takes `enabled` and `extendTimeLimit` from the config. |
| `enabled()` | `bool` | Whether the rules are enforced. |
| `run()` | `EcrResult` | Runs one sale, void or refund under the rules above. `$terminalKey` identifies the physical terminal (see the terminals' `terminalKey()`). `$reference` is the resolved merchant reference the request will carry. `$budgetSeconds` is the longest the call can take, used for PHP's time limit. `$send` (`callable(): EcrResult`) builds and sends the request. Returns `Failed` with `Busy` or `Unresolved` without calling `$send`. **Throws** `DuplicateMerchantReferenceException`. While `$send` runs, user abort is ignored and, if `extendTimeLimit` is on, the time limit is raised. Approved and declined results are recorded. A failure where nothing was sent removes the entry, so the reference may be used again. A failure with an unknown outcome leaves the entry unresolved. |
| `protected()` | whatever `$send` returns | Runs `$send` with user abort ignored and, if `extendTimeLimit` is on, PHP's time limit raised to `$budgetSeconds` + 30 s. Applies whether or not the guard is enabled. The terminals use it for settlement, which can wait as long as a sale. |
| `observeInquiry()` | `void` | Lets an inquiry by reference settle an unresolved entry. A record whose status is Approved or Declined settles it. A record with any other status stays unknown, with a note to confirm by hand. Not found settles nothing, because after a lost answer it does not prove nothing was charged. Does nothing when the guard is disabled. |
| `unresolved()` | `list<JournalEntry>` | Entries whose outcome is unknown, oldest first. Always empty when the guard is disabled. |
| `entry()` | `?JournalEntry` | One entry by reference. Always `null` when the guard is disabled. |
| `markResolved()` | `JournalEntry` | Settles an unresolved entry by hand, after the outcome was confirmed on the terminal or with Amwal. `$note` should say how it was confirmed. An entry that is already settled is returned unchanged. **Throws** `\InvalidArgumentException` if no entry exists for the reference, or if `$note` is blank. |

Journal and lock failures (state folder cannot be created, journal unreadable or corrupt, lock cannot be opened) surface as `\RuntimeException` from the file implementations below.

### `JournalEntry`

One sale, void or refund the guard sent, as the journal remembers it. It holds the reference, type, amount and outcome, and never card data or keys.

```php
final class JournalEntry
{
    public const PENDING = 'pending';
    public const UNKNOWN = 'unknown';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const RESOLVED = 'resolved';

    public function __construct(
        public readonly string $reference,
        public readonly string $type,
        public readonly string $state,
        public readonly string $amountMinorUnits,
        public readonly string $currencyCode,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $status = '',
        public readonly string $failure = '',
        public readonly string $note = '',
    );

    public function isUnresolved(): bool;
    public function with(?string $state = null, ?string $status = null, ?string $failure = null, ?string $note = null): self;
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

| State constant | Value | Meaning |
|---|---|---|
| `PENDING` | `pending` | Recorded before the request left; the answer has not been read yet. |
| `UNKNOWN` | `unknown` | The request went out and the answer did not settle it. Inquire by reference. |
| `APPROVED` | `approved` | Approved. |
| `DECLINED` | `declined` | Declined. |
| `RESOLVED` | `resolved` | Settled by hand with `FinancialGuard::markResolved()` after confirming on the terminal or with Amwal. |

- `isUnresolved()`: `true` for `PENDING` or `UNKNOWN`, meaning the outcome is still unknown and the terminal must be asked before any new money moves.
- `with()`: a copy with the given fields changed and `updatedAt` set to the current UTC time.
- `toArray()` / `fromArray()`: plain-array form for storage. `fromArray()` reads missing or non-scalar fields as `''`.

### `TransactionJournal`

Remembers every merchant reference sent to a terminal and what became of it, across requests and processes. Implement it over your database to share the guard between servers. `FileTransactionJournal` is the default.

```php
interface TransactionJournal
{
    public function find(string $terminalKey, string $reference): ?JournalEntry;
    public function unresolved(string $terminalKey): array;   // list<JournalEntry>, oldest first
    public function put(string $terminalKey, JournalEntry $entry): void;
    public function remove(string $terminalKey, string $reference): void;
}
```

- `unresolved()`: entries whose outcome is still unknown, oldest first.
- `put()`: inserts or replaces the entry for `$entry->reference`.

### `FileTransactionJournal`

The journal as one JSON file per terminal, in a private folder. Every change is a read-modify-write under an exclusive lock, written to a temporary file and renamed into place, so two PHP processes never interleave their writes and a crash never leaves half a file. Settled entries older than the retention period are dropped. Unresolved entries are kept until they are settled.

```php
final class FileTransactionJournal implements TransactionJournal
{
    public function __construct(string $directory, int $retentionDays = 90);
}
```

- **Throws** `\RuntimeException` when the folder cannot be created, or the journal cannot be read, locked or saved. A journal file that cannot be parsed also throws ("corrupt and must be checked by hand"). This fails closed, because a journal that cannot be read cannot prove a reference is unused.

### `TerminalLock`

One financial transaction per terminal at a time, across every PHP process on the machine. Implement it over a shared lock service (Redis, a database row) when several servers drive the same terminal.

```php
interface TerminalLock
{
    public function tryAcquire(string $terminalKey): ?callable;
}
```

- `tryAcquire()`: takes the terminal without waiting. Returns the release function (`callable(): void`), or `null` if another caller holds the terminal.

### `FileTerminalLock`

A terminal lock implemented as an exclusive `flock` on a file. The operating system releases it if the process dies, so a crashed request never leaves a terminal locked. Works on Windows (LockFileEx) and Unix.

```php
final class FileTerminalLock implements TerminalLock
{
    public function __construct(string $directory);
}
```

- **Throws** `\RuntimeException` if the folder cannot be created or the lock file cannot be opened. The release function is safe to call more than once.

---

## Logging

Where the SDK writes its diagnostics. Logging is off until you pass a logger. Lines carry request and response payloads: masked card number, amount and reference. The signing secret is never written, and card numbers and sensitive fields are masked by `Redactor` before a line reaches the logger. Treat the lines as transaction records.

```php
namespace AmwalPay\Ecr\Log;

abstract class EcrLogger
{
    abstract protected function write(string $line): void;
    final public function debug(string $message): void;
    public static function none(): self;
    public static function from(callable $sink): self;
    public static function psr(object $logger): self;
}

final class CallableEcrLogger extends EcrLogger { public function __construct(callable $sink); }
final class NullEcrLogger extends EcrLogger {}
final class PsrLoggerAdapter extends EcrLogger { public function __construct(object $logger); }

final class Redactor
{
    public static function line(string $line): string;
    public static function mask(string $pan): string;
    public static function luhn(string $digits): bool;
}
```

| Member | Notes |
|---|---|
| `EcrLogger::debug()` | Writes one diagnostic line, redacted. To write your own logger, extend `EcrLogger` and implement `write()`. |
| `EcrLogger::none()` | Discards everything. This is the default. |
| `EcrLogger::from($sink)` | Wraps a callable that takes one line, e.g. `EcrLogger::from(fn (string $line) => error_log($line))`. An exception thrown by the callable escapes the SDK call. |
| `EcrLogger::psr($logger)` | Wraps a PSR-3 logger (anything with a `debug(string, array)` method). |
| `CallableEcrLogger` | Hands each redacted line to a callable. |
| `NullEcrLogger` | Discards every line. |
| `PsrLoggerAdapter` | Sends each redacted line to a PSR-3 logger at debug level, with context `['channel' => 'amwalpay-ecr']`, without making `psr/log` a dependency. Throws `\InvalidArgumentException` if the object has no `debug` method. |
| `Redactor::line()` | Masks what must never reach a log; applied to every line the SDK writes. A run of 13 to 19 digits that passes the Luhn check is masked to its first six and last four digits. The values of fields named like a PIN, CVV, track data, token, password or secret are replaced with `***`. This is the second line of defence, for the day a terminal, a Hub or a caller's own text carries something it should not. |
| `Redactor::mask()` | Keeps the first six and last four digits and replaces the rest with `*`. |
| `Redactor::luhn()` | Whether a digit string passes the Luhn check. |

---

## Channel

How bytes reach a Wi-Fi or USB cable terminal, and nothing else. Signing a request, checking the answer and deciding what a response code means all belong to `EcrTerminal`, and work the same however the bytes travel. You need this layer to open a USB cable session (`SerialEcrChannel`), or to supply your own transport, e.g. for tests.

### `EcrChannel`

A channel carries one framed request and brings back one framed answer.

```php
namespace AmwalPay\Ecr\Channel;

interface EcrChannel
{
    public function endpoint(): string;
    public function host(): string;
    public function port(): int;
    public function probe(float $timeout): ?string;
    public function exchange(string $body, float $timeout): string;
}
```

- `endpoint()`: what to show an operator when this link fails, e.g. `192.168.1.50:9100`.
- `port()`: zero when the link is not a socket.
- `probe()`: whether the terminal can be reached, without sending anything. Returns `null` if reachable, otherwise the reason.
- `exchange()`: sends one request body and returns the answer body, both UTF-8 JSON without the length header. Applying the framing is the channel's job. **Throws** `EcrChannelTimeout` if the terminal accepted the request and did not answer within `$timeout` seconds, and `EcrIOException` for anything else that broke the link.

### `TcpEcrChannel`

The terminal over TCP, on the shop's Wi-Fi or LAN. One connection carries one request and one answer, which is how POS ECR ports behave: connect, send, wait for the cardholder, read the result, disconnect. The connect budget and the answer budget are separate. Connecting is a handshake on the local network: it either happens quickly or the address is wrong. Because nothing has been sent at that point, a connect failure counts as "unreachable". The long wait belongs to the answer, which includes the cardholder presenting a card.

```php
final class TcpEcrChannel implements EcrChannel
{
    public const DEFAULT_CONNECT_TIMEOUT = 3.0;

    public function __construct(string $host, int $port = EcrConfig::DEFAULT_PORT, ?float $connectTimeout = null);
}
```

- `DEFAULT_CONNECT_TIMEOUT`: used when `$connectTimeout` is `null`. Prefer passing `EcrConfig::$connectTimeout`, as `EcrTerminal` does.
- **Throws** `\InvalidArgumentException` if `$host` is empty.
- A request too large for a frame fails locally before connecting, so it is never reported as a dropped connection.

### `SerialEcrChannel`

The terminal on the USB virtual serial port the operating system assigned to the cable: 115200 baud, 8 data bits, no parity, 1 stop bit, DTR and RTS on, no handshake, which is what "MediaTek USB VCOM (Android)" enumerates as. The bytes are the same framed messages as Wi-Fi. The port is opened for each exchange and closed after it.

```php
final class SerialEcrChannel implements EcrChannel
{
    public const BAUD_RATE = 115200;

    public function __construct(
        string $portName,                       // 'COM8', '/dev/ttyACM0'
        ?\Closure $open = null,                 // Closure(string $port): SerialLine; null: SerialLines::open()
        ?\Closure $presentNames = null,         // Closure(): list<string>; null: SerialPorts::presentNames()
    );
}
```

- `endpoint()` / `host()`: the port name, or `USB cable` when it is blank. `port()`: always 0.
- `probe()`: never opens the port (opening and closing drops DTR, which the terminal treats as a hang-up). A blank port answers "Windows has not assigned a COM port to this terminal. Plug it in. The COM number follows the device serial and can change." On Windows, a port the system does not list answers "Windows is not listing COM8. Plug the terminal in. …". Otherwise `null`.
- `exchange()`: frames the body, opens the port, discards stale bytes left from an earlier exchange, writes the request and reads frames until one carries this request's nonce (a frame answering an earlier request is skipped, not believed). Throws `EcrChannelTimeout` after `$timeout` seconds. A failure to open, set up or drain the port before writing is an `EcrIOException` that reads as `Unreachable` (nothing was sent); a port that fails after the write reads as `ConnectionLost` (outcome unknown).
- `$open` and `$presentNames` exist so a test can stand in for the port.

### `EcrFrames`

The wire framing: a 2-byte big-endian length followed by that many bytes of UTF-8 JSON. A TCP stream carries no message boundaries, so the header is what says where one message ends.

```php
final class EcrFrames
{
    public const HEADER_BYTES = 2;
    public const MAX_BODY_BYTES = 0xFFFF;

    public static function wrap(string $body): string;
    public static function bodyLength(string $header): int;
    public static function readBody(callable $readExactly): string;
    public static function closed(string $what): EcrIOException;
    public static function readBodyFrom(string $bytes, int &$offset = 0): string;
}
```

| Method | Notes |
|---|---|
| `wrap()` | Puts a length header in front of `$body`. Throws `EcrIOException` if the body does not fit a frame (more than `MAX_BODY_BYTES`). |
| `bodyLength()` | The body length a 2-byte header announces. Throws `\InvalidArgumentException` if the header is shorter than two bytes. |
| `readBody()` | Reads exactly one message body from a byte source. `$readExactly` is `callable(int $count, string $what): string` and must return exactly `$count` bytes or throw. Throws `EcrIOException`, including "Terminal returned an empty message" for a zero length. |
| `closed()` | The error for a stream that ended partway through `$what`. |
| `readBodyFrom()` | Reads one framed body from an in-memory string, starting at `$offset`, and moves `$offset` past it. For channels that already hold the bytes. Throws `EcrIOException`. |

### `EcrIOException` and `EcrChannelTimeout`

```php
class EcrIOException extends \RuntimeException {}
final class EcrChannelTimeout extends EcrIOException {}
```

- **`EcrIOException`**: the link to the terminal failed. The SDK decides what the failure means for the payment from the message text, the same way the Windows SDK reads `IOException`:
  - "closed the connection": the request may have arrived (connection lost).
  - "not valid JSON" or "empty message": the answer was unreadable (malformed).
  - Anything else: nothing was sent (unreachable).
- **`EcrChannelTimeout`**: the terminal accepted the request and did not answer in time. This is not a refusal: the transaction may still have run.

---

## Serial (USB cable)

Finding and opening the COM port a USB cable terminal is on. Namespace `AmwalPay\Ecr\Serial`.

### `SerialPorts`

COM ports this computer can open. Windows labels come from Device Manager (read from the registry with `reg query`), so "MediaTek USB VCOM (Android) (COM8)" can be told apart from other USB serial devices. The macOS `/dev/tty.*` twin of a `/dev/cu.*` port is hidden, and COM ports are ordered by number. Every method that looks at the machine takes an optional `$ports` list, so a till (or a test) can pass one it already has.

```php
final class SerialPorts
{
    public static function listFor(string $saved, string $terminalSerial, ?array $ports = null): ConnectedPorts;
    public static function portFor(string $saved, ?array $ports = null): SerialPortBinding;
    public static function list(): array;                 // list<SerialPortChoice>
    public static function presentNames(): array;         // list<string>
    public static function preferred(array $ports, string $terminalSerial = ''): string;
    public static function resolve(string $saved, ?array $ports = null): string;
    public static function deviceSerialFor(string $portName): string;
    public static function isDirectPort(string $value): bool;
}
```

| Method | Returns | Notes |
|---|---|---|
| `listFor()` | `ConnectedPorts` | The ports for the terminal form, and the row to select. A plugged-in device whose USB serial equals `$terminalSerial` wins; then the one matching `$saved` (by selection, name or serial); otherwise `preferred()`. A saved port that is not plugged in is not added to the list. |
| `portFor()` | `SerialPortBinding` | The COM port a sale opens for `$saved`, and what to store afterwards. A saved COM name is upgraded to the device's USB serial once it is known. An unplugged device serial gives an empty `port`. |
| `list()` | `list<SerialPortChoice>` | Serial devices plugged in right now, asked of the operating system on every call. Windows: `HKLM\HARDWARE\DEVICEMAP\SERIALCOMM` for live ports, `HKLM\SYSTEM\CurrentControlSet\Enum` for labels and USB serials. Linux: ttys backed by a device in `/sys/class/tty`. macOS: `/dev/tty.*` and `/dev/cu.*`. Never throws; empty when nothing can be read. |
| `presentNames()` | `list<string>` | The port names only (`SerialPort.GetPortNames()`). |
| `preferred()` | `string` | The selection to offer first: a matching USB serial, then the Android virtual COM ("VCOM" or "MediaTek" in the label or name), then the first port. |
| `resolve()` | `string` | The COM port to open for a saved identity. A device serial is looked up again, so a terminal moved to another USB socket opens on its new COM port. A saved COM name or device path is opened as given until the serial is learned. An unplugged device serial returns `""`: a stale COM number may now belong to another device. |
| `deviceSerialFor()` | `string` | The USB serial of the device that owns this COM port now, or `""`. |
| `isDirectPort()` | `bool` | Whether the value is a COM name (`COM8`) or a device path (`/dev/…`), as opposed to a USB serial. |

### `SerialPortChoice`, `ConnectedPorts`, `SerialPortBinding`

```php
final class SerialPortChoice
{
    public readonly string $name;           // COM8, /dev/ttyACM0
    public readonly string $label;          // "COM8 · MediaTek USB VCOM (Android) · device HF4349L000053"; the name when blank
    public readonly string $deviceSerial;   // USB serial; empty when the COM port follows the socket
    public function __construct(string $name, string $label, string $deviceSerial = '');
    public function selection(): string;    // what to store: the device serial, else the name
    public function toArray(): array;       // {name, label, deviceSerial, selection}
}

final class ConnectedPorts
{
    public readonly array $ports;           // list<SerialPortChoice>, plugged in now
    public readonly string $selected;       // the selection to store on the terminal
}

final class SerialPortBinding
{
    public readonly string $port;           // COM port to open now; empty when unplugged
    public readonly string $remember;       // USB serial when known, otherwise the saved COM name
}
```

### `SerialLine`, `SerialLines`, `WindowsSerialLine`, `PosixSerialLine`

```php
interface SerialLine
{
    public function write(string $bytes): void;                    // all of it, or EcrIOException
    public function read(int $maxBytes, float $timeout): string;   // '' when nothing arrived in time
    public function close(): void;
}

final class SerialLines
{
    public static function open(string $port, int $baudRate = 115200): SerialLine;   // throws EcrIOException
}
```

- `SerialLines::open()`: `WindowsSerialLine` on Windows, `PosixSerialLine` elsewhere.
- **`WindowsSerialLine`**: the COM port through `kernel32` (`CreateFile`, `SetCommState`, `SetCommTimeouts`, `ReadFile`, `WriteFile`) with PHP's FFI extension, because PHP's own streams cannot put a deadline on a COM read. Needs `extension=ffi`, and `ffi.enable=true` under a web server. Without FFI, `open()` throws `EcrIOException` naming the setting. A missing port throws "The port 'COM8' does not exist."; a port another program holds throws "Access to the port 'COM8' is denied. …". Writes time out after 3 s.
- **`PosixSerialLine`**: opens the device and sets it to raw 8N1 with `stty` on the open descriptor; reads and writes with `stream_select` deadlines.

---

## Web Service

Building blocks for the ECR Web Service (HTTPS to the Amwal Hub). Sessions use these on their own. Use them directly only when you make the HTTP call yourself or replace the transport. `WebService\WebServiceMessage` and `WebService\WebServiceHttpLog` are `@internal`.

### `WebServiceRequests`

Builds and signs Hub request bodies, for a caller that posts the JSON itself. Each method returns the body as a `Json\JsonObject`; send `$body->toJson()`.

```php
namespace AmwalPay\Ecr\WebService;

final class WebServiceRequests
{
    public static function sale(int $merchantId, int $terminalId, string $terminalSerial, int $amountMinorUnits, string $merchantReference = '', string $currencyCode = '512', string $ecrId = 'ECR01', string $transactionDateTime = '', #[\SensitiveParameter] string $secureHashKey = ''): JsonObject;
    public static function void(int $merchantId, int $terminalId, string $terminalSerial, string $stan, string $merchantReference = '', string $currencyCode = '512', string $ecrId = 'ECR01', string $transactionDateTime = '', #[\SensitiveParameter] string $secureHashKey = ''): JsonObject;
    public static function refund(int $merchantId, int $terminalId, string $terminalSerial, int $amountMinorUnits, string $stan, string $originalTransactionDate, string $merchantReference = '', string $currencyCode = '512', string $ecrId = 'ECR01', string $transactionDateTime = '', #[\SensitiveParameter] string $secureHashKey = ''): JsonObject;
    public static function transactionStatus(int $merchantId, int $terminalId, string $terminalSerial, string $stan = '', string $originalTransactionDate = '', string $merchantReference = '', string $currencyCode = '512', string $ecrId = 'ECR01', string $transactionDateTime = '', string $originalMerchantReference = '', #[\SensitiveParameter] string $secureHashKey = ''): JsonObject;
    public static function closeReceipt(int $merchantId, int $terminalId, string $terminalSerial, string $originalMerchantReference, string $merchantReference = '', string $ecrId = 'ECR01', string $transactionDateTime = '', #[\SensitiveParameter] string $secureHashKey = ''): JsonObject;
    public static function settle(int $merchantId, int $terminalId, string $terminalSerial, string $merchantReference = '', string $currencyCode = '512', string $ecrId = 'ECR01', string $transactionDateTime = '', #[\SensitiveParameter] string $secureHashKey = ''): JsonObject;
    public static function checkReachability(int $merchantId, int $terminalId, string $terminalSerial, string $merchantReference = '', string $currencyCode = '512', string $ecrId = 'ECR01', string $transactionDateTime = '', #[\SensitiveParameter] string $secureHashKey = ''): JsonObject;
}
```

- `$amountMinorUnits`: minor units, so OMR 1.234 is `1234`.
- `$originalTransactionDate`: `yyyyMMdd`.
- `transactionStatus()`: the inquiry. It looks up by receipt number when `$stan` is set, and otherwise by `$originalMerchantReference`.
- `closeReceipt()`: the body for `/Ecr/closeReceipt`. `$originalMerchantReference` is the sale, void or refund being dismissed. A blank value throws `\InvalidArgumentException`.
- `settle()` and `checkReachability()`: the bodies for `/Ecr/settle` and `/Ecr/CheckReachability`. They share one body, in this order: `version`, `merchantId`, `terminalId`, `terminalSerial`, `ecrId`, `merchantReference`, `currencyCode`, `transactionDateTime`, `requestDateTime`, then `secureHashValue` when signed. No `messageType`: the path selects the operation.
- A blank `$merchantReference` is generated. A blank `$transactionDateTime` uses the machine's local time (`EcrSdk::localTimeZone()`). An empty `$secureHashKey` returns an unsigned body.
- **Throws** `InvalidRequestException` for a malformed merchant reference, and `\InvalidArgumentException` if the key cannot sign.

```php
$body = WebServiceRequests::sale(10001, 20002, 'SN-WS-0001', 1234, 'ORDER-1', secureHashKey: $webServiceKey);
```

### `WebServiceSecureHash`

Web Service request signing: the platform's `calcHash(obj, secret)`. `obj` is not the raw JSON body. It is the sorted `key=value&key=value` text of every top-level scalar except `secureHashValue`, following the same rules as Wi-Fi. That text is signed with HMAC-SHA256 under the hex-decoded secret and written as uppercase hex.

```php
final class WebServiceSecureHash
{
    public const FIELD = 'secureHashValue';
    public const KEY_LABEL = 'Web Service secure hash key';

    public static function calcHash(string $message, #[\SensitiveParameter] string $secret): string;
    public static function compose(JsonObject $json): string;
    public static function sign(JsonObject $body, #[\SensitiveParameter] string $secret): string;
    public static function withHash(JsonObject $body, #[\SensitiveParameter] string $secret): JsonObject;
}
```

- `FIELD`: the field added to signed Web Service request bodies.
- `KEY_LABEL`: the log label for the Web Service signing key (distinct from the Wi-Fi key).
- `calcHash()`: HMAC-SHA256 under the hex-decoded secret, as uppercase hex. Returns `""` on any error, or if the secret is empty.
- `compose()`: the sorted `key=value&...` signing text, excluding `secureHashValue`.
- `sign()`: `calcHash(compose($body), $secret)`.
- `withHash()`: a copy of `$body` with `secureHashValue` set. The input is left alone, and an earlier hash is replaced. An empty secret returns the body unchanged. **Throws** `\InvalidArgumentException` if the secret cannot sign, so an unsigned body is never sent by mistake.

### `WebServiceRoutes`

Resolves the Hub URL and the fixed ECR operation paths for an environment. The Hub address itself is resolved by the SDK.

```php
final class WebServiceRoutes
{
    public function __construct(EcrEnvironment $environment);
    public function hub(): string;
    public function path(WebServiceOperation $operation): string;
    public function url(WebServiceOperation $operation): string;
    public function pathFor(EcrTransactionType $type): ?string;
}
```

- `hub()`: the Hub base URL for the environment.
- `path()`: the operation's path.
- `url()`: the hub plus the path.
- `pathFor()`: the path for a transaction type, or `null` for a receipt, which has no Web Service operation.

### `WebServiceOperation`

Web Service ECR operations, with one REST endpoint each on the Hub.

```php
enum WebServiceOperation: string
{
    case Sale = '/Ecr/Sale';
    case Void = '/Ecr/Void';
    case Refund = '/Ecr/Refund';
    case TransactionStatus = '/Ecr/Inquiry';
    case CloseReceipt = '/Ecr/closeReceipt';
    case Settle = '/Ecr/settle';
    case CheckReachability = '/Ecr/CheckReachability';

    public function path(): string;
    public function label(): string;
    public static function forType(EcrTransactionType $type): ?self;
}
```

- `label()`: `Sale`, `Void`, `Refund`, `Transaction status`, `Close receipt`, `Settlement`, `Check reachability`.
- `forType()`: the REST operation for a transaction type, or `null` if it has none (receipts).

### `WebServiceApi`

Posts signed JSON bodies to the Web Service ECR endpoints. Every failure (no response, an empty body, invalid JSON, an HTTP error with nothing readable) comes back as a `success: false` envelope instead of an exception, so callers read every outcome the same way. When the request may have reached the Hub, the envelope carries `nextStep` `INQUIRE_BY_MERCHANT_REFERENCE`: a till must inquire, not treat it as a refusal and send the sale again.

```php
final class WebServiceApi
{
    public const UNKNOWN_OUTCOME_STEP = 'INQUIRE_BY_MERCHANT_REFERENCE';

    public function __construct(EcrConfig $config, EcrLogger $logger, ?HttpTransport $http = null);
    public function routes(): WebServiceRoutes;
    public function postJson(WebServiceOperation $operation, JsonObject $body, ?float $deadline = null): JsonObject;
}
```

- `UNKNOWN_OUTCOME_STEP`: the wire value that `NextStep::InquireByMerchantReference` is read from.
- Constructor: `$http` set to `null` uses `CurlHttpTransport` with `$config->caBundlePath`.
- `postJson()`: posts `$body` and returns the answer as JSON, never throwing for a transport failure. The deadline is `$deadline` when given (the reachability check passes `probeTimeout`), otherwise `connectTimeout + responseTimeout`. A transport failure, an empty body or invalid JSON returns an envelope with `nextStep` set. An HTTP error that explains itself (a `message` or `errorList`) is returned as read, as the Windows SDK. An HTTP error with nothing readable gets `nextStep` only when its status is 5xx.

### `Http\HttpTransport`

Posts one JSON body and returns the answer. `CurlHttpTransport` is the default. Supply your own to route through a corporate proxy or for tests. The URL is always the one the SDK resolved for the environment.

```php
namespace AmwalPay\Ecr\WebService\Http;

interface HttpTransport
{
    public function post(string $url, string $body, array $headers, float $connectTimeout, float $totalTimeout): HttpResponse;
}
```

- `$headers`: `array<string, string>`.
- `$connectTimeout`: seconds to establish the connection.
- `$totalTimeout`: seconds for the whole exchange, connection included.
- **Throws** `HttpTransportException` if no answer was read.

### `Http\CurlHttpTransport`

HTTPS with cURL. The TLS certificate and host name are always verified. On Windows, PHP often ships without a CA bundle, and cURL then fails with "SSL certificate problem: unable to get local issuer certificate". Set `curl.cainfo` in php.ini, or pass `caBundlePath` in the config. **Never turn verification off:** the Hub's answer decides whether goods are handed over, and only TLS proves the answer came from the Hub.

```php
final class CurlHttpTransport implements HttpTransport
{
    public function __construct(?string $caBundlePath = null);
}
```

- **Throws** `\RuntimeException` from the constructor if the curl extension is not loaded. `post()` throws `HttpTransportException`, with `timedOut` set for a cURL timeout. Redirects are not followed.

### `Http\HttpResponse`

One HTTP answer: status, reason phrase (may be empty on HTTP/2) and body text.

```php
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $reasonPhrase = '',
    );
}
```

### `Http\HttpTransportException`

No HTTP answer was read: DNS, TLS, connection refused or reset, or the deadline passed. The request may have reached the Hub, so the SDK treats this as an unknown outcome.

```php
final class HttpTransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null);
}
```

---

## EcrWireResponse

The unified ECR response envelope: `{ success, responseCode, message, data, errorList, nonce?, secureHash? }`. Legacy answers that nest the envelope under `ecrResponse`, or carry flat `approved` / `responseMessage` fields, are normalised.

```php
namespace AmwalPay\Ecr;

final class EcrWireResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $responseCode,
        public readonly string $message,
        public readonly ?JsonObject $data,
        public readonly array $errorList,     // list<string>
        public readonly string $nonce = '',
        public readonly string $secureHash = '',
    );

    public static function parse(JsonObject $json): self;
    public function merchantReference(): string;
    public function terminalSerial(): string;
    public function displayMessage(): string;
    public static function displayMessageFromRaw(string $raw, string $fallback = ''): string;
}
```

- `parse()`: reads an envelope, including the legacy shapes.
- `merchantReference()`: `data.merchantReference`, then legacy `merchantReferenceId`, then `requestId`. Returns `""` if there is no `data`.
- `terminalSerial()`: `data.terminalSerial`, or `""`.
- `displayMessage()`: user-facing text: the `errorList` entries first, then the message.
- `displayMessageFromRaw()`: user-facing text from raw JSON: `errorList` first, then `message`. Returns `$fallback` if the text is empty, is not a JSON object, or says nothing. Never throws.

---

## EcrTransactionType

The operations a terminal can be asked to run. Each type declares what the caller has to supply, so a till's own form can be driven from these flags instead of repeating the rules. What the terminal will accept (whether the original exists, whether it can still be voided) is decided by the terminal.

```php
namespace AmwalPay\Ecr;

enum EcrTransactionType: string
{
    case Sale = 'Sale';
    case Void = 'Void';
    case Refund = 'Refund';
    case Inquiry = 'Inquiry';
    case Receipt = 'Receipt';
    case CloseReceipt = 'CloseReceipt';
    case Settlement = 'Settlement';

    public static function menuOptions(): array;   // list<self>
    public function messageType(): string;
    public function displayName(): string;
    public function requiresAmount(): bool;
    public function requiresOriginalStan(): bool;
    public function requiresOriginalDate(): bool;
    public function movesMoney(): bool;
    public function allowsOtherTerminal(): bool;
}
```

| Case | Meaning | `messageType()` |
|---|---|---|
| `Sale` | An ordinary purchase. | `SALE` |
| `Void` | Cancels an earlier transaction for its full amount. No card is presented. | `VOID` |
| `Refund` | Returns money against an earlier transaction, in full or in part. The cardholder presents the card. | `REFUND` |
| `Inquiry` | Asks what became of an earlier transaction. Read-only and safe to repeat. | `INQUIRY` |
| `Receipt` | Asks for an earlier transaction's e-receipt URL. Wi-Fi and USB only. | `RECEIPT` |
| `CloseReceipt` | Asks the terminal to put its receipt away and return to idle. | `CLOSE_RECEIPT` |
| `Settlement` | Closes the current batch and prints the settlement receipt. Run it with `settlement()`. Web Service: `/Ecr/settle`. | `SETTLEMENT` |

- `menuOptions()`: the operations an operator picks from, in the order a till usually offers them: Sale, Void, Refund, Inquiry. Receipt, CloseReceipt and Settlement are left out by design: the till confirms a settlement with the cashier first, because the terminal starts as soon as the message arrives.
- `messageType()`: the value carried in the message's `messageType` field.
- `displayName()`: the case value, except `Close receipt` for `CloseReceipt`. Settlement declares nothing for the caller to supply: every flag below is false.
- `requiresAmount()`: whether the caller supplies an amount (Sale, Refund). A void takes the original's amount, which only the terminal knows.
- `requiresOriginalStan()`: whether the caller identifies an earlier transaction by its receipt number (STAN): Void, Refund, Inquiry, Receipt.
- `requiresOriginalDate()`: whether the caller supplies the original's date, because a receipt number is unique only within a terminal's day: Refund, Inquiry, Receipt.
- `movesMoney()`: whether the operation moves money: Sale, Void, Refund.
- `allowsOtherTerminal()`: whether the caller may name a different terminal the original was taken on (Wi-Fi only). The same set as `requiresOriginalStan()`.

---

## Json (supporting types)

`AmwalPay\Ecr\Json` holds the strict JSON types used in a few public signatures: `EcrWireResponse::$data`, `WebServiceRequests` return values, `WebServiceApi::postJson()`, and `WebServiceSecureHash`.

```php
namespace AmwalPay\Ecr\Json;

final class Json
{
    public const MAX_DEPTH = 64;
    public static function parseObject(string $text): JsonObject;  // throws JsonException
    public static function parse(string $text): mixed;             // throws JsonException
    public static function write(mixed $value): string;
    public static function pretty(mixed $value): string;
    public static function content(mixed $value): string;
    public static function isScalar(mixed $value): bool;
    public static function compose(JsonObject $json, string $excluded): string;
}

final class JsonObject implements \IteratorAggregate, \Countable
{
    public function __construct(array $values = []);
    public function has(string $key): bool;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): self;
    public function remove(string $key): self;
    public function keys(): array;
    public function count(): int;
    public function getIterator(): \Generator;
    public function str(string $key): string;
    public function obj(string $key): ?JsonObject;
    public function arr(string $key): ?JsonArray;
    public function flag(string $key): bool;
    public function strictBool(string $key): ?bool;
    public function toJson(): string;
}

final class JsonArray implements \IteratorAggregate, \Countable
{
    public function __construct(array $items = []);
    public function items(): array;
    public function count(): int;
    public function getIterator(): \ArrayIterator;
}

final class JsonNumber
{
    public function __construct(public readonly string $raw);
    public function __toString(): string;
}

final class JsonException extends \RuntimeException {}
```

- `Json`: the one JSON reader and writer the SDK uses on the wire. It is strict on purpose. It keeps numbers exactly as written, and refuses repeated keys, trailing commas, comments, single quotes, invalid UTF-8, lone surrogates and a byte order mark. `MAX_DEPTH` matches System.Text.Json, which the Windows SDK parses with. `parseObject()` requires exactly one object. `compose()` builds the sorted `key=value&...` signing text of the scalar entries, leaving out `$excluded`.
- `JsonObject`: keeps keys in order and values exactly as they arrived. Values are `string`, `bool`, `null`, `int`, `JsonNumber`, `JsonObject` or `JsonArray`. `set()` throws `\InvalidArgumentException` for a float or any other type, because money must never pass through a float. `str()` reads any scalar as text, and reads JSON `null` or a missing field as `""`. `flag()` counts only `true`/`false` (or their text); anything else is `false`.
- `JsonNumber`: a JSON number kept exactly as written, because a signature is computed over the number's text.
- `JsonException`: the text is not the single, strict JSON value it was expected to be.
