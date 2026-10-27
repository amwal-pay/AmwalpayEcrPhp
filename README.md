# Amwal ECR SDK for PHP

`amwalpay/ecr-php` drives an Amwal POS terminal from a PHP till: sale, void, refund, inquiry,
e-receipt, close receipt and settlement. **Wi-Fi** and **USB cable** use the terminal's ECR port.
**Web Service** uses HTTPS to the Amwal Hub. Card data stays on the terminal.

It has the same protocol, signing, result semantics and payment-safety rules as the Windows SDK
`AmwalPay.Ecr` (.NET, <https://github.com/amwal-pay/AmwalpayEcrWindows>), and passes the same
known-answer vectors. PHP-specific differences (calls are synchronous, an opt-in cross-process guard,
amounts as strings) are listed in [docs/parity.md](docs/parity.md).

Package `amwalpay/ecr-php` **1.0.0-rc.1**: SIT release candidate. See [docs/release.md](docs/release.md)
before using it in production. Open one session, call the operation, then read the result.

| | |
|---|---|
| Integration guide | [docs/integration-guide.md](docs/integration-guide.md) |
| API reference | [docs/api-reference.md](docs/api-reference.md) |
| Troubleshooting | [docs/troubleshooting.md](docs/troubleshooting.md) |
| Supported versions | [docs/compatibility.md](docs/compatibility.md) |
| Wire protocol | [docs/protocol.md](docs/protocol.md) |
| Parity with the Windows SDK | [docs/parity.md](docs/parity.md) |
| Runnable example | [examples/README.md](examples/README.md) |
| Release and rollback | [docs/release.md](docs/release.md) |

## Install

- PHP 8.1 or later with `curl`, `json` and `openssl` (Windows builds of PHP ship all three).
- USB cable on Windows also needs `extension=ffi` and `ffi.enable=true` (see [USB cable](#usb-cable-implementation)).
- Web Service is HTTPS. PHP must have a CA bundle (`curl.cainfo`, or `EcrConfig` `caBundlePath`).

```powershell
composer require amwalpay/ecr-php:dev-main
```

Pin the exact version. Until the package is published, install the built artifact; see
[Install from the artifact](docs/integration-guide.md#install-from-the-artifact).

## Open a session

<a id="ecropenedsession"></a>

Open one session for the terminal and keep it; every sale, void, refund, inquiry, e-receipt, close
receipt and settlement can use it.

### Wi-Fi implementation

Each terminal shows its IP address and port on its idle screen. Use them to connect from the till.
The till and the terminal must be on the same Wi-Fi.

```php
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Link\LanLink;

$plan = EcrSessions::plan(
    new LanLink('192.168.1.50'),                  // port defaults to 9100
    new EcrConfig(secureHashKey: $wifiKey, stateDirectory: 'C:\\ProgramData\\MyTill\\ecr'),
);
if (!$plan->isReady()) {
    exit(implode('; ', $plan->issues()));
}

$session = EcrSessions::open('P653200085189', $plan);
```

### Web Service implementation

```php
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\Link\WebServiceLink;

$plan = EcrSessions::plan(
    new WebServiceLink(merchantId: '13593', terminalId: '101311'),
    new EcrConfig(
        secureHashKey: $webServiceKey,
        environment: EcrEnvironment::UAT,      // SIT is the default; PROD for live
    ),
);

$session = EcrSessions::open('P653200008251', $plan);
```

Hub addresses stay inside the SDK. `EcrEnvironment::fromName()` reads `SIT`, `UAT` and `PROD`,
ignoring case. Any other name reads as `SIT`.

### USB cable implementation

There is no IP address. The terminal profile is USB cable, and the signing key is the Wi-Fi key.
Windows shows the cable as a COM port, "MediaTek USB VCOM (Android)", at 115200 baud, 8 data bits, no
parity, 1 stop bit. The bytes are the same framed messages as Wi-Fi.

The COM number can change the next time the cable is plugged in. Store `SerialPortChoice::selection()`
(the USB serial when the device has one) and open `SerialPorts::portFor()->port` for the sale.
`SerialPorts::list()` returns only the devices plugged in on that call. A COM port remembered from an
earlier plug is not in the list.

```php
use AmwalPay\Ecr\Channel\SerialEcrChannel;
use AmwalPay\Ecr\Link\UsbCableLink;
use AmwalPay\Ecr\Serial\SerialPorts;

$listed = SerialPorts::listFor(saved: $storedCable, terminalSerial: '');
// $listed->ports is what is plugged in now. $listed->selected is what to store.

$open = SerialPorts::portFor($listed->selected);
if ($open->port === '') {
    exit('Plug the terminal in. Windows assigns the COM port from the USB serial.');
}

$plan = EcrSessions::plan(new UsbCableLink($open->port), new EcrConfig(secureHashKey: $wifiKey));
if (!$plan->isReady()) {
    exit(implode('; ', $plan->issues()));
}

$session = EcrSessions::open(
    'P653200085189',
    $plan,
    usbChannel: fn () => new SerialEcrChannel($open->port),
);
```

`open()` requires `usbChannel` for a cable plan. `SerialEcrChannel` opens that COM port for the sale
and leaves it closed between calls. A cable session uses the same sale, void, refund, inquiry,
e-receipt, close receipt and settlement calls as Wi-Fi.

On Windows the port is driven through PHP's FFI extension, because PHP's own streams cannot put a
deadline on a COM read: set `extension=ffi` and `ffi.enable=true` in php.ini. Without it the sale
fails with `Failure\Unreachable` and nothing is sent. Linux (`/dev/ttyACM0`) and macOS
(`/dev/cu.usbmodem…`) need only `stty`.

## Operations

Amounts are major units: a string (`'1.234'`), an int or a float, rounded like the Windows SDK's
`decimal`. `merchantReference` names this attempt. Save it before the call. Leave it blank and the
SDK generates one and returns it on the result.

### Sale

The cardholder presents the card on the terminal.

```php
$reference = 'ORDER-1001-1';           // or EcrSessions::newMerchantReference()
save($reference);

$result = $session->sale('1.234', $reference);
```

Read `$result` as in [Read the result](#read-the-result).

### Void

Cancels an earlier transaction for its full amount. No card. `receiptNumber` is the receipt number
(STAN). `originalTerminalId` names another terminal when the original was not taken on this one. Web
Service ignores it and uses the terminal in the plan. The void has its own merchant reference.

```php
$result = $session->void(
    receiptNumber: $stan,
    originalTerminalId: '',
    merchantReference: 'ORDER-1001-V1',
);
```

### Refund

Returns some or all of an earlier transaction. The cardholder presents the card. `transactionDate` is
the original day, `yyyyMMdd`.

```php
$result = $session->refund(
    amount: '1.000',
    receiptNumber: $stan,
    transactionDate: '20261019',
    originalTerminalId: '',
    merchantReference: 'ORDER-1001-R1',
);
```

### Inquiry by receipt number

Reads a transaction. No card, no money. Safe to repeat.

```php
$inquiry = $session->inquire(receiptNumber: $stan, transactionDate: '20261019');
```

### Inquiry by merchant reference

Reads the attempt you named `originalReference`. Use this when the sale, void or refund never
answered: you still have the reference you sent, and you do not yet have a receipt number.
`transactionDate` is optional. On Wi-Fi and USB, `originalTerminalId` names another terminal.

```php
$inquiry = $session->inquireByReference('ORDER-1001-1');
```

### E-receipt

Wi-Fi and USB. Returns a URL for a QR code. Safe to repeat. `ReceiptReady` carries `url`.
`ReceiptUnavailable` means there is no receipt to show. `ReceiptFailed` means the exchange failed;
repeat the call.

```php
$receipt = $session->receipt(receiptNumber: $stan, transactionDate: '20261019');

if ($receipt instanceof ReceiptReady) {
    showQr($receipt->url);
}
```

On Web Service the same call returns `ReceiptUnavailable`. `$session->supportsReceipt()` is false
there.

### Close receipt

<a id="close-receipt"></a>

Asks the terminal to leave the receipt screen and return to idle. Call it from the till's Close
receipt action when the cashier is finished. Sale, void, refund and dismissing a dialog do not send
it. Safe to repeat: `ReceiptClosed` means the terminal is idle, whether a receipt was up or not.

```php
$closed = $session->closeReceipt(
    merchantReference: '',
    originalMerchantReference: 'ORDER-1001-1',
);
```

`originalMerchantReference` is the sale, void or refund you are dismissing. Web Service requires it.
Wi-Fi and USB ignore it. Both session arguments are strings, so pass them by name.

| Call | Sale's reference |
|---|---|
| `EcrOpenedSession::closeReceipt($merchantReference, $originalMerchantReference)` | Second argument |
| `EcrWebServiceTerminal::closeReceipt($originalMerchantReference, $merchantReference)` | First argument |
| `EcrTerminal::closeReceipt($merchantReference)` | None. Wi-Fi and USB only. |

```php
if ($closed instanceof CloseReceiptRefused) {
    show($closed->reason);              // firmware has no CLOSE_RECEIPT; leave the receipt up
} elseif ($closed instanceof CloseReceiptFailed) {
    show($closed->failure->message);    // nothing changed; the call can be repeated
}
```

### Settlement

<a id="settlement"></a>

Closes the current batch and prints the settlement receipt. Wi-Fi and USB cable send a `SETTLEMENT`
message on the same session as a sale. Web Service posts `/Ecr/settle` with the identity body (no
`messageType`). Confirm with the cashier first: the close starts as soon as the request arrives. The
terminal must be idle. Uses the full response timeout, because a close can wait on pending reversals.
Safe to repeat when the answer is lost: a batch that closed answers `NothingToSettle` the next time,
and one that did not settles on the retry. `supportsSettlement()` is true for every transport.

```php
use AmwalPay\Ecr\Result\{Settled, NothingToSettle, NotSettled, SettlementFailed};

$settlement = $session->settlement();

if ($settlement instanceof Settled) {
    show($settlement->batchId, $settlement->netAmount, $settlement->totals);
} elseif ($settlement instanceof NothingToSettle) {
    show($settlement->message);
} elseif ($settlement instanceof NotSettled) {
    show($settlement->reason);
} elseif ($settlement instanceof SettlementFailed) {
    show($settlement->failure->message);   // the batch may or may not have closed
}
```

Amounts in `Settled` are major units. `totals` always has `SALE`, `REFUND`, `VOID`, `PRE_AUTH` and
`CAPTURE`, with a zero row where the terminal omitted one. `closeReceipt()` puts the terminal's
settlement summary away; it is the same call as after a sale.

### Reachability

Wi-Fi and USB, before a sale. Proves the port or cable answers. It does not prove the terminal is
idle.

```php
$probe = $session->probeReachability();
if ($probe !== null && !$probe->reachable) {
    show($probe->error);
}
```

`probeTimeout` is 3 seconds. Web Service returns `null` from `probeReachability()`, so a sale is not
held for a Hub probe.

Check reachability is a separate Web Service call, `POST /Ecr/CheckReachability`, with the same
identity body as settle. Call it from its own action. It uses `probeTimeout`.

```php
$check = $session->checkReachability();
```

## Read the result

<a id="handle-the-result"></a>

`sale()`, `void()` and `refund()` return an `EcrResult`.

```php
use AmwalPay\Ecr\Result\{Approved, Declined, Failed, Found};

if ($result instanceof Approved) {
    record($result->merchantReference, $result->amount, $result->rrn, $result->authCode);
    if ($result->partialApproval) {
        collectBalance($result->requestedAmount, $result->amount);
    }
} elseif ($result instanceof Declined) {
    show($result->reason);
} elseif ($result instanceof Failed && $result->settled()) {
    apply($result->recovered->transaction);           // Wi-Fi/USB asked the terminal by itself
} elseif ($result instanceof Failed && $result->failure->outcomeUnknown()) {
    markUnresolved($result->merchantReference);       // inquire by reference; never resend
} else {
    show($result->failure->message);                  // nothing was sent
}
```

| Result | Meaning |
|---|---|
| `Approved` | Money moved. `amount` is what was taken or returned. `responseCode` is `00` on a plain approval. `maskedPan` is masked. |
| `Approved` with `partialApproval` | The issuer authorised less than you asked. `requestedAmount` is what you asked. Collect the difference before you hand over the goods. |
| `Declined` | The terminal answered and refused. `reason` is the text to show. `nextStep` is `None`. |
| `Failed` and `Failure\Unreachable` | Nothing was sent. Wi-Fi and USB only. |
| `Failed` and `failure->outcomeUnknown()` | The request went out and the answer did not settle it: timeout, connection lost, malformed answer, or a signature that does not match. The payment may have completed. |
| `Failed->settled()` | Wi-Fi or USB already inquired. `recovered` is `Found`. Record that transaction. |
| `InvalidRequestException` | The call was rejected before anything was sent. A merchant reference longer than 32 characters, or one that contains a space, `&` or `=`, throws here. |

An answer whose `nextStep` is `INQUIRE_BY_MERCHANT_REFERENCE` arrives as `Failed` (`Timeout` or
`ConnectionLost`). That includes a Web Service timeout, a lost HTTP call, an empty or unreadable body,
and an HTTP 5xx with nothing readable. `outcomeUnknown()` is false only for `Unreachable` (and, with
the guard on, `Busy` and `Unresolved`).

### Inquiry

| Result | Meaning |
|---|---|
| `Found` | `transaction` is the record. `status` is the outcome (`Approved`, `Declined`, `Voided`, or other text from the backend). Compare it ignoring case. |
| `NotFound` | This lookup returned no record. `reason` is the backend's text when it sent any. |
| `InquiryFailed` | The lookup itself failed. Repeat the inquiry. An inquiry does not move money. |

On `Found`, `transaction->stan` is the receipt number. `canVoid` and `canRefund` say whether the
terminal would accept that operation now. When `partialApproval` is set, `amount` is what was asked
and `authorizedAmount` is what was charged.

## Important notes

### Resolve an unknown outcome

<a id="resolve-an-unknown-outcome"></a>

`outcomeUnknown()` means the payment may have completed. Inquire by the merchant reference of that
attempt. Charge the order again only after `transaction->status` is `Declined`, or after the terminal
confirms the attempt did not complete.

1. Save the reference before `sale()`, `void()` or `refund()`.
2. On Wi-Fi and USB, read `Failed->recovered` first. With `autoInquireOnFailure` left on (the
   default), the SDK has already called `inquireByReference()` once. `Found` settles the attempt.
   `NotFound`, `InquiryFailed` or `null` leaves it unknown. Web Service sets `recovered` to `null`.
3. Call `inquireByReference()` with the saved reference.
4. Read `Found->transaction->status`, ignoring case.
   - `Approved`: the attempt completed. On a void or refund, the void or refund completed.
   - `Declined`: it did not. A new attempt needs a new reference.
   - Any other value, including `Voided` and `Reversed`: leave the order unresolved.
5. `NotFound` after a lost answer means this lookup returned no record. The same answer follows a
   terminal reset, or a record that has aged out. Confirm on the terminal, or with Amwal, before you
   take payment again.
6. `InquiryFailed` means the lookup failed. Inquire again.
7. On startup, inquire every attempt still marked unresolved (`$session->reconcile()` does this when
   the guard is on).

`Failure\Unreachable` means the connection was never made. There is no attempt to look up.

### Merchant reference

32 characters maximum, with space, `&` and `=` excluded. The SDK trims the value. Use one reference
per attempt; an order number plus an attempt suffix fits. A void and a refund each get a new
reference.

### Receipt number

`Approved` does not carry it. Read `transaction->stan` from an inquiry by merchant reference, or read
the printed receipt. The SDK sends the number padded to 6 digits. Pass that receipt's day as
`yyyyMMdd` to refund, inquiry by receipt number and e-receipt. A receipt number is unique on one
terminal for one day.

### Amounts and currency

`currencyCode` defaults to `'512'` and `minorUnitDigits` to `3` (OMR). Another currency needs both.
`minorUnitDigits` accepts 0 to 4. The SDK rounds half away from zero to that many places, then sends
minor units.

### Timeouts

| Transport | Wait |
|---|---|
| Wi-Fi | `connectTimeout` 10 s, then `responseTimeout` 120 s. A lost answer can add one more response wait for the automatic inquiry. |
| USB | The till waits 120 seconds for the answer, the same as Wi-Fi. |
| Web Service through `plan()` | A `responseTimeout` of 120 s becomes `EcrConfig::WEB_SERVICE_RESPONSE_TIMEOUT` (130 s). Any other value is kept. The HTTP deadline is `connectTimeout` + `responseTimeout`: 140 s with the defaults. |

`new EcrWebServiceTerminal(...)` skips the 130 s raise. Set
`responseTimeout: EcrConfig::WEB_SERVICE_RESPONSE_TIMEOUT` on that config.

The default wait covers card present and PIN. PHP calls are synchronous: the SDK raises
`max_execution_time` and sets `ignore_user_abort` for a payment or settlement wait, but the web
server must also hold the request that long (see
[the integration guide](docs/integration-guide.md#14-timeouts-and-long-waits-in-php-on-windows)).
A request that already reached the terminal can still complete.

### Keys

Even-length hex, at least 16 characters. Building an `EcrConfig` with an invalid key throws
`InvalidArgumentException`. Pass operator-typed text as `rawSecureHashKey` to `plan()` and the same
problem is listed in `issues()`.

`open()` requires a key. `new EcrTerminal(...)` and `new EcrWebServiceTerminal(...)` with an empty key
send unsigned requests and skip the answer check.

Wi-Fi and USB sign the field `secureHash`. Web Service signs `secureHashValue`. The two secrets are
issued separately. `$plan->signingKeyLabel()` names the one this plan expects.

### Logging

```php
$logger = EcrLogger::from(fn (string $line) => error_log($line));   // or EcrLogger::psr($psr3Logger)
$session = EcrSessions::open($serial, $plan, logger: $logger);
```

Logging is off until you pass a logger. `EcrLogger::none()` discards every line. Each line is the
request or the response: masked PAN, amount and reference. Card numbers, PIN, CVV and tokens are
masked again on every line. An exception thrown by the sink escapes the call.

### Payment safety in PHP (opt-in)

PHP serves each request in its own process. With `EcrConfig(guardFinancialOperations: true)` the SDK
enforces, across all PHP processes on the machine: one sale, void or refund per terminal at a time
(`Failure\Busy`), each merchant reference used once (`DuplicateMerchantReferenceException`), and no
new money movement while an outcome is unknown (`Failure\Unresolved`, until `reconcile()` or
`markResolved()` settles it). Point `stateDirectory` at a durable, private folder. See
[the integration guide](docs/integration-guide.md#12-the-financial-guard).

## When you build the message yourself

`EcrTransactionType` is `Sale`, `Void`, `Refund`, `Inquiry`, `Receipt`, `CloseReceipt`, `Settlement`.
`EcrTransactionType::menuOptions()` is the operator menu: sale, void, refund, inquiry.
`requiresAmount()`, `requiresOriginalStan()`, `requiresOriginalDate()`, `movesMoney()` and
`allowsOtherTerminal()` say which fields that operation needs. `EcrTerminal::run()` accepts only the
three that move money.

`WebServiceRequests::sale()`, `void()`, `refund()`, `transactionStatus()`, `closeReceipt()`,
`settle()` and `checkReachability()` are for a caller that posts the JSON. Each writes the body and
signs it when you pass the key. Settle and check-reachability share one body: `version`,
`merchantId`, `terminalId`, `terminalSerial`, `ecrId`, `merchantReference`, `currencyCode`,
`transactionDateTime`, `requestDateTime`, `secureHashValue`. The session methods do this themselves.

## Develop

```powershell
composer install
composer test                 # unit + integration (real TCP, real cURL, child processes)
composer check:secrets        # no keys or card numbers in the repository
composer build                # dist/amwalpay-ecr-php-<version>.zip + .sha256
```

The Windows COM-port test runs only when FFI is loaded: `php -d extension=ffi vendor/bin/phpunit`.
