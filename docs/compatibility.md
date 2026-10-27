# Supported versions

What `amwalpay/ecr-php` 1.0.0-rc.1 supports, and what has actually been verified. "Supported"
means the code targets it and CI runs it; "verified" means it has been run and recorded. SIT
evidence (real terminals and the real Hub) is still to be collected: see [release.md](release.md).

## PHP

| PHP | Status |
|---|---|
| 8.1 | Supported (minimum). `#[SensitiveParameter]` is ignored on 8.1, so keys can appear in stack traces when `zend.exception_ignore_args=Off`; keep it `On` (the production default). |
| 8.2 | Supported. **Verified**: full suite, Windows 11 Pro 10.0.26200, PHP 8.2.12 ZTS x64 (XAMPP), cURL 8.4.0, OpenSSL 3.0.11. |
| 8.3 | Supported (CI). |
| 8.4 | Supported (CI). |
| < 8.1 | Not supported: enums and readonly properties are required. |

Extensions: `curl`, `json`, `openssl` (required); `ffi` (USB cable on Windows only: `extension=ffi`,
and `ffi.enable=true` under a web server); `sockets` (optional: lets the tests simulate a TCP reset). Thread-safe (ZTS) and non-thread-safe (NTS) builds both work: the SDK keeps no shared state
between requests except its journal and lock files.

## Windows and web servers

| Platform | Status |
|---|---|
| Windows 11 (x64) | Supported. **Verified** (see PHP 8.2 above). |
| Windows 10 1809+ (x64) | Supported. |
| Windows Server 2016, 2019, 2022 (x64) | Supported. |
| IIS 10 + FastCGI, Apache 2.4 (XAMPP, mod_fcgid or mod_php), PHP CLI workers | Supported; raise the request timeouts as in the [integration guide](integration-guide.md#14-timeouts-and-long-waits-in-php-on-windows). |
| Linux, macOS | Work (CI runs Ubuntu), not part of the approved Windows scope. |

File locking uses `flock`, which on Windows is `LockFileEx`: the state folder must be on a local
disk, not a network share.

## Terminal and backend

| Component | Supported |
|---|---|
| ECR message format | `version` 1. Answers declaring a higher version are refused (outcome unknown). |
| Terminal firmware | Any POS app version that speaks the Wi-Fi ECR format of `AmwalPay.Ecr` 1.0.0-1.0.2 (signed, unified envelope; legacy flat and `ecrResponse` shapes are read too). `CLOSE_RECEIPT` needs the firmware that `AmwalPay.Ecr` 1.0.2 targets; older firmware answers `CloseReceiptRefused`. Exact firmware numbers to be recorded during SIT. |
| Terminal ECR mode | Wi-Fi (`ecrMode: 2`), USB cable (`ecrMode: 1`, the MediaTek USB VCOM serial port at 115200 8N1), Web Service (`ecrMode: 4`). Bluetooth is not offered, as in the Windows SDK. |
| Web Service contract | POS-552 (WebIntegration), as implemented by `AmwalPay.Ecr`: `/Ecr/Sale`, `/Ecr/Void`, `/Ecr/Refund`, `/Ecr/Inquiry`, `/Ecr/closeReceipt`, `/Ecr/settle`, `/Ecr/CheckReachability`. |
| Hub environments | SIT, UAT, PROD (addresses as in `AmwalPay.Ecr` 1.0.2/1.0.1; UAT changed in 1.0.1). |
| Settlement | `SETTLEMENT` (Wi-Fi, USB) and `/Ecr/settle` (Web Service), read as the Windows SDK reads them (`settlementStatus` `SETTLED`, `NO_TRANSACTIONS`, other). Firmware without it answers `NotSettled`. |
| USB cable on Windows | **Verified** against a real COM port (open, 115200 8N1 set-up, read deadline, write, close) on the machine above with PHP 8.2.12 + FFI; not yet against a terminal. |

## Compatibility matrix for SIT

Fill one row per combination exercised in SIT; attach the evidence (logs with masked PAN, terminal
receipts) to POS-573.

| Windows | PHP | Web server | Terminal model / firmware | Mode | Environment | Approved | Declined | Timeout → reconciled | Lost answer → reconciled | Result |
|---|---|---|---|---|---|---|---|---|---|---|
| | | | | Wi-Fi | n/a | | | | | |
| | | | | USB cable | n/a | | | | | |
| | | | | Web Service | SIT | | | | | |
