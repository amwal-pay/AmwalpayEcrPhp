# Changelog

Notable changes to `amwalpay/ecr-php`. The format follows Keep a Changelog 1.1.0 and the
package uses semantic versioning. Pin an exact version and read the entry before you upgrade.

## 1.0.0-rc.1 (2026-10-25)

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

- Manual settlement: blocked until the POS-599 contract is final and versioned.
- USB/cable transport.
