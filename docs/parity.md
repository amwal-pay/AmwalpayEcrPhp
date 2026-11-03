# Parity with the Windows SDK

`amwalpay/ecr-php` 1.0.0-rc.1 is a port of `AmwalPay.Ecr` (.NET,
<https://github.com/amwal-pay/AmwalpayEcrWindows>) at repository commit `789f6b9` (2026-10-29), whose
README names package 1.0.3: USB cable over the serial COM port and the USB accessory channel,
settlement (`SETTLEMENT` and `/Ecr/settle`) and the Web Service reachability check
(`/Ecr/CheckReachability`). The example till follows `AmwalpayEcrWindowsExample` at `c1065b5`. This page lists
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
- USB accessory: the Android Open Accessory handshake and identity strings, the accessory ids, the
  order interfaces are tried, the reconnect attempts, stale bytes discarded and other nonces skipped
  (`UsbAccessoryEcrChannel`).
- Web Service answers are read as `ReadAsStringAsync` reads them: no byte order mark, an invalid byte
  becomes U+FFFD. One deadline covers connect and answer.
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
| `UsbAccessoryEcrChannel` | `Channel\UsbAccessoryEcrChannel` (buses in `Usb\`) |
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
| 10 | **The USB accessory channel does not install a driver.** `Channel\UsbAccessoryEcrChannel` is ported, over WinUSB and libusb through FFI, but .NET's `WinUsbDriverSetup` is not: it adds a self-signed certificate to the machine's trust stores and runs `pnputil` behind an elevation prompt. Install the driver Amwal provides. **The native buses have not been run against a real terminal**: see [compatibility](compatibility.md). | A PHP process, under a web server especially, has no desktop to show the prompt on, and a payment SDK should not change a machine's certificate trust on its own. |
| 11 | **On Windows the COM port is driven through PHP's FFI extension** (`kernel32`), which must be enabled (`extension=ffi`, `ffi.enable=true` under a web server). Without it the cable reports `Unreachable` and sends nothing. Linux and macOS use `stty` on the open descriptor. | PHP's streams cannot put a deadline on a COM read; a payment wait without one could hang a till. |
| 12 | **A cable error during or after the write is `ConnectionLost`** (outcome unknown, inquiry), on the COM port and the accessory channel. A failure to open, set up or drain the port before the write is `Unreachable`. .NET reports some of these (e.g. "The port is closed.") as `Unreachable`, a write timeout or a port that refuses access escapes as an exception, and a non-object frame escapes too (`Malformed` here). | Once bytes may have left, the terminal may have acted; never report that as "nothing was sent". |
| 13 | **`usbChannel` is the last parameter of `EcrSessions::open()`** (after `logger`, `httpTransport`, `guard`); .NET has it third. Pass it by name in both. | Keeps existing PHP callers that pass `$logger` positionally working. |
| 14 | **A cable terminal's guard key is `cable\|<port>\|0`**, a Wi-Fi one `wifi\|<host>\|<port>`. | Only used by the opt-in guard; .NET has no guard. |
| 15 | **The Windows "port does not exist" / "access denied" message is chosen by whether Windows lists the port**, not by the Win32 error code. Likewise the WinUSB pipe does not read "device gone" from the error code: the channel lists the bus once a second while it waits. | PHP calls Windows between two FFI calls, so `GetLastError()` is not reliable. |
| 16 | **Web Service redirects are not followed.** .NET's `HttpClient` follows them (307/308 POST again). A Hub answering a redirect is `Failed` here, with an inquiry to resolve it. | A signed payment is not re-sent to an address nobody configured. Pass an `HttpTransport` to change this. |
| 17 | **Proxy and certificates come from PHP, not Windows.** `HTTPS_PROXY` and `curl.cainfo` (or `caBundlePath`), not the WinHTTP proxy and the Windows certificate store. | cURL does not read them. See [troubleshooting](troubleshooting.md#web-service). |
| 18 | **A non-UTF-8 `charset` on a Web Service answer is not converted**: bytes that are not UTF-8 become U+FFFD. cURL may also negotiate HTTP/2, where a status has no reason phrase, so a message can read `HTTP 502` for `Bad Gateway`. | The Hub answers in UTF-8; only the text of a field or message could differ. |
| 19 | **Windows cable discovery reads the registry through `reg.exe`.** With `proc_open` disabled no port is listed, so a cable reads as not plugged in, where .NET's `GetPortNames()` still works. Likewise the machine's time zone is read through `tzutil`/PowerShell: with `shell_exec` disabled it falls back to `date.timezone`, so set `EcrConfig` `timezone` there. | PHP has no API for either. |
| 20 | **Small things.** The stale-byte drain on the COM port always waits at least 50 ms (.NET only takes what is buffered). `SerialPorts::resolve($saved, null)` asks the machine. Emoji travel as raw UTF-8 and U+2028/9 as escapes in a request (same value and signature, different byte count). Linux port listing does not filter phantom `ttyS*`. An `authorizeAmount` outside what a `decimal` holds is shown as text. | PHP streams and types; none of these changes an outcome on a real terminal. |
