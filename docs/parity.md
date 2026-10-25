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

Each one fails closed: it can turn an answer into "not sent" or "outcome unknown", never into an
approval or a decline.

| # | Difference | Why |
|---|---|---|
| 1 | **Financial guard** (cross-process terminal lock, reference journal, block while unknown). New failures `Busy`, `Unresolved`; `DuplicateMerchantReferenceException`. | PHP runs each request in its own process; the rules a desktop till holds in memory must be on disk. Required by the business rules (one transaction per terminal, unique reference, enquire before any new attempt). |
| 2 | **Web Service client deadline 160 s** on every path (.NET: 140 s via `Plan`, 130 s via the constructor). | Approved rule: 160 s, above the inner timeout ladder. |
| 3 | **Every HTTP 5xx is an unknown outcome**, even with a readable envelope (.NET returns a readable 5xx envelope as read, which can be `Declined`). | A 5xx means the Hub failed while handling the request, possibly after relaying it. **Confirm with the POS team (POS-573).** |
| 4 | **Amounts are strings or ints, never floats**; zero, negative, exponent and separator forms, and amounts that round to nothing, are refused. | PHP has no decimal type. .NET would send a negative amount malformed. |
| 5 | **Receipt numbers must be 1-6 digits** (.NET strips stray characters); **dates must be real `yyyyMMdd` days**; **refund requires the date**; Web Service void/inquiry require the receipt number. | A mistyped web-form value is refused instead of being reshaped into a request for another transaction. |
| 6 | **`currencyCode` must be 3 digits; timeouts positive; timezone a valid identifier.** | Deterministic failure at start-up. |
| 7 | **Answers declaring `version` > 1 are not read** (malformed, outcome unknown). | Mixed-version safety: never misread a newer format. |
| 8 | **`Failure\Invalid`** (outcome not unknown) for non-numeric Web Service IDs and a missing close-receipt reference (.NET: `Malformed`, outcome unknown). | Nothing was sent; nothing to reconcile. |
| 9 | **Web Service IDs must be plain digits** (`.NET long.TryParse` accepts a sign). | Negative IDs are not valid. |
| 10 | **`EcrSessions::open()` requires a non-blank serial.** | The serial names the terminal in every message. |
| 11 | **A Wi-Fi host with a scheme, path or spaces is a plan issue.** | Catches `http://192.168.1.50` typed into the IP field. |
| 12 | **Log lines are redacted** (Luhn-valid PANs, PIN/CVV/track/token/password fields). | Second line of defence; the terminal already masks. |
| 13 | **`EcrConfig` cannot be serialized**; its key is hidden from `var_dump`/`print_r` and (PHP 8.2+) stack traces. | Secrets must never leak through caches, sessions or error pages. |
| 14 | **`transactionDateTime` uses `timezone`** (or PHP's default) instead of the machine's local time. | PHP on Windows defaults to UTC when `date.timezone` is unset. |
| 15 | **The SDK raises `max_execution_time` and sets `ignore_user_abort`** during a payment wait. | On Windows the PHP time limit is wall-clock; a killed request is an unknown outcome. |
| 16 | **No USB/cable link, no cancellation token, no `RunAsync` for non-money types beyond `run()`.** | Out of scope / not applicable to synchronous PHP. |

Differences 2, 3 and 7 change what a till shows for the same Hub or terminal behaviour; agree them
with the POS team before SIT sign-off.
