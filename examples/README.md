# Amwal ECR - PHP web sample

The PHP port of the Amwal ECR web sample
([AmwalpayEcrWindowsExample / AmwalECR-Web-Example](https://github.com/amwal-pay/AmwalpayEcrWindowsExample)):
register POS terminals, key an amount in the browser, pick a terminal, run a sale, void, refund or
inquiry over **Wi-Fi** or the **ECR Web Service**. The page is the web sample's page, file for file
(plain HTML/CSS/ES modules, no build step, no CDN). Behind it, the same 21-route JSON API is written in
PHP, along with a PHP port of the samples' shared Core (terminal store, form rules, transaction
engine, outcome classification, result wording), so behaviour and wording match the Windows samples.

It installs `amwalpay/ecr-php` **from the built package artifact** (`../dist/*.zip`) through Composer,
never from the SDK's source folder, so it runs exactly what ships.

- How it is built: [docs/implementation.md](docs/implementation.md)
- SDK copy-paste snippets: [cli/wifi-quickstart.php](cli/wifi-quickstart.php), [cli/webservice-quickstart.php](cli/webservice-quickstart.php)

> **This sample has no authentication.** Anyone who can reach it can take payments and replace
> signing keys. It accepts loopback host names only; read [Security](#security) before changing that.

## Run it

Needs PHP 8.1+ (`curl`, `json`, `openssl`) and Composer. From the SDK folder:

```powershell
composer build                 # dist/amwalpay-ecr-php-1.0.0-rc.1.zip
cd examples
composer install               # installs that zip (and bacon/bacon-qr-code for the e-receipt QR)
composer verify-artifact       # "OK: the example runs on the built artifact"
composer serve                 # php -S 127.0.0.1:5080 -t web/public web/public/index.php
```

Open <http://127.0.0.1:5080>. A second instance on another port and data folder:

```powershell
$env:AMWAL_ECR_EXAMPLE_DATA = "$env:TEMP\ecr-scratch"
php -S 127.0.0.1:5091 -t web/public web/public/index.php
```

## Configure it

| Setting | Default | Override |
|---|---|---|
| Address | `127.0.0.1:5080` (`composer serve`) | the `php -S` address, or your web server |
| Allowed hosts | `127.0.0.1;localhost;[::1]` | `AMWAL_ECR_EXAMPLE_ALLOWED_HOSTS` |
| Data folder | `%LOCALAPPDATA%\AmwalECR-PHP-Example` (`~/.local/share/...` elsewhere) | `AMWAL_ECR_EXAMPLE_DATA` |
| Time zone | php.ini `date.timezone`, else `Asia/Muscat` | `AMWAL_ECR_EXAMPLE_TIMEZONE` |
| PHP CLI for workers | the running PHP (built-in server), or `php.exe` next to `php-cgi.exe` | `AMWAL_ECR_EXAMPLE_PHP` |

The data folder holds `terminals.json`, `settings.dat` (environment and both signing keys,
encrypted), `keys/` (the key that decrypts it), `runs/` (transactions the page polls), `log.txt`
(the last 500 SDK lines) and `sdk-state/` (the SDK's terminal lock and merchant-reference journal).

In the UI, keys, environment and terminals are set on the **Terminals** screen: a *Wi-Fi secure hash
key* card, a *Web Service* card (environment `SIT`/`UAT`/`PROD` and the Web Service key), and the
terminal list. Terminal records hold addresses only (Wi-Fi: IP and port; Web Service: merchant and
terminal IDs), never a key. Keys are validated when saved and an invalid one is refused.

### Wi-Fi, without a terminal

The signed fake terminal verifies the till's signature and signs its answers like a real one:

```powershell
php ..\tools\fake-pos-server.php --key 0123456789ABCDEF0123456789ABCDEF --port 19100
```

Then: **Terminals** → paste the same key as the Wi-Fi key → **Add terminal** (Wi-Fi, IP `127.0.0.1`,
port `19100`) → **Transaction** → amount → **Start transaction**. Useful flags: `--decline 51`,
`--partial`, `--delay 8` (then **Cancel**, or reload the page), `--drop-sale`,
`--drop-sale --drop-first-inquiry`, `--not-found`, `--busy`, `--no-close-receipt`.

### Web Service

Calls the Amwal Hub over HTTPS (`ecrMode: 4`), not a terminal IP. You need credentials from Amwal.
Set the environment and the **Web Service** key (not the Wi-Fi key) on the Terminals screen, add a
terminal in mode Web Service (serial, numeric merchant and terminal IDs), and select it on the
Transaction screen; the config card must read ready before **Start**. There is no reachability probe
and no e-receipt; keep the merchant reference so **Inquire by reference** can resolve a lost answer.
On Windows, PHP often needs a CA bundle for HTTPS: set `curl.cainfo` in php.ini (see
[troubleshooting](../docs/troubleshooting.md#web-service)).

## What it demonstrates

Everything the Windows samples demonstrate (operations, POS-style amount entry, unknown outcomes,
inquiry, Close receipt, QR), plus:

- **Transactions run on the server and the page polls.** `POST /api/transactions` starts a run
  (`202`) in a background PHP worker process; closing the tab or dropping a request never cancels a
  payment, only the explicit Cancel does, and a reloaded page finds its running or unread transaction.
- **A countdown driven by the server**: `timeoutSeconds` (120 s Wi-Fi, 160 s Web Service) and
  `remainingSeconds`, counted on the server.
- **Cancel only stops waiting.** A cancelled sale, void or refund becomes an unknown outcome with
  **Inquire by reference**, never "cancelled".
- **Close receipt** is its own button on a completed sale, void or refund. Closing the dialog does not
  send it.
- **One running transaction per terminal**, enforced by the server (`409`), and again by the SDK.
- **Write-only keys.** The API never returns a stored key, only `configured: true/false` and its label.
- **The QR is drawn on the server** (`GET /api/qr`, SVG), so the page needs no library.
- **A merchant reference is made and stored before anything is sent**, and a worker that dies leaves
  an unknown outcome carrying that reference, not a spinner.
- Light and dark themes and a phone-width layout.

## API

JSON in, JSON out. Mutating calls (`POST`, `PUT`, `DELETE`) **must** send
`Content-Type: application/json` (`{}` if there is nothing to say). Field errors are
`422 { "errors": { "<field>": "<message>" }, "message": null }`; other errors are
`{ "error": "...", "transactionId": null }`. The contract is the web sample's, route for route.

| Route | Purpose |
|---|---|
| `GET /api/meta` | Operations (SDK flags, per-combination field layouts), modes, environments, amount limits, today |
| `GET/POST /api/terminals`, `PUT/DELETE /api/terminals/{serial}` | Terminals, keyed by serial |
| `GET /api/terminals/{serial}/config` | The config card: what the SDK plan makes of the terminal, before paying |
| `GET /api/settings`, `PUT /api/settings/environment` | Environment; never a key |
| `PUT/DELETE /api/settings/keys/{wifi\|webService}` | Replace or clear a key (write-only) |
| `GET/POST /api/transactions` | Un-acknowledged runs (newest first); start one (`202`, `422`, `409`) |
| `GET /api/transactions/{id}` | Poll the state |
| `POST /api/transactions/{id}/cancel` | Stop waiting |
| `POST /api/transactions/{id}/inquire` | Look an unknown outcome up by its reference |
| `POST /api/transactions/{id}/receipt` | Fetch the e-receipt of an inquiry result (Wi-Fi) |
| `POST /api/transactions/{id}/close-receipt` | Ask the terminal to put its receipt away |
| `DELETE /api/transactions/{id}` | The operator has read the result (`409` while running) |
| `GET /api/qr?text=` | `image/svg+xml` |
| `GET/DELETE /api/log` | The last 500 SDK log lines; clear |

Finished transactions nobody acknowledged are kept for 12 hours, then dropped.

## IIS and Apache

The built-in server is enough for one till. Under a web server:

- Point the site at `web/public`, and send every request that is not a file to `index.php`
  (IIS URL Rewrite rule, or Apache `FallbackResource /index.php`).
- Workers are started as PHP CLI processes; under php-cgi set `AMWAL_ECR_EXAMPLE_PHP` to `php.exe`.
  The app-pool identity (or Apache service account) must be able to start processes and write the
  data folder.
- Requests are short (the page polls), so the page needs no long FastCGI timeouts; the worker runs
  outside the web server's request limits.
- Bind to loopback, or set `AMWAL_ECR_EXAMPLE_ALLOWED_HOSTS` only after the steps below.

## Security

There is no authentication or authorisation. Anyone who can open the page can start payments, edit
terminals, replace or clear keys and read the debug log (masked card numbers). The defaults keep that
to the machine it runs on:

- Host filtering to loopback names (defeats DNS rebinding; another `Host` gets `400`).
- Stored keys are never sent to the browser or logged; `settings.dat` is AES-256-GCM encrypted.
- No CORS; mutating calls must be `application/json`; a request from another origin
  (`Origin` / `Sec-Fetch-Site`) is refused with `403`.
- Security headers and a strict CSP (no inline script or style); server text is rendered with
  `textContent`; bodies capped at 64 KB; errors carry no detail.
- The key in `keys/` is **not** itself encrypted: the data folder's permissions are what protect it.

### Before you expose this

Add authentication and authorisation on every endpoint (read-only ones included); TLS, with the allowed
hosts set to your host names; per-user terminal ownership; terminals in a database and keys in a
secret store; and audit logging.

## Tests

From the SDK folder:

```powershell
vendor\bin\phpunit --testsuite example
```

The tests host this app for real (PHP's built-in server, a temp data folder, worker processes, the
signed fake terminal): terminals and their messages, write-only and encrypted keys, meta, form
validation, approved / declined / partial / unreachable, lost answer recovered, lost answer plus lost
follow-up then **Inquire** on the same run (exactly one SALE), cancel = unknown outcome, `409` and 8
concurrent starts letting exactly one through, terminals side by side, receipt and QR, the log, the
security guard, and a dead worker turning into an unknown outcome with its reference.

## Known limits

- Single machine: the run registry and the SDK's terminal lock are files; two servers do not share
  them (see the integration guide for a shared journal and lock).
- The server guards a *running* run; while an *Outcome unknown* result sits unread, the SDK's journal
  (not this page) is what refuses the next sale on that terminal until it is reconciled.
- Closing the result dialog acknowledges it, including an unknown outcome; the reference stays in the
  debug log and in the SDK journal.
- USB cable and Bluetooth can be registered (to match the Android and Windows terminal lists) but not
  run: the PHP SDK opens Wi-Fi and Web Service only.
- Web Service is not exercised against a real Hub by the tests.
