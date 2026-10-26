# Parity with the Windows SDK

`amwalpay/ecr-php` 1.0.0-rc.1 is a port of `AmwalPay.Ecr` 1.0.2 (.NET,
<https://github.com/amwal-pay/AmwalpayEcrWindows>). This page lists what is the same, how names map,
and every deliberate difference, so POS-573 (cross-channel parity) can be checked line by line.

## The same

- Wi-Fi framing, request fields and field order, nonce, `secureHash` composition and HMAC, answer
  verification and nonce binding, the strict JSON reading rules.
- Web Service paths, body fields and order, `secureHashValue`, the environment-to-Hub mapping.
- Result mapping: unified and legacy envelopes, `errorList` before `message`, `nextStep`
  `INQUIRE_BY_MERCHANT_REFERENCE` → `Failed` (never `Declined`), partial approvals,
  `authorizeAmount` vs `amount`, inquiry "found" meaning a transaction record is present (a declined
  sale is `Found`), Hub flat fields, correlation-only `data` → `NotFound`, receipt and close-receipt
  readers, Web Service inquiry outage → `InquiryFailed`.
- Failure classification: connect failure → `Unreachable` (nothing sent, no inquiry); reset after the
  request → `ConnectionLost` (outcome unknown, inquiry); timeout, malformed, unauthenticated → outcome
  unknown.
- Automatic inquiry by reference after a lost Wi-Fi answer, once, never a resend; never on Web Service.
- Merchant reference rules (32 printable ASCII, no space, `&`, `=`; generated as 12 uppercase hex),
  receipt numbers padded to 6, amounts rounded half away from zero to minor units, 12 digits.
- Key rules (even-length hex, at least 16), plan issues and their wording, `connectionSummary()`.
- Log wording, and the secret logged only as `configured`.

Verified by porting the .NET tests: the known-answer vectors (Wi-Fi, Web Service, RFC 4231), the
wire-shape strings, and the reading, terminal, recovery, session, config and channel cases.

## Names

| .NET | PHP |
|---|---|
| `SaleAsync(decimal, ...)` | `sale(string\|int\|Amount, ...)` (synchronous) |
| `EcrResult.Approved` / `.Declined` / `.Failed` | `Result\Approved` / `Declined` / `Failed` |
| `EcrInquiry.Found` / `.NotFound` / `.Failed` | `Result\Found` / `NotFound` / `InquiryFailed` |
| `EcrReceipt.Ready` / `.Unavailable` / `.Failed` | `ReceiptReady` / `ReceiptUnavailable` / `ReceiptFailed` |
| `EcrReceiptClosed.Idle` / `.Refused` / `.Failed` | `ReceiptClosed` / `CloseReceiptRefused` / `CloseReceiptFailed` |
| `Failure.OutcomeUnknown` | `Failure::outcomeUnknown()` |
| `Failed.Settled`, `Failed.Recovered` | `settled()`, `$recovered` |
| `EcrLink.Lan`, `EcrLink.WebService` | `Link\LanLink`, `Link\WebServiceLink` |
| `IEcrChannel`, `EcrChannelTimeout` | `Channel\EcrChannel`, `Channel\EcrChannelTimeout` |
| `EcrWebServiceRequestJson.*` | `WebService\WebServiceRequests::*` |
| `IEcrLogger`, `EcrLogger.From` | `Log\EcrLogger`, `EcrLogger::from()` / `::psr()` |
| `TimeSpan` | seconds (`float`) |
| `ArgumentException` | `InvalidArgumentException` (`Exception\InvalidRequestException`) |
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
| 1 | **Financial guard, opt-in** (`guardFinancialOperations: true`): cross-process terminal lock, reference journal, block while unknown, with failures `Busy`, `Unresolved` and `DuplicateMerchantReferenceException`. Off by default, so results match .NET. | PHP runs each request in its own process; a till that wants these rules needs them on disk. |
| 2 | **Amounts are `string`, `int` or `float`**, read as exact decimal text (a float through its shortest form); text that is not a number throws `InvalidRequestException`. | PHP has no `decimal`; .NET cannot be handed a value that is not a number. |
| 3 | **Timeouts must be positive; `timezone` is an optional override** (null, the default, is the machine's zone as .NET). | A PHP socket cannot wait a negative time. |
| 4 | **Text fields must be valid UTF-8**, or `InvalidArgumentException` before sending. | PHP strings are bytes; .NET strings are always valid text. |
| 5 | **Log lines are redacted** (Luhn-valid PANs, PIN/CVV/track/token/password fields). | Second line of defence; never changes a result. |
| 6 | **`EcrConfig` cannot be serialized**; its key is hidden from `var_dump`/`print_r` and (PHP 8.2+) stack traces. | Secrets must never leak through caches, sessions or error pages. |
| 7 | **The SDK raises `max_execution_time` and sets `ignore_user_abort`** during a payment wait. | On Windows the PHP time limit is wall-clock; a killed request is an unknown outcome. |
| 8 | **No USB/cable link, no cancellation token; calls are synchronous.** | Not applicable to PHP. |
| 9 | **`Failure\Invalid` still exists** but is no longer produced. | Results stored by earlier builds still read. |
