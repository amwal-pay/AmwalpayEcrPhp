# Security

## Reporting

Report a vulnerability privately to the Amwal Pay security contact, not in a public issue. Include the
SDK version (`AmwalPay\Ecr\EcrSdk::VERSION`) and steps to reproduce. Never include a real key, card
number or customer data.

## What the SDK protects

- **Requests** to the terminal are signed (HMAC-SHA256, hex-decoded shared key) and carry a 128-bit
  nonce, so a captured request cannot be replayed and a device on the network cannot issue one.
- **Answers** from the terminal are believed only when signed with the same key and bound to the
  request's nonce; anything else is an unknown outcome, never an approval.
- **Web Service** uses HTTPS with certificate and host-name verification always on, and signed bodies.
- **Unknown outcomes are never declines**, and the financial guard blocks new money movement until
  they are reconciled, so a lost answer cannot become a double charge.
- **Secrets**: `EcrConfig` never prints its key, cannot be serialized, and hides it from stack traces
  on PHP 8.2+. Logs show the key only as `configured`.
- **Card data**: the terminal sends only masked PANs; every log line is additionally passed through a
  redactor that masks Luhn-valid card numbers and PIN, CVV, track, token and password fields.

## What the integrator must do

- Keep keys out of source control, logs, URLs and front-end code; load them from the environment or a
  secrets store.
- Make `stateDirectory` private to the PHP process, on a local disk.
- Keep `zend.exception_ignore_args = On` in production (especially on PHP 8.1).
- Protect any page that can start a payment with authentication; the example web till is loopback-only
  and has none.
- Treat SDK logs as transaction records.

## Known limitation of the agreed format

The Wi-Fi signature covers top-level fields only; nested `data` (amounts, RRN, masked PAN in the answer)
is not signed. The till acts on `success`, `message` and `nonce`, which are. This matches the terminal
and the Windows SDK.

The repository is checked for keys and card numbers by `tools/check-secrets.php` in CI.
