# Troubleshooting

Find the symptom, then the cause. Turn on logging first
(`EcrSessions::open($serial, $plan, EcrLogger::from(fn ($l) => error_log($l)))`): the lines show
what was sent and received, with the key shown only as `configured`.

## Before anything is sent

| Symptom | Cause and fix |
|---|---|
| `plan->issues()`: *Wi-Fi/CABLE secure hash key is not configured* / *Web Service secure hash key is not configured* | The key for **this link** is empty. Wi-Fi and Web Service use different keys; check which one `signingKeyLabel()` names. |
| *Secure hash key must be an even-length hex string of at least 16 characters* | A space, a newline, a missing character, or a key that is not hex. Paste it again from Amwal. |
| *Merchant ID must be numeric* / *Terminal ID must be numeric* | Web Service IDs are plain digits. |
| *IP address must be a host name or IP address only* | Remove `http://`, a path or spaces; the port goes in `LanLink`'s second argument. |
| `InvalidArgumentException` from `new EcrConfig(...)` | The message names the setting: currency must be 3 digits (`512`), timeouts positive, timezone an identifier like `Asia/Muscat`. |
| `InvalidRequestException`: amount | Pass the amount as a string (`'1.234'`), positive, no sign, no thousands separator, no more than 12 digits of minor units. |
| `InvalidRequestException`: merchant reference | At most 32 characters, printable ASCII, no space, `&` or `=`. |
| `InvalidRequestException`: receipt number / date | Receipt number: digits, 1-6 significant. Date: a real day as `yyyyMMdd`. A refund needs the date. |
| `DuplicateMerchantReferenceException` | This reference was already sent to this terminal. If its outcome is unknown, inquire by it; never resend. Otherwise use a new reference. |
| `Failed` + `Busy` | Another sale, void or refund is running on this terminal (maybe from another browser tab or server process). Wait. Nothing was sent. |
| `Failed` + `Unresolved` | An earlier payment on this terminal has an unknown outcome. Run `$session->reconcile()`, or inquire by the reference named in the message. If the terminal keeps answering "not found", confirm with the terminal's report or Amwal, then `markResolved()`. |
| `RuntimeException`: *ECR state folder cannot be created* / *journal cannot be written* | `stateDirectory` must exist or be creatable, on a local disk, writable by the PHP user (IIS app pool identity, Apache service account). |
| *journal is corrupt and must be checked by hand* | Do not delete it blindly: it lists payments whose outcome is unknown. Read the file, reconcile each reference, then restore a valid file. |

## Wi-Fi

| Symptom | Cause and fix |
|---|---|
| `Unreachable`: *Connection timed out after 10000 ms* | Wrong IP, terminal off, or on another network. Check the IP under the card logos on the terminal; till and terminal on the same Wi-Fi; the Windows network profile is **Private**. |
| `Unreachable`: *No connection could be made because the target machine actively refused it* | The terminal is not in ECR (Wi-Fi) mode, or the port is wrong (default 9100). |
| Probe fails from IIS/Apache but works from the command line | Windows Firewall rule for the web server process, or the service runs in a network-isolated context. Allow outbound TCP to the terminal's port for `php-cgi.exe` / `httpd.exe`. |
| Every answer is `Unauthenticated`: *not signed with this terminal's key* | The till's Wi-Fi key and the terminal's key differ. Re-enter the key on the till; confirm with Amwal which key the terminal has. Each one is an unknown outcome: reconcile before taking another payment. |
| `Unauthenticated`: *belongs to a different request* | An answer to an earlier request, or a device replaying traffic. Reconcile; if it repeats, investigate the network. |
| `Timeout` after 2 minutes | The cardholder did not finish, or the terminal lost its backend connection. Outcome unknown: the SDK inquires once; reconcile if it is still unknown. |
| `ConnectionLost` | Wi-Fi dropped or the terminal restarted mid-transaction. Same as a timeout. |
| `Malformed` with *version 2* | The terminal speaks a newer ECR format. Upgrade `amwalpay/ecr-php`; reconcile the attempt. |
| `CloseReceiptRefused` | Firmware without `CLOSE_RECEIPT`. The receipt stays up; press back on the terminal. |
| `ReceiptUnavailable` *The receipt could not be generated* | The backend has not published one. Try again later; the transaction is unaffected. |

## Web Service

| Symptom | Cause and fix |
|---|---|
| `Failed` *SSL certificate problem: unable to get local issuer certificate* | PHP has no CA bundle. Download the Mozilla bundle (`cacert.pem` from curl.se), set `curl.cainfo` and `openssl.cafile` in php.ini, or pass `caBundlePath`. Never disable verification. |
| `Failed` *Could not resolve host* / *Failed to connect* | Outbound HTTPS blocked: proxy, firewall or DNS. With a proxy, set `HTTPS_PROXY` for the PHP process or pass an `HttpTransport`. This is an unknown outcome: the request may have reached the Hub before the failure. |
| `Failed` *Timed out after 2m 40s* | The Hub did not answer within 160 s. Reconcile by reference. If it happens before 160 s, something in front of PHP (IIS, Apache, proxy) has a shorter timeout. |
| `Declined` *secureHashValue mismatch* / HTTP 401 | Wrong key: the Web Service key, not the Wi-Fi key. The Hub refused the request; nothing moved. |
| `Declined` *RequestDateTimeIsRequired* or a time-related error | Check `timezone` / `date.timezone`; the server clock must be right. |
| `NotFound` right after a lost sale | The Hub has no record yet, or the date filter excluded it: inquire by reference without a date. Not proof that nothing was charged. |
| `InquiryFailed` | The lookup itself failed (network). Inquire again: it moves no money. |

## PHP and the web server

| Symptom | Cause and fix |
|---|---|
| The page errors after ~30 s, 70 s or 90 s during a sale | The web server ended the request: raise FastCGI `activityTimeout`/`requestTimeout` (IIS), `Timeout`/`FcgidIOTimeout` (Apache). The payment may have completed: reconcile. |
| *Maximum execution time exceeded* | `set_time_limit` is disabled; raise `max_execution_time` in php.ini to at least 300. |
| Other pages of the same user hang during a sale | PHP session lock. Call `session_write_close()` before the payment call. |
| `transactionDateTime` is hours off | `date.timezone` unset (PHP uses UTC). Set `timezone` in `EcrConfig`. |
| Two servers both sold on one terminal at once | The file lock only covers one machine. Implement `TerminalLock` and `TransactionJournal` over shared storage. |

## Getting help

Send Amwal: the SDK version (`AmwalPay\Ecr\EcrSdk::VERSION`), PHP version, Windows version, terminal
serial and firmware, the merchant reference, the time, and the SDK log lines for that reference. Never
send the key; the log never contains it.
