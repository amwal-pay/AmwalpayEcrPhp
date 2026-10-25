# Changelog

Notable changes to `amwalpay/ecr-php`. The format follows Keep a Changelog 1.1.0 and the
package uses semantic versioning. Pin an exact version and read the entry before you upgrade.

## 1.0.0-rc.1 (2026-10-25)

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
- Financial guard, on by default: one sale, void or refund per terminal at a time across
  PHP processes (`Failure\Busy`), each merchant reference used once
  (`DuplicateMerchantReferenceException`), no new money movement while an outcome is unknown
  (`Failure\Unresolved`), `reconcile()` and `markResolved()` to settle them.
- Web Service client deadline of 160 s (connect + answer) on every path.
- Example: the PHP port of the Amwal ECR web sample (same page, same API, same Core rules and
  wording), plus Wi-Fi and Web Service quickstart scripts, installing this package from its built
  artifact; signed fake terminal (`tools/fake-pos-server.php`).

### Not included

- Manual settlement: blocked until the POS-599 contract is final and versioned.
- USB/cable transport.
