# Integration guide

How to put an Amwal POS terminal behind a PHP till with `amwalpay/ecr-php`: install, configure,
run every operation over Wi-Fi, USB cable or Web Service, read the results, and resolve the payments
whose answer was lost. Every snippet uses placeholders (`$wifiKey`, `SN-WIFI-0001`, `10001`); real
values come from Amwal and your own configuration.

The structure follows the Windows SDK's README (`AmwalPay.Ecr`, .NET), so the two can be read side by
side.

1. [What you need from Amwal](#1-what-you-need-from-amwal)
2. [Install](#2-install)
3. [Configure](#3-configure)
4. [Wi-Fi: open a session](#4-wi-fi-open-a-session)
5. [Web Service: open a session](#5-web-service-open-a-session)
6. [USB cable: open a session](#6-usb-cable-open-a-session)
7. [Operations](#7-operations)
8. [Settlement](#8-settlement)
9. [Reachability](#9-reachability)
10. [Read the result](#10-read-the-result)
11. [Resolve an unknown outcome](#11-resolve-an-unknown-outcome)
12. [The financial guard](#12-the-financial-guard)
13. [References, receipt numbers, amounts and dates](#13-references-receipt-numbers-amounts-and-dates)
14. [Timeouts, and long waits in PHP on Windows](#14-timeouts-and-long-waits-in-php-on-windows)
15. [Keys and logging](#15-keys-and-logging)
16. [When you build the message yourself](#16-when-you-build-the-message-yourself)
17. [Building the till's screens](#17-building-the-tills-screens)

---

## 1. What you need from Amwal

| | Wi-Fi (`ecrMode: 2`) | USB cable (`ecrMode: 1`) | Web Service (`ecrMode: 4`) |
|---|---|---|---|
| Terminal set up in TMS for | ECR over Wi-Fi | ECR over USB cable | ECR Web Service |
| Address | The IP and port on the terminal's idle screen (port 9100 by default) | None: the COM port the OS assigns to the cable | None: the SDK calls the Amwal Hub |
| Identity | The terminal's serial number | The terminal's serial number | Serial number, numeric merchant ID, numeric terminal ID |
| Secret | The **Wi-Fi secure hash key** (hex) | The **Wi-Fi secure hash key** | The **Web Service secure hash key** (hex), a different secret |
| Environment | n/a | n/a | SIT, UAT or PROD |
| Network | Till and terminal on the same LAN/Wi-Fi; outbound TCP to the terminal's port | A USB cable from the till to the terminal | Outbound HTTPS to the Hub |

Endpoint, authentication, header and environment details for Web Service are owned by the Amwal
POS team (POS-552); confirm them before SIT.

## 2. Install

```powershell
composer require amwalpay/ecr-php:dev-main
```

Requirements: PHP 8.1+ with `curl`, `json`, `openssl`. USB cable on Windows also needs `ffi`
(section 6). See [compatibility.md](compatibility.md).

### Install from the artifact

Until the package is on a registry, every environment installs the same built zip, so SIT tests
exactly what will ship. Put `amwalpay-ecr-php-1.0.0-rc.1.zip` in a folder (here `../artifacts`) and:

```json
{
    "require": { "amwalpay/ecr-php": "1.0.0-rc.1" },
    "repositories": [ { "type": "artifact", "url": "../artifacts" } ]
}
```

Check the zip against its `.sha256` file before installing. `examples/bin/verify-artifact.php`
shows how a till can confirm at run time which version it loaded.

## 3. Configure

`EcrConfig` is immutable and checked when it is built: an invalid value throws
`InvalidArgumentException` at start-up, not on the shop floor.

```php
$config = new EcrConfig(
    ecrId: 'TILL-01',                                    // this till's name on the terminal's records
    secureHashKey: $wifiKey,                             // Wi-Fi key or Web Service key, by link
    stateDirectory: 'C:\\ProgramData\\MyTill\\ecr',      // durable, private: the guard's lock and journal
);
$later = $config->with(responseTimeout: 90);             // a validated copy
```

| Setting | Default | Notes |
|---|---|---|
| `ecrId` | `ECR01` | Shown on the terminal's records. |
| `currencyCode` | `512` (OMR) | ISO 4217 **numeric**, three digits. |
| `minorUnitDigits` | `3` | 0 to 4. Another currency needs both settings. |
| `port` | `9100` | Wi-Fi. The link's port wins. |
| `connectTimeout` | `10` s | Wi-Fi connect; Web Service connect. |
| `responseTimeout` | `120` s | Wi-Fi and USB answer wait. `plan()` raises an untouched 120 s to 130 s for Web Service: 140 s with connect. |
| `probeTimeout` | `3` s | Reachability probe, and the Web Service `CheckReachability` deadline. |
| `secureHashKey` | empty | Even-length hex, at least 16 characters. `open()` requires one. |
| `merchantId`, `terminalId` | empty | Set from `WebServiceLink` by `plan()`. |
| `environment` | `SIT` | `EcrEnvironment::fromName('uat')` reads names, ignoring case; unknown names read as SIT. |
| `autoInquireOnFailure` | `true` | Wi-Fi and USB: follow a lost answer with one inquiry by reference. |
| `guardFinancialOperations` | `false` | Opt-in. See [section 12](#12-the-financial-guard). |
| `stateDirectory` | system temp + `amwalpay-ecr` | **Set it in production.** The temp folder can be cleaned. |
| `extendTimeLimit` | `true` | Raises `max_execution_time` before a payment wait. |
| `caBundlePath` | none | Web Service TLS, when PHP has no CA bundle. |
| `timezone` | machine's local time | Leave it unset: `transactionDateTime` uses the operating system's clock and time zone, as the Windows SDK's `DateTime.Now`. php.ini's `date.timezone` is ignored. Set it only to override the machine. |

`EcrSessions::plan()` turns a link plus a config into a plan and **lists** problems rather than
throwing, so a settings screen can show them. Pass the key as the operator typed it in
`rawSecureHashKey`: it is trimmed and checked, and a bad one becomes an issue.

```php
$plan = EcrSessions::plan($link, $config, rawSecureHashKey: $typedKey);
foreach ($plan->issues() as $issue) { echo $issue, "\n"; }
echo $plan->connectionSummary();     // "192.168.1.50:9100", "COM8" or "UAT · merchant 10001 · terminal 20002"
echo $plan->signingKeyLabel();       // which of the two keys this plan expects
```

## 4. Wi-Fi: open a session

Each terminal shows its IP address and port on its idle screen. Use them to connect from the till.
The till and the terminal must be on the same Wi-Fi.

```php
$plan = EcrSessions::plan(new LanLink('192.168.1.50', 9100), $config);
$session = EcrSessions::open('SN-WIFI-0001', $plan, EcrLogger::from(fn ($line) => error_log($line)));

$probe = $session->probeReachability();          // before a sale
if (!$probe->reachable) {
    show("Terminal not reachable: {$probe->endpoint} ({$probe->error})");
}
```

A probe proves the port answers, not that the terminal is idle. It sends nothing.

Every request is signed with the Wi-Fi key and carries a fresh nonce; every answer must be signed
with the same key and echo that nonce, or it is discarded as `Failure\Unauthenticated`. A key
mismatch therefore shows up as repeated `Unauthenticated` failures, never as approvals.

No terminal yet? `php tools/fake-pos-server.php --key <hex> --port 19100` runs a signed fake terminal;
register `127.0.0.1:19100`. Its flags simulate declines, partial approvals, busy terminals, lost and
garbled answers, resets and old firmware.

## 5. Web Service: open a session

```php
$plan = EcrSessions::plan(
    new WebServiceLink(merchantId: '10001', terminalId: '20002'),
    new EcrConfig(secureHashKey: $webServiceKey, environment: EcrEnvironment::UAT),
);
$session = EcrSessions::open('SN-WS-0001', $plan);
```

- The Hub address is chosen by the SDK from the environment and cannot be typed in.
  `EcrEnvironment::fromName()` reads `SIT`, `UAT` and `PROD`, ignoring case; any other name reads as
  `SIT`.
- `probeReachability()` returns `null`, so a sale is never held for a Hub probe. The Hub check is
  its own call, `checkReachability()` (section 9).
- There is no e-receipt (`receipt()` answers `ReceiptUnavailable`).
- A lost answer is never inquired about automatically: `Failed->recovered` is always `null`. The
  inquiry is yours.
- `originalTerminalId` is ignored: the plan's terminal is used.
- A corporate proxy or custom HTTP stack: pass an `HttpTransport` to `EcrSessions::open()`. The URL
  it is given is always the SDK's.

## 6. USB cable: open a session

There is no IP address. The terminal profile is USB cable, and the signing key is the **Wi-Fi key**.
Windows shows the cable as a COM port, "MediaTek USB VCOM (Android)", at 115200 baud, 8 data bits, no
parity, 1 stop bit (Linux: `/dev/ttyACM0`; macOS: `/dev/cu.usbmodem…`). The bytes are the same framed
messages as Wi-Fi, so sale, void, refund, inquiry, e-receipt, close receipt and settlement all work
the same.

The COM number can change the next time the cable is plugged in. Store
`SerialPortChoice::selection()` (the USB serial when the device has one) and open
`SerialPorts::portFor()->port` for the sale. `SerialPorts::list()` returns only the devices plugged in
on that call; a COM port remembered from an earlier plug is not in it.

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
    'SN-CABLE-0001',
    $plan,
    usbChannel: fn () => new SerialEcrChannel($open->port),
);
```

`open()` requires `usbChannel` for a cable plan (a `\LogicException` otherwise). `SerialEcrChannel`
opens the COM port for each exchange and closes it after; it never opens the port just to probe,
because opening and closing drops DTR and the terminal treats that as a hang-up.

**Windows needs PHP's FFI extension.** PHP's own file streams cannot put a deadline on a COM read, and
a payment wait without one could hang the till, so the SDK talks to the port through `kernel32` with
FFI. In php.ini:

```ini
extension=ffi
ffi.enable=true    ; the default "preload" allows FFI on the command line only
```

Without it the sale fails with `Failure\Unreachable` and a message naming the setting; nothing is
sent. Linux and macOS need only `stty`, which the system has. The PHP user must be allowed to open the
port (`dialout` group on Linux).

A request is written only once the port is open and set up, so every failure before that is
`Unreachable`. After the request is written, a lost port is `ConnectionLost` and no answer in time is
`Timeout`: both are unknown outcomes, followed by one inquiry by reference like Wi-Fi.

## 7. Operations

Amounts are major units: a string (`'1.234'`), an int or a float. `merchantReference` names this
attempt. Save it before the call. Leave it blank and the SDK generates one and returns it on the
result.

| Call | Moves money | Wi-Fi / USB cable | Web Service |
|---|---|---|---|
| `sale($amount, $merchantReference)` | yes | ✓ | ✓ |
| `void($receiptNumber, $originalTerminalId, $merchantReference)` | yes | ✓ | ✓ (`originalTerminalId` ignored) |
| `refund($amount, $receiptNumber, $transactionDate, $originalTerminalId, $merchantReference)` | yes | ✓ | ✓ |
| `inquire($receiptNumber, $transactionDate, $originalTerminalId)` | no | ✓ | ✓ |
| `inquireByReference($originalReference, $transactionDate)` | no | ✓ | ✓ |
| `receipt($receiptNumber, $transactionDate)` | no | ✓ | `ReceiptUnavailable` |
| `closeReceipt(merchantReference:, originalMerchantReference:)` | no | ✓ | ✓ (needs the original) |
| `settlement($merchantReference)` | closes the batch | ✓ `SETTLEMENT` | ✓ `/Ecr/settle` |
| `probeReachability()` | no | ✓ | `null` |
| `checkReachability()` | no | `null` | ✓ `/Ecr/CheckReachability` |

**Sale.** The cardholder presents the card.

```php
$reference = 'ORDER-1001-1';
save($reference);
$result = $session->sale('1.234', $reference);
```

**Void.** Cancels an earlier transaction for its full amount, by receipt number (STAN). No card.
The void has its own reference. `originalTerminalId` names another terminal (Wi-Fi and USB only).

```php
$result = $session->void(receiptNumber: $stan, originalTerminalId: '', merchantReference: 'ORDER-1001-V1');
```

**Refund.** Returns some or all of an earlier transaction. The cardholder presents the card.
`transactionDate` is the original's day, `yyyyMMdd`.

```php
$result = $session->refund('1.000', receiptNumber: $stan, transactionDate: '20261019', merchantReference: 'ORDER-1001-R1');
```

**Inquiry by receipt number.** Reads a transaction. No card, no money; safe to repeat, answered even
while the terminal is busy. The date is optional but narrows the lookup.

**Inquiry by merchant reference.** Reads the attempt you sent as `originalReference`: the way to find a
sale, void or refund that never answered. Leave the date blank rather than inventing "today". On
Wi-Fi and USB, `originalTerminalId` names another terminal.

**E-receipt (Wi-Fi and USB).** `ReceiptReady->url` goes in a QR code for the customer's phone. Safe
to repeat. `ReceiptUnavailable` means there is no receipt to show; `ReceiptFailed` means the exchange
failed: repeat the call.

**Close receipt.** Asks the terminal to leave the receipt screen and return to idle. Call it from the
cashier's Close receipt button when they are done; sale, void, refund and dismissing a dialog never
send it. Safe to repeat: `ReceiptClosed` means the terminal is idle, whether a receipt was up or not.
Firmware without `CLOSE_RECEIPT` answers `CloseReceiptRefused`: leave the receipt up and carry on.

`originalMerchantReference` is the sale, void or refund you are dismissing. Web Service requires it;
Wi-Fi and USB ignore it. Both session arguments are strings, so pass them by name.

| Call | Sale's reference |
|---|---|
| `EcrOpenedSession::closeReceipt($merchantReference, $originalMerchantReference)` | Second argument |
| `EcrWebServiceTerminal::closeReceipt($originalMerchantReference, $merchantReference)` | First argument |
| `EcrTerminal::closeReceipt($merchantReference)` | None. Wi-Fi and USB only. |

## 8. Settlement

Closes the current batch and prints the settlement receipt. Wi-Fi and USB cable send a `SETTLEMENT`
message on the same session as a sale. Web Service posts `/Ecr/settle` with the identity body (no
`messageType`). **Confirm with the cashier first**: the close starts as soon as the request arrives.
The terminal must be idle. It uses the full response timeout, because a close can wait on pending
reversals. `supportsSettlement()` is true for every transport.

```php
use AmwalPay\Ecr\Result\{Settled, NothingToSettle, NotSettled, SettlementFailed};

$settlement = $session->settlement();

switch (true) {
    case $settlement instanceof Settled:
        show($settlement->batchId, $settlement->netAmount, $settlement->totals);
        break;
    case $settlement instanceof NothingToSettle:
        show($settlement->message);
        break;
    case $settlement instanceof NotSettled:
        show($settlement->reason);           // the batch is still open
        break;
    case $settlement instanceof SettlementFailed:
        show($settlement->failure->message);  // the batch may or may not have closed
        break;
}
```

| Result | Meaning |
|---|---|
| `Settled` | The batch closed. `batchId`, `currency`, `startTime`, `endTime`, `totalTransactions`, `netAmount`, `totals`. |
| `NothingToSettle` | The terminal is idle and the batch had nothing in it. |
| `NotSettled` | The batch is still open: counts did not match (`status` `MISMATCH`, `uploadedTransactions`), the session expired, the terminal was busy, or this firmware cannot settle (`status` empty). `reason` is the text to show. |
| `SettlementFailed` | The exchange failed. The batch may or may not have closed. |

Amounts in `Settled` are major units. `totals` always has `SALE`, `REFUND`, `VOID`, `PRE_AUTH` and
`CAPTURE`, in that order, each an `EcrSettlementTotal` (`count`, `amount`), with a zero row where the
terminal omitted one.

It is safe to repeat when the answer is lost: a batch that closed answers `NothingToSettle` the next
time, and one that did not settles on the retry. The SDK never repeats it by itself.
`closeReceipt()` puts the terminal's settlement summary away; it is the same call as after a sale.

## 9. Reachability

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
identity body as settle. Call it from its own action. It uses `probeTimeout` as its whole deadline,
and returns `null` on Wi-Fi and USB.

```php
$check = $session->checkReachability();   // EcrReachability: reachable, error, endpoint (the Hub origin)
```

## 10. Read the result

`sale()`, `void()` and `refund()` return an `EcrResult`:

```php
switch (true) {
    case $result instanceof Approved:
        record($result);                                   // amount, rrn, authCode, maskedPan
        if ($result->partialApproval) {
            collectBalance($result->requestedAmount, $result->amount);
        }
        break;
    case $result instanceof Declined:
        show($result->reason);
        break;
    case $result instanceof Failed && $result->settled():
        apply($result->recovered->transaction);            // Wi-Fi/USB found it by itself
        break;
    case $result instanceof Failed && $result->failure->outcomeUnknown():
        markUnresolved($result->merchantReference);        // section 11
        break;
    default:
        show($result->failure->message);                   // nothing was sent
}
```

| Result | Meaning |
|---|---|
| `Approved` | Money moved. `amount` is what was taken or returned. `responseCode` is `00` on a plain approval. `maskedPan` is masked. |
| `Approved` with `partialApproval` | The issuer authorised less than you asked. `requestedAmount` is what you asked. Collect the difference before you hand over the goods. |
| `Declined` | The terminal answered and refused. `reason` is the text to show. `nextStep` is `None`. |
| `Failed` and `Failure\Unreachable` | Nothing was sent. Wi-Fi and USB only. |
| `Failed` and `failure->outcomeUnknown()` | The request went out and the answer did not settle it. The payment may have completed. |
| `Failed->settled()` | Wi-Fi or USB already inquired. `recovered` is `Found`. Record that transaction. |
| `InvalidRequestException` | Refused before anything was sent: a merchant reference longer than 32 characters, or one with a space, `&` or `=`; an amount that is not a number. |

| Failure | `outcomeUnknown()` | What happened |
|---|---|---|
| `Unreachable` | false | No connection was made (or the COM port could not be opened). Nothing sent. |
| `Busy` | false | Another sale, void or refund is running on this terminal (guard on). Nothing sent. |
| `Unresolved` | false | An earlier outcome on this terminal is unknown (guard on). Nothing sent. |
| `Timeout` | true | The request went out; no answer in time. |
| `ConnectionLost` | true | The connection or cable broke after the request went out. |
| `Malformed` | true | The answer was unreadable, or (Web Service) the IDs on the config are not numeric and nothing was sent. |
| `Unauthenticated` | true | The answer was not signed with this key or answered another request. |

An answer whose `nextStep` is `INQUIRE_BY_MERCHANT_REFERENCE` (a Hub timeout, `NO_RESPONSE`) arrives
as `Failed` (`Timeout` or `ConnectionLost`), never as `Declined`. An empty or unreadable Hub answer,
an HTTP 5xx with nothing readable, and every network failure is an unknown outcome. An HTTP error
that explains itself is read as the Hub wrote it, as in the Windows SDK.

Inquiries return `Found` (with `transaction`), `NotFound` (with `reason`) or `InquiryFailed`.
`Found` means a record came back, not that money moved: read `transaction->status`
(`isApproved()`, `isDeclined()`), ignoring case. A declined sale the terminal knows is `Found` with
status `Declined`. On `Found`, `transaction->stan` is the receipt number; `canVoid`/`canRefund` say
what the terminal would accept now; with `partialApproval`, `amount` was asked and
`authorizedAmount` charged.

## 11. Resolve an unknown outcome

`Failure->outcomeUnknown()` means **the payment may have completed.** Inquire by the merchant
reference of that attempt. Charge the order again only after `transaction->status` is `Declined`, or
after the terminal confirms the attempt did not complete.

1. Save the merchant reference before calling `sale()`, `void()` or `refund()`.
2. Wi-Fi and USB: read `Failed->recovered` first. With `autoInquireOnFailure` on (the default), the
   SDK already asked once. `Found` settles it. `NotFound`, `InquiryFailed` or `null` leaves it
   unknown. Web Service sets `recovered` to `null`.
3. Call `$session->inquireByReference($reference)`.
4. Read `Found->transaction->status`, ignoring case:
   - `Approved`: the attempt completed (for a void or refund: the void or refund completed).
   - `Declined`: it did not. A new attempt needs a **new** reference.
   - Anything else (`Voided`, `Reversed`, ...): leave it unresolved and check with Amwal.
5. `NotFound` after a lost answer means only that this lookup found nothing; the same answer follows a
   terminal reset or an aged-out record. Confirm on the terminal or with Amwal before taking payment
   again.
6. `InquiryFailed`: the lookup itself failed. Inquire again; it moves no money.
7. On start-up, `$session->reconcile()` inquires every attempt still unresolved on that terminal.

`Failure\Unreachable` means the connection was never made. There is no attempt to look up.

With the guard on, the SDK records the outcome of each step (section 12): an `Approved` or `Declined`
inquiry settles the entry; when an operator settles it another way, record how:

```php
$session->markResolved('ORDER-1001-1', 'Terminal batch report 2026-10-25: not charged (ticket 4711)');
```

## 12. The financial guard

PHP serves each web request in its own process, so the rules a desktop till keeps in memory have to
live on disk. The Windows SDK has no guard, so it is off by default; turn it on with
`EcrConfig(guardFinancialOperations: true)` and it covers every sale, void and refund:

| Rule | What the caller sees |
|---|---|
| One financial transaction per terminal at a time (all processes on the machine) | `Failed` + `Failure\Busy`, nothing sent |
| A merchant reference is sent to a terminal once | `DuplicateMerchantReferenceException`, nothing sent |
| No new money movement while an earlier outcome is unknown | `Failed` + `Failure\Unresolved` listing the references, nothing sent |
| The reference is written down before the request leaves | A process killed mid-wait leaves a `pending` entry that blocks until reconciled |

Inquiries, receipts, close receipt, settlement and reachability are never blocked. The journal holds
references, types, amounts in minor units and outcomes, never card data or keys. Settled entries are
kept 90 days.

```php
$session->pendingOutcomes();   // list<JournalEntry>: reference, type, state, amountMinorUnits, failure, note
$session->reconcile();         // inquire each; returns [reference => EcrInquiry]
```

- Point `stateDirectory` at a durable folder only the PHP user can write
  (`icacls C:\ProgramData\MyTill\ecr /inheritance:r /grant:r "IIS AppPool\MyTill:(OI)(CI)M"`).
- Several servers driving the same terminal: implement `Safety\TransactionJournal` and
  `Safety\TerminalLock` over your database or Redis, and pass the guard when opening:
  `EcrSessions::open($serial, $plan, guard: new FinancialGuard($journal, $lock))`.
- Leave it off only when your own system enforces the same rules.

## 13. References, receipt numbers, amounts and dates

**Merchant reference.** At most 32 characters of printable ASCII, with space, `&` and `=` excluded;
trimmed. One per attempt; an order number plus an attempt suffix fits (`ORDER-1001-1`). A void and a
refund each get their own. Blank: the SDK generates 12 hex characters and returns it on the result,
but then you cannot save it before sending; prefer `EcrSessions::newMerchantReference()`.

**Receipt number (STAN).** As the Windows SDK: anything that is not a digit is dropped and the rest is
sent padded (`24` → `000024`); only a blank one is refused.
`Approved` does not carry it: read `Found->transaction->stan` from an inquiry by reference, or the
printed receipt. Unique on one terminal for one day. Pass that receipt's day as `yyyyMMdd` to refund,
inquiry by receipt number and e-receipt.

**Amount.** Major units as a string (`'1.234'`), a whole number (`5`) or a float (read through its
shortest exact form, so `1.005` stays `1.005`); `Amount::of()` for a value object. Rounded half away
from zero to `minorUnitDigits`, sent as minor units padded to 12 digits, as the Windows SDK's
`decimal`. The amount is not judged: zero and negative amounts are sent and the terminal or Hub
answers them. Only text that is not a number is refused. OMR keeps three decimals.

**Currency.** `currencyCode` is the ISO 4217 numeric code (`512` OMR, `840` USD) with
`minorUnitDigits` to match (0 to 4).

**Dates.** `yyyyMMdd`, sent as given. Pass one for a refund; optional for inquiries and receipts.
`EcrTransaction::day()` turns `transactionTime` into one.

## 14. Timeouts, and long waits in PHP on Windows

| Transport | Wait |
|---|---|
| Wi-Fi | connect 10 s, then answer 120 s. A lost answer can add one more wait for the automatic inquiry. |
| USB cable | The till waits 120 s for the answer, the same as Wi-Fi. |
| Web Service through `plan()` | A `responseTimeout` of 120 s becomes `EcrConfig::WEB_SERVICE_RESPONSE_TIMEOUT` (130 s); any other value is kept. One HTTP deadline of connect + answer: **140 s** with the defaults. |
| Web Service `checkReachability()` | `probeTimeout` (3 s) for the whole call. |

`new EcrWebServiceTerminal(...)` skips the 130 s raise: set
`responseTimeout: EcrConfig::WEB_SERVICE_RESPONSE_TIMEOUT` on that config.

The wait covers card presentation and PIN. The SDK holds the PHP request open for all of it, so the
web server must not end the request first. A request killed mid-wait leaves an unknown outcome; a
request that already reached the terminal can still complete.

- **`max_execution_time`.** On Windows it counts wall-clock time. The SDK raises it before each payment
  wait (`extendTimeLimit`); if `set_time_limit` is disabled, raise it in php.ini.
- **IIS + FastCGI.** Raise `activityTimeout` and `requestTimeout` for the PHP handler to at least
  300 s (`%windir%\system32\inetsrv\appcmd set config -section:system.webServer/fastCgi
  /[fullPath='C:\php\php-cgi.exe'].activityTimeout:300`), and the site's connection timeout.
- **Apache (mod_fcgid / XAMPP).** `Timeout 300`, and `FcgidIOTimeout 300` with mod_fcgid.
- **Reverse proxies and load balancers.** Idle timeout above 180 s.
- **PHP sessions.** A PHP session is locked for the whole request: call `session_write_close()` before
  `sale()`, or every other request from that browser waits behind the payment.
- **Browser gives up.** The SDK sets `ignore_user_abort(true)` during the wait, so a closed tab does
  not kill the payment; the result is still recorded in the journal.
- **Windows Firewall.** Allow outbound TCP to the terminal's port; set the Wi-Fi network profile to
  Private.
- **USB cable.** Only one process can hold a COM port. Run cable payments from one worker per
  terminal, or turn the guard on so a second request gets `Busy` instead of "access denied".

Run long payments from a CLI worker or queue if your web stack cannot hold a request for 140 s; the
journal and lock work the same from any process.

## 15. Keys and logging

**Keys.** Even-length hex, at least 16 characters. Building an `EcrConfig` with an invalid key throws
`InvalidArgumentException`; pass operator-typed text as `rawSecureHashKey` to `plan()` and the same
problem is listed in `issues()`. `open()` requires a key; `new EcrTerminal(...)` and
`new EcrWebServiceTerminal(...)` with an empty key send unsigned requests and skip the answer check.

Two secrets: Wi-Fi and USB sign `secureHash` with the Wi-Fi key; Web Service signs `secureHashValue`
with the Web Service key. `$plan->signingKeyLabel()` names the one a plan expects
(`Wi-Fi/CABLE secure hash key` or `Web Service secure hash key`). Keep them out of source control:
environment variables, the Windows Credential Manager/DPAPI, or a secrets vault. `EcrConfig` never
prints its key (`__toString`, `var_dump`, `print_r`), refuses to be serialized, and hides the key from
stack traces on PHP 8.2+ (`#[SensitiveParameter]`).

**Logging.** Off until you pass a logger. `EcrLogger::none()` discards every line.

```php
$session = EcrSessions::open($serial, $plan, EcrLogger::from(fn (string $line) => error_log($line)));
$session = EcrSessions::open($serial, $plan, EcrLogger::psr($monolog));   // any PSR-3 logger
```

Lines carry the request and answer payloads (masked PAN, amount, reference) and the signing steps
(`[Wi-Fi/CABLE]`, `[Web Service]`); the key appears only as `configured`. Every line also passes
through a redactor that masks any Luhn-valid card number and the values of PIN, CVV, track, token and
password fields. Treat the log as a transaction record. An exception thrown by your logger escapes the
SDK call.

## 16. When you build the message yourself

`EcrTransactionType` is `Sale`, `Void`, `Refund`, `Inquiry`, `Receipt`, `CloseReceipt`, `Settlement`.
`EcrTransactionType::menuOptions()` is the operator menu: sale, void, refund, inquiry (receipt, close
receipt and settlement are absent by design). `requiresAmount()`, `requiresOriginalStan()`,
`requiresOriginalDate()`, `movesMoney()` and `allowsOtherTerminal()` say which fields that operation
needs. `EcrTerminal::run()` accepts only the three that move money.

`WebService\WebServiceRequests` builds each Hub body for a caller that posts the JSON itself, and signs
it when you pass the key: `sale`, `void`, `refund`, `transactionStatus`, `closeReceipt`, `settle` and
`checkReachability`. Settle and check-reachability share one body: `version`, `merchantId`,
`terminalId`, `terminalSerial`, `ecrId`, `merchantReference`, `currencyCode`, `transactionDateTime`,
`requestDateTime`, `secureHashValue`. The session methods do this themselves.

```php
$body = WebServiceRequests::settle(10001, 20002, 'SN-WS-0001', secureHashKey: $webServiceKey);
```

## 17. Building the till's screens

- Offer **Inquire by reference**, never **Retry**, on an unknown outcome. Keep the reference on screen.
- Show a countdown sized to the wait (120 s Wi-Fi and USB, 130 s Web Service); do not impose a
  shorter one.
- **Close receipt** is its own button on a completed sale, void or refund. Dismissing a dialog does
  not send it.
- **Settlement** is its own button behind a confirmation ("Close today's batch?"). Not a menu choice
  next to sale.
- For a USB cable terminal, show the ports from `SerialPorts::listFor()` and store `selected`.
- On `Approved` with `partialApproval`, show both amounts and collect the difference.
- On start-up, run `reconcile()` and show what is still unresolved.
- The example till in `examples/` does the payment flow; see [examples/README.md](../examples/README.md).
