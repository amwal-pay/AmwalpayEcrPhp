# Parity with the Windows SDK

`amwalpay/ecr-php` 1.0.0-rc.1 is a port of `AmwalPay.Ecr` (.NET,
<https://github.com/amwal-pay/AmwalpayEcrWindows>): release 1.0.2 plus what the repository added
after it on 2026-10-26: USB cable over the serial COM port, settlement (`SETTLEMENT` and
`/Ecr/settle`) and the Web Service reachability check (`/Ecr/CheckReachability`). This page lists
what is the same, how names map, and every deliberate difference, so POS-573 (cross-channel parity)
can be checked line by line.

## The same

- Wi-Fi framing, request fields and field order, nonce, `secureHash` composition and HMAC, answer
  verification and nonce binding, the strict JSON reading rules.
- USB cable: the same frames on the COM port at 115200 8N1 with DTR and RTS on; the port opened for
  each exchange and never just to probe; stale bytes discarded before the write; frames answering
  another nonce skipped; the COM port picker (`SerialPorts`): ordering, the Android VCOM preferred,
  the macOS twin hidden, a saved USB serial followed to its new COM number, labels as
  `COM8 · <Device Manager name> · device <serial>`.
- Web Service paths, body fields and order, `secureHashValue`, the environment-to-Hub mapping;
  settle and check-reachability share the identity body with no `messageType`.
- Result mapping: unified and legacy envelopes, `errorList` before `message`, `nextStep`
  `INQUIRE_BY_MERCHANT_REFERENCE` → `Failed` (never `Declined`), partial approvals,
  `authorizeAmount` vs `amount`, inquiry "found" meaning a transaction record is present (a declined
  sale is `Found`), Hub flat fields, correlation-only `data` → `NotFound`, receipt and close-receipt
  readers, Web Service inquiry outage → `InquiryFailed`.
- Settlement reading: `settlementStatus` decides (`SETTLED` → `Settled`, `NO_TRANSACTIONS` on success →
  `NothingToSettle`, anything else → `NotSettled`), totals in receipt order with zero rows filled in,
  amounts in major units, counts read as `int.TryParse`; a Hub outage → `SettlementFailed`
  (`ConnectionLost`).
- Reachability: Wi-Fi/USB probe before a sale, `null` on Web Service; the Hub check is its own call,
  bounded by `probeTimeout`, `reachable` only on a success envelope, `endpoint` the Hub origin.
- Failure classification: connect failure → `Unreachable` (nothing sent, no inquiry); reset after the
  request → `ConnectionLost` (outcome unknown, inquiry); timeout, malformed, unauthenticated → outcome
  unknown.
- Automatic inquiry by reference after a lost Wi-Fi or USB answer, once, never a resend; never on Web
  Service, and never after a settlement.
- Merchant reference rules (32 printable ASCII, no space, `&`, `=`; generated as 12 uppercase hex),
  receipt numbers padded to 6, amounts rounded half away from zero to minor units, 12 digits.
- Key rules (even-length hex, at least 16), plan issues and their wording, `connectionSummary()`
  (`COM8`, or `USB cable` with no port).
- Log wording (`[Wi-Fi/CABLE]`, `[Web Service]`), and the secret logged only as `configured`.

Verified by porting the .NET tests: the known-answer vectors (Wi-Fi, Web Service, RFC 4231), the
wire-shape strings (including settle and check reachability), and the reading, settlement, serial
port, terminal, recovery, session, config and channel cases.

## Names

| .NET | PHP |
|---|---|
| `SaleAsync(decimal, ...)` | `sale(string\|int\|float\|Amount, ...)` (synchronous) |
| `EcrResult.Approved` / `.Declined` / `.Failed` | `Result\Approved` / `Declined` / `Failed` |
| `EcrInquiry.Found` / `.NotFound` / `.Failed` | `Result\Found` / `NotFound` / `InquiryFailed` |
| `EcrReceipt.Ready` / `.Unavailable` / `.Failed` | `ReceiptReady` / `ReceiptUnavailable` / `ReceiptFailed` |
| `EcrReceiptClosed.Idle` / `.Refused` / `.Failed` | `ReceiptClosed` / `CloseReceiptRefused` / `CloseReceiptFailed` |
| `EcrSettlement.Settled` / `.NothingToSettle` / `.NotSettled` / `.Failed` | `Settled` / `NothingToSettle` / `NotSettled` / `SettlementFailed` |
| `EcrSettlementTotal(Count, Amount)` | `Result\EcrSettlementTotal($count, $amount)` |
| `SettlementAsync(merchantReference)` | `settlement($merchantReference)` |
| `SupportsSettlement` | `supportsSettlement()` |
| `EcrOpenedSession.CheckReachabilityAsync()` | `EcrOpenedSession::checkReachability()` |
| `EcrWebServiceTerminal.ProbeReachabilityAsync()` | `EcrWebServiceTerminal::probeReachability()` |
| `Failure.OutcomeUnknown` | `Failure::outcomeUnknown()` |
| `Failed.Settled`, `Failed.Recovered` | `settled()`, `$recovered` |
| `EcrLink.Lan`, `EcrLink.UsbCable(ComPort)`, `EcrLink.WebService` | `Link\LanLink`, `Link\UsbCableLink($comPort)`, `Link\WebServiceLink` |
| `EcrSessionPlan.UsesUsbCable` | `usesUsbCable()` |
| `EcrSessions.Open(serial, plan, usbChannel: () => ...)` | `EcrSessions::open($serial, $plan, usbChannel: fn () => ...)` |
| `EcrSessions.UsbCableTerminal(...)` | `EcrSessions::usbCableTerminal(...)` |
| `SerialEcrChannel`, `SerialPorts`, `SerialPortChoice`, `ConnectedPorts`, `SerialPortBinding` | `Channel\SerialEcrChannel`, `Serial\SerialPorts`, `Serial\SerialPortChoice`, `Serial\ConnectedPorts`, `Serial\SerialPortBinding` |
| `IEcrChannel`, `EcrChannelTimeout` | `Channel\EcrChannel`, `Channel\EcrChannelTimeout` |
| `EcrWebServiceRequestJson.*` (incl. `Settle`, `CheckReachability`) | `WebService\WebServiceRequests::*` (incl. `settle()`, `checkReachability()`) |
| `EcrWebServiceOperation.Settle`, `.CheckReachability` | `WebServiceOperation::Settle`, `::CheckReachability` |
| `IEcrLogger`, `EcrLogger.From` | `Log\EcrLogger`, `EcrLogger::from()` / `::psr()` |
| `TimeSpan` | seconds (`float`) |
| `ArgumentException` | `InvalidArgumentException` (`Exception\InvalidRequestException`) |
| `InvalidOperationException` | `\LogicException` |
| `CancellationToken` | none: PHP calls are synchronous; see the time-limit notes |

## Deliberate differences

Behaviour, results and wording follow the Windows SDK, including its leniency: receipt numbers lose
stray characters and are padded, dates and terminal IDs are sent as given, amounts are not judged,
answers are read whatever `version` they declare, a readable HTTP error envelope is returned as read,
IDs are read as `long.TryParse`, the Web Service answer wait is 130 s through `plan()`, and
`transactionDateTime` is the machine's local time (`DateTime.Now`). What remains different comes from
PHP itself, or is an opt-in that is off by default:

| # | Difference | Why |
|---|---|---|
| 1 | **Financial guard, opt-in** (`guardFinancialOperations: true`): cross-process terminal lock, reference journal, block while unknown, with failures `Busy`, `Unresolved` and `DuplicateMerchantReferenceException`. Off by default, so results match .NET. Settlement and reachability are never guarded. | PHP runs each request in its own process; a till that wants these rules needs them on disk. |
| 2 | **Amounts are `string`, `int` or `float`**, read as exact decimal text (a float through its shortest form); text that is not a number throws `InvalidRequestException`. | PHP has no `decimal`; .NET cannot be handed a value that is not a number. |
| 3 | **Timeouts must be positive; `timezone` is an optional override** (null, the default, is the machine's zone as .NET). | A PHP socket cannot wait a negative time. |
| 4 | **Text fields must be valid UTF-8**, or `InvalidArgumentException` before sending. | PHP strings are bytes; .NET strings are always valid text. |
| 5 | **Log lines are redacted** (Luhn-valid PANs, PIN/CVV/track/token/password fields). | Second line of defence; never changes a result. |
| 6 | **`EcrConfig` cannot be serialized**; its key is hidden from `var_dump`/`print_r` and (PHP 8.2+) stack traces. | Secrets must never leak through caches, sessions or error pages. |
| 7 | **The SDK raises `max_execution_time` and sets `ignore_user_abort`** during a payment or settlement wait. | On Windows the PHP time limit is wall-clock; a killed request is an unknown outcome. |
| 8 | **No cancellation token; calls are synchronous.** | Not applicable to PHP. |
| 9 | **`Failure\Invalid` still exists** but is no longer produced. | Results stored by earlier builds still read. |
| 10 | **USB cable is the serial COM port only.** The .NET repository also carries a USB accessory (Android Open Accessory) channel over WinUSB/libusb with a driver installer; its README documents only the COM port, and the PHP SDK ports that. | The accessory channel needs native USB drivers that PHP cannot load. |
| 11 | **On Windows the COM port is driven through PHP's FFI extension** (`kernel32`), which must be enabled (`extension=ffi`, `ffi.enable=true` under a web server). Without it the cable reports `Unreachable` and sends nothing. Linux and macOS use `stty` on the open descriptor. | PHP's streams cannot put a deadline on a COM read; a payment wait without one could hang a till. |
| 12 | **A cable error after the request is written is `ConnectionLost`** (outcome unknown, inquiry). A failure to open, set up or drain the port before the write is `Unreachable`. .NET reports some post-write port errors (e.g. "The port is closed.") as `Unreachable`, and a port that refuses access escapes as an exception. | After the write the terminal may have acted; never report that as "nothing was sent". |
| 13 | **`usbChannel` is the last parameter of `EcrSessions::open()`** (after `logger`, `httpTransport`, `guard`); .NET has it third. Pass it by name in both. | Keeps existing PHP callers that pass `$logger` positionally working. |
| 14 | **A cable terminal's guard key is `cable\|<port>\|0`**, a Wi-Fi one `wifi\|<host>\|<port>`. | Only used by the opt-in guard; .NET has no guard. |
| 15 | **The Windows "port does not exist" / "access denied" message is chosen by whether Windows lists the port**, not by the Win32 error code. | PHP calls Windows between two FFI calls, so `GetLastError()` is not reliable. |
