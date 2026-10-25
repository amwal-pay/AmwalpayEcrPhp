# Amwal ECR SDK for PHP

`amwalpay/ecr-php` drives an Amwal POS terminal from a PHP till: sale, void, refund, inquiry,
e-receipt and close receipt. **Wi-Fi/LAN** talks to the terminal's ECR port on the shop network.
**ECR Web Service** talks HTTPS to the Amwal Hub. Card data stays on the terminal.

It has the same protocol, signing, result semantics and payment-safety rules as the Windows SDK
`AmwalPay.Ecr` 1.0.2 (.NET), and passes the same known-answer vectors. PHP-specific additions
(cross-process terminal lock, merchant-reference journal, amounts as strings) are listed in
[docs/parity.md](docs/parity.md).

Version **1.0.0-rc.1**: SIT release candidate. See [docs/release.md](docs/release.md) before using it in production.

| | |
|---|---|
| Integration guide | [docs/integration-guide.md](docs/integration-guide.md) |
| API reference | [docs/api-reference.md](docs/api-reference.md) |
| Troubleshooting | [docs/troubleshooting.md](docs/troubleshooting.md) |
| Supported versions | [docs/compatibility.md](docs/compatibility.md) |
| Wire protocol | [docs/protocol.md](docs/protocol.md) |
| Runnable example | [examples/README.md](examples/README.md) |
| Release and rollback | [docs/release.md](docs/release.md) |

## Install

PHP 8.1 or later with `curl`, `json` and `openssl` (Windows builds of PHP ship all three).

```powershell
composer require amwalpay/ecr-php:1.0.0-rc.1
```

Pin the exact version. Until the package is published, install the built artifact; see
[Install from the artifact](docs/integration-guide.md#install-from-the-artifact).

## Open a session

Open one session per terminal and keep it. Every operation goes through it.

### Wi-Fi

The terminal shows its IP address and port on its idle screen. The till and the terminal must be
on the same network.

```php
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Link\LanLink;

$plan = EcrSessions::plan(
    new LanLink('192.168.1.50', 9100),
    new EcrConfig(secureHashKey: $wifiKey, timezone: 'Asia/Muscat', stateDirectory: 'C:\\ProgramData\\MyTill\\ecr'),
);
if (!$plan->isReady()) {
    exit(implode("\n", $plan->issues()));
}
$session = EcrSessions::open('SN-WIFI-0001', $plan);
```

### Web Service

```php
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\Link\WebServiceLink;

$plan = EcrSessions::plan(
    new WebServiceLink(merchantId: '10001', terminalId: '20002'),
    new EcrConfig(secureHashKey: $webServiceKey, environment: EcrEnvironment::UAT, timezone: 'Asia/Muscat'),
);
$session = EcrSessions::open('SN-WS-0001', $plan);
```

The Hub address stays inside the SDK: choose `SIT` (default), `UAT` or `PROD`. The Wi-Fi key and the
Web Service key are two different secrets; never hard-code either.

## Take a payment

Amounts are major units as a **string** (`'1.234'`), never a float. Make the merchant reference
yourself and save it with the order **before** the call: it is how you find the payment if the
answer is lost.

```php
use AmwalPay\Ecr\Result\{Approved, Declined, Failed};

$reference = EcrSessions::newMerchantReference();   // or your own: 32 printable ASCII, no space, & or =
saveOrder($orderId, $reference);

$result = $session->sale('1.234', $reference);

if ($result instanceof Approved) {
    record($result->merchantReference, $result->amount, $result->rrn, $result->authCode);
    if ($result->partialApproval) {
        collectBalance($result->requestedAmount, $result->amount);
    }
} elseif ($result instanceof Declined) {
    show($result->reason);
} elseif ($result instanceof Failed && $result->settled()) {
    apply($result->recovered->transaction);           // Wi-Fi asked the terminal by itself
} elseif ($result instanceof Failed && $result->failure->outcomeUnknown()) {
    markUnresolved($result->merchantReference);       // inquire by reference; never resend
} else {
    show($result->failure->message);                  // nothing was sent
}
```

| Result | Meaning |
|---|---|
| `Approved` | Money moved. `amount` is what was taken or returned. With `partialApproval`, collect the difference. |
| `Declined` | The terminal answered and refused. No money moved. |
| `Failed`, `failure->outcomeUnknown()` false | Nothing was sent: unreachable, busy, an earlier unknown outcome to reconcile, or a bad configuration. |
| `Failed`, `failure->outcomeUnknown()` true | The request went out and the answer did not settle it: timeout, lost connection, unreadable or unauthenticated answer. **The payment may have completed.** |
| `Failed->settled()` | Wi-Fi only: the SDK inquired once and found the transaction (`recovered`). |
| `InvalidRequestException` | Refused before anything was sent: bad amount, reference, receipt number or date. |

## Other operations

```php
$session->void(receiptNumber: '24', merchantReference: 'ORDER-1001-V1');
$session->refund('1.000', receiptNumber: '24', transactionDate: '20261019', merchantReference: 'ORDER-1001-R1');
$session->inquire(receiptNumber: '24', transactionDate: '20261019');   // Found / NotFound / InquiryFailed
$session->inquireByReference('ORDER-1001-1');                        // after a lost answer
$session->receipt('24', '20261019');                                  // Wi-Fi: e-receipt URL for a QR code
$session->closeReceipt(originalMerchantReference: 'ORDER-1001-1');    // the cashier's Close receipt button
```

## Payment safety, enforced

- **Unknown outcome is never a decline.** Inquire by merchant reference; charge again only after the
  inquiry says `Declined`, or the terminal confirms the attempt did not complete.
- **One sale, void or refund per terminal at a time**, across all PHP processes. A second one gets
  `Failed` with `Failure\Busy`; nothing is sent.
- **A merchant reference is used once.** Reusing one throws `DuplicateMerchantReferenceException`.
- **No new money movement while an outcome is unknown.** The terminal answers `Failure\Unresolved`
  until `$session->reconcile()` (or an inquiry by that reference) settles it, or an operator records
  how it was confirmed with `$session->markResolved($reference, $note)`.
- **Every Wi-Fi answer is verified** against the till's key and the request's nonce; an answer that
  fails is `Failure\Unauthenticated` (outcome unknown), never believed.
- **Web Service waits up to 160 s** (connect + answer), longer than the Hub's own wait for card and PIN.
- **Logs never carry the key**; card numbers, PIN, CVV and tokens are masked on every line.

Run `$session->reconcile()` at start-up. Point `stateDirectory` at a durable, private folder.

## Develop

```powershell
composer install
composer test                 # unit + integration (real TCP, real cURL, child processes)
composer check:secrets        # no keys or card numbers in the repository
composer build                # dist/amwalpay-ecr-php-<version>.zip + .sha256
```

Manual settlement is not included: it waits on the POS-599 contract. USB/cable is not offered.
