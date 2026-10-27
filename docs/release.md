# Release and rollback

How `amwalpay/ecr-php` is versioned, built, rolled out to SIT and production, and rolled back.

## Identity

| | |
|---|---|
| Package | `amwalpay/ecr-php` |
| Namespace | `AmwalPay\Ecr` |
| Version | `1.0.0-rc.1` (`AmwalPay\Ecr\EcrSdk::VERSION`) |
| Repository | `https://github.com/amwal-pay/AmwalpayEcrPhp` (to be created) |
| Parity | `AmwalPay.Ecr` 1.0.2 (.NET) |
| Artifact | `dist/amwalpay-ecr-php-<version>.zip` + `.sha256` |

Semantic versioning: a patch fixes behaviour without API changes; a minor adds API; a major removes
or changes API. Any change to what a result means (an outcome that becomes unknown, a decline that
becomes a failure) is called out under **Changed** in [CHANGELOG.md](../CHANGELOG.md), with the
action an integrator must take. Integrators pin an exact version.

## Build

```powershell
composer install
composer test
composer check:secrets
composer build
```

`composer build` refuses to run unless `EcrSdk::VERSION` is a semantic version with a CHANGELOG
entry. It writes the zip (composer.json with the version stamped in, `src/`, `docs/`, README,
CHANGELOG, SECURITY) and its SHA-256. Tests, tools and examples are not in the artifact. The same
sources always produce the same archive contents.

## Roll out

1. **Review.** Code review and security review of the tagged commit (this repository, plus
   [parity.md](parity.md) deviations agreed with the POS team). CI green on Windows and Linux,
   PHP 8.1 to 8.4.
2. **Tag** `v1.0.0-rc.1`, build, and store the zip and `.sha256` with the release.
3. **SIT.** Install the stored zip (not a rebuild, not the source tree) into the example and into the
   SIT till through Composer's `artifact` repository. Run `composer verify-artifact` in the example:
   it must print the stored SHA-256 and *OK: the example runs on the built artifact*.
4. **SIT evidence**, for each row of the [compatibility matrix](compatibility.md#compatibility-matrix-for-sit),
   Wi-Fi, USB cable and Web Service:
   - an approved sale, void and refund, and a declined sale;
   - a timeout or lost answer that ends as an unknown outcome, the terminal blocking the next sale,
     and `reconcile()` settling it with the same result the terminal shows;
   - busy: a second sale on the same terminal while one is running;
   - close receipt; inquiry by receipt and by reference; settlement (settled and nothing to settle);
   - USB cable: the COM port found again after re-plugging; Web Service: check reachability;
   - the log of each, showing masked PAN only and the key only as `configured`.
   Attach it to POS-573.
5. **Release approval**, then publish the same artifact to the package registry as `1.0.0` after
   bumping `EcrSdk::VERSION` and the CHANGELOG from the RC (the RC itself is never relabelled).
6. **Production** pins `amwalpay/ecr-php: 1.0.0` exactly.

## Roll back

1. **Reconcile first.** On every till, list `pendingOutcomes()` and run `reconcile()`; settle what
   remains with the terminal's report or Amwal before any payment is retried. A rollback never
   clears an unknown outcome.
2. Pin the last verified version in `composer.json` (`"amwalpay/ecr-php": "<previous>"`) and
   `composer update amwalpay/ecr-php`, or redeploy the previous build of the till.
3. To stop using the PHP path entirely, switch the till's terminal configuration back to the previous
   integration (or disable the ECR feature) in the till's own settings.
4. Keep the `stateDirectory` contents: the journal is the record of what was sent. The previous version
   of this package reads the same files; a till that does not use this package should keep them until
   every entry is settled.

## Definition of done (from the task)

| Item | State |
|---|---|
| Code review, security review | Pending (human) |
| CI/tests green | Local: green on Windows 11 / PHP 8.2.12. CI workflow in `.github/workflows/ci.yml` |
| Package name, version, repository, support matrix, release/rollback instructions | This page, [compatibility.md](compatibility.md) |
| Exact artifact consumed by the example and verified in SIT | Example: done (`composer verify-artifact`). SIT: pending |
| Integration guide and API reference published and linked from the task | Written ([integration-guide.md](integration-guide.md), [api-reference.md](api-reference.md)); publishing and linking: pending |
| Settlement, USB cable, Web Service check reachability | Implemented to match the Windows SDK; SIT evidence pending |
