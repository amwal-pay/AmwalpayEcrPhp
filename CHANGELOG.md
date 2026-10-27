# Changelog

Notable changes to `amwalpay/ecr-php`. The format follows Keep a Changelog 1.1.0 and the
package uses semantic versioning. Pin an exact version and read the entry before you upgrade.

## 1.0.0-rc.1 (2026-10-25)

### Added (2026-10-27): what `AmwalPay.Ecr` (.NET) added after 1.0.2

No existing method changed signature or was removed. Wire formats of the existing operations are
unchanged.

- **Settlement**, which closes the terminal's current batch and prints the settlement receipt:
  - Wi-Fi / USB cable: `EcrTerminal::settlement($merchantReference)` sends `SETTLEMENT`.
  - Web Service: `EcrWebServiceTerminal::settlement($merchantReference)` posts `/Ecr/settle` with the
    identity body (no `messageType`).
  - Either transport: `EcrOpenedSession::settlement()`, and `supportsSettlement()` (always true).
  - Result family `Result\EcrSettlement`: `Settled` (batch ID, currency, times, total count, net
    amount and `SALE`/`REFUND`/`VOID`/`PRE_AUTH`/`CAPTURE` totals in major units), `NothingToSettle`,
    `NotSettled` (response code, `settlementStatus`, reason, uploaded count) and `SettlementFailed`;
    `Result\EcrSettlementTotal`.
  - `EcrTransactionType::Settlement` (`SETTLEMENT`). It is not in `menuOptions()`, and `run()` still
    accepts only the three that move money. Affected: an exhaustive `match` over the enum. Action:
    add a case.
  - Uses the full response timeout, raises PHP's time limit for the wait, is never blocked by the
    guard, and is never followed by an automatic inquiry or resend.
- **Web Service check reachability**: `EcrOpenedSession::checkReachability()` /
  `EcrWebServiceTerminal::probeReachability()` post `/Ecr/CheckReachability` with `probeTimeout` as
  the whole deadline. `probeReachability()` on a Web Service session still returns `null`, so a sale is
  never held for a Hub probe.
- `WebServiceRequests::settle()` and `::checkReachability()`, `WebServiceOperation::Settle` and
  `::CheckReachability`, and an optional `$deadline` on `WebServiceApi::postJson()`.
- **USB cable**, over the serial COM port the cable appears as ("MediaTek USB VCOM (Android)",
  115200 8N1):
  - `Link\UsbCableLink($comPort)`; `EcrSessionPlan::usesUsbCable()`; `connectionSummary()` names the
    port. The plan expects the Wi-Fi key.
  - `EcrSessions::open(..., usbChannel: fn () => new SerialEcrChannel($port))` (required for a cable
    plan) and `EcrSessions::usbCableTerminal()`.
  - `Channel\SerialEcrChannel`: one exchange per port open, stale bytes discarded, answers to another
    nonce skipped, probe without opening the port.
  - `Serial\SerialPorts` (`list()`, `listFor()`, `portFor()`, `resolve()`, `preferred()`, ...) with
    `SerialPortChoice`, `ConnectedPorts` and `SerialPortBinding`, so a till stores the terminal's USB
    serial and finds its COM port again after Windows renumbers it.
  - Windows uses PHP's FFI extension (`extension=ffi`, `ffi.enable=true` under a web server); Linux
    and macOS use `stty`. Without FFI on Windows a cable sale is `Unreachable` and nothing is sent.
  - The USB accessory (AOA over WinUSB/libusb) channel of the .NET repository is not ported: see
    [docs/parity.md](docs/parity.md), difference 10.
- `FinancialGuard::protected()` is public, so a non-financial wait can be shielded from PHP's time
  limit.

### Fixed (2026-10-26)

- `transactionDateTime` (and Web Service `requestDateTime`) is now the machine's local time, as
  the Windows SDK's `DateTime.Now`, instead of PHP's `date.timezone`. A php.ini zone that was not
  the machine's (Europe/Berlin under XAMPP, UTC when unset) put the stamp hours off and the
  terminal declined with "The request timestamp is outside the accepted window".
  `EcrConfig` `timezone` still overrides it; `EcrSdk::localTimeZone()` shows the zone read.

### Changed (2026-10-26): behaviour matches `AmwalPay.Ecr` 1.0.2 (.NET)

- **The financial guard is opt-in** (`guardFinancialOperations: false` by default), as the Windows
  SDK has none. Turn it on to keep `Busy`, `Unresolved` and `DuplicateMerchantReferenceException`.
- Amounts accept floats; zero, negative and over-12-digit amounts are sent as the Windows SDK sends
  them. Only text that is not a number is refused.
- Receipt numbers drop stray characters and are padded, dates and the original terminal ID are sent
  as given, a refund does not require a date; only a blank receipt number is refused.
- Answers are read whatever `version` they declare.
- Web Service: a readable HTTP error envelope (5xx included) is returned as read; a refused inquiry
  reads as the Windows SDK reads it; non-numeric IDs and a missing close-receipt reference are
  `Malformed` (not `Invalid`); IDs are read as `long.TryParse`; answer wait 130 s via `plan()`
  (140 s deadline), the config as given via the constructor.
- `EcrConfig` no longer checks `currencyCode`; `WEB_SERVICE_CLIENT_TIMEOUT` is removed and
  `WEB_SERVICE_RESPONSE_TIMEOUT` is 130 s. Plans no longer reject a host with a scheme or path, and
  `open()` accepts a blank serial.
- .NET string rules: Unicode `Trim()` for references, environment names and error lists; references
  counted in UTF-16 units; any decimal digit kept in a receipt number; invalid UTF-8 in a Wi-Fi
  answer becomes U+FFFD instead of a malformed answer, and an unreadable answer is quoted in full.

First release candidate, for SIT only. Not for production until code review, security review,
SIT evidence and release approval are complete (see [docs/release.md](docs/release.md)).

### Added

- Wi-Fi/LAN ECR over TCP with the terminal's framing (2-byte big-endian length + UTF-8 JSON),
  HMAC-SHA256 request signing with a 128-bit nonce, and answer verification bound to that
  nonce. Wire-compatible with `AmwalPay.Ecr` 1.0.2 (.NET): the same known-answer vectors pass.
- ECR Web Service (Amwal Hub, POS-552 contract) for SIT, UAT and PROD: `secureHashValue`
  signing, `/Ecr/Sale`, `/Ecr/Void`, `/Ecr/Refund`, `/Ecr/Inquiry`, `/Ecr/closeReceipt`.
- Operations: sale, void, refund, inquiry by receipt number, inquiry by merchant reference,
  e-receipt (Wi-Fi), close receipt, reachability probe (Wi-Fi).
- Results with the Windows SDK's semantics: `Approved`, `Declined`, `Failed` (with
  `Failure::outcomeUnknown()`), `Found`, `NotFound`, `InquiryFailed`, and the receipt and
  close-receipt families.
- `EcrSessions::plan()` / `open()`, `EcrConfig` validated on construction, `EcrLogger` with
  PAN/PIN/CVV redaction on every line.
- Financial guard (opt-in since the parity change above): one sale, void or refund per terminal at a time across
  PHP processes (`Failure\Busy`), each merchant reference used once
  (`DuplicateMerchantReferenceException`), no new money movement while an outcome is unknown
  (`Failure\Unresolved`), `reconcile()` and `markResolved()` to settle them.
- Example: the PHP port of the Amwal ECR web sample (same page, same API, same Core rules and
  wording), plus Wi-Fi and Web Service quickstart scripts, installing this package from its built
  artifact; signed fake terminal (`tools/fake-pos-server.php`).

### Not included

- Bluetooth, and the USB accessory (AOA) channel: not offered by the Windows SDK's documented API either.
