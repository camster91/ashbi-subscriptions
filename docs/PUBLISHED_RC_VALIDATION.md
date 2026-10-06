# Exact published RC validation

The independently reviewable workflow is `.github/workflows/published-rc-validation.yml`.
It supplements, and does not replace, the source-tree CI workflow.

## Immutable target and tool provenance

- Public release: `v2.1.2-rc.1`, asset `ashbi-subscriptions-2.1.2-rc.1.zip`.
- SHA-256: `5341eb57819315157c330ec4746459e240224f70d814883226981153c767fa45`.
- Installed basename: **`subscription/subscription.php`**. No rename to `plugin/`.
- Official WordPress.org Plugin Check ZIP: `plugin-check.2.1.0.zip`.
- Plugin Check SHA-256: `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`.

Both assets were anonymously downloaded and their exact checksums verified during
implementation. The RC has 212 file members; the existing package regression
also linted 134 PHP files and verified the canonical root, package exclusions,
bootstrap inclusion, and absence of a top-level class in the main file.
Those are **archive checks, not WordPress runtime evidence**.

The runner pins these URLs and checksums in `scripts/validate-published-release.py`.
It never builds a candidate from the checkout or downloads a GitHub source archive.
Changing the target requires an explicit reviewed code change.

## Hosted jobs and acceptance

1. **Harness regressions:** locked `npm ci --ignore-scripts`, moderate-or-higher
   dependency audit, actual upgraded wp-env tooling compatibility tests, offline
   ZIP/HTTP/command/PHP fixtures, and shell syntax checks.
2. **Published ZIP / HPOS off and on:** two fresh Docker-backed wp-env environments
   with `max-parallel: 1`, a 25-minute job timeout, PHP 8.2, current WordPress and
   official WooCommerce/Stripe stable packages. WP-CLI installs and activates the
   verified RC ZIP. An external readback verifies every installed file hash,
   rejects extra/missing files and competing source activation, checks the exact
   runtime basename and actual WooCommerce HPOS datastore, then repeats after
   integration. The existing authenticated HTTP runner retains real admin login,
   REST nonces, and capability/ownership assertions. Tests are mounted externally
   at `/ashbi-integration`, because the published ZIP deliberately excludes them.
3. **Official Plugin Check / initial nonblocking findings:** a separate fresh
   package installation, after the HPOS jobs, with the official checker installed
   and activated from its own verified ZIP. The exact command is:

   ```sh
   wp plugin check subscription/subscription.php --format=strict-json \
     --fields=file,line,column,type,code,message,docs \
     --require=./wp-content/plugins/plugin-check/cli.php
   ```

   The documented pre-WordPress `cli.php` requirement enables runtime checks in
   addition to static checks. No checks, codes, warnings, or errors are suppressed;
   the checker's own default scan exclusions still apply. No AI analysis is enabled.
   `strict-json` is supported by the inspected official 2.1.0 ZIP and keeps all
   finding rows in one array, unlike ordinary `json`, which emits file headings
   and multiple JSON fragments. PCP's exact successful no-findings message is
   normalized to an empty array only with exit status zero; raw stdout remains
   available. This normalization is explicitly identified in the summary.

Package/checksum/runtime failures fail their steps. Plugin Check installation,
missing reports, or malformed reports are infrastructure failures, not ignored
findings. Finding debt is initially diagnostic: a separate `continue-on-error`
step exposes raw exit status and an errors-present policy status. PCP can return
zero despite findings; the policy status therefore also inspects `ERROR` rows.
This policy is **not a WordPress.org approval or an all-checks-clean claim**.
A later reviewed change can make that diagnostic policy blocking.

## Evidence and cleanup

Artifacts are uploaded with `if: always()` and retained for 14 days:

- Package/fixture provenance, archive member SHA-256 manifest, runtime and plugin
  versions, installed-package byte/datastore readback, integration JSON and exit
  code; failed HTTP callback stdout/stderr when available.
- Plugin Check raw stdout, stderr, raw exit status, errors-present policy status,
  normalized finding array, summary, checker/check-list metadata and provenance.

Only top-level report files are uploaded; neither Docker cache/configuration nor
WordPress databases are included. A failure before report generation can leave
only partial evidence and must not be represented as a completed check.

A generated private configuration and cache live beneath each report directory's
`owned-environment/`. The locked wp-env 11.15.0 CLI and installed parser were
inspected: `--config` is supported and its override is derived beside that custom
file. The checkout's `.wp-env.json` and user `.wp-env.override.json` are untouched.
Ownership/config checks reject an unexpected override, changed config, or a
pre-existing owned directory. Inherited wp-env cache/port overrides, an occupied
port 8888, and an unknown Docker publisher block startup rather than being stopped.

Python `finally` cleanup and an independent workflow `always()` cleanup retry
remove only the owned environment. They use **`wp-env cleanup --force`**, not
`destroy`, preserving potentially shared Docker images. Failed cleanup preserves
ownership state for retry and fails visibly; no global Docker prune is performed.

## Execute after review/push by the responsible maintainer

Pull requests automatically run the workflow. No external push, workflow dispatch,
release edit, or issue update was performed during implementation.

Once the workflow is on the repository's default branch, dispatch a reviewed ref:

```sh
gh workflow run published-rc-validation.yml --repo camster91/ashbi-subscriptions \
  --ref <reviewed-branch-or-tag>
gh run list --repo camster91/ashbi-subscriptions \
  --workflow published-rc-validation.yml --branch <reviewed-branch> --limit 5
gh run watch <run-id> --repo camster91/ashbi-subscriptions --exit-status
gh run download <run-id> --repo camster91/ashbi-subscriptions --dir <evidence-directory>
```

Do not call this gate passed until both HPOS jobs finish successfully and the
actual Plugin Check report has been reviewed. Hosted execution remains pending
at implementation handoff; local fixtures do not supply those results.

## Lightweight local regressions

After installing the locked npm and Composer development dependencies:

```sh
python3 scripts/test-published-release.py
npm run test:tooling
npm run lint:js
php vendor/bin/phpunit
php tests/compatibility-contract.php
bash -n scripts/run-integration.sh
php -l scripts/package-validation/safety.php
php -l scripts/package-validation/verify-installed.php
python3 scripts/validate-published-release.py verify --package <downloaded-exact-RC.zip>
bash scripts/test-release-package.sh <downloaded-exact-RC.zip>
git diff --check
```

During implementation the new suite passed eight tests, with explicit fabricated
ZIP/HTTP/command fixtures and WordPress PHP doubles; source PHPUnit passed 226
tests / 2,013 assertions and the compatibility contract passed 10 keys / 5 hooks.
No local Docker, wp-env server, Playground, browser, gateway sandbox, or client
site was started. Windows packaging needs task-local zip/rsync tools and MSYS
path conversion enabled only in the packaging subprocess; native arguments use
`C:/...`, while shell PATH entries use `/c/...`. Keep local jobs serialized and
set temporary-directory variables explicitly to task scratch when the inherited
shell points elsewhere.

## Safety and remaining boundaries

The disposable MU safety fixture suppresses email and blocks WordPress HTTP API
requests to Stripe/PayPal provider domains; automatic WP cron and the default
Action Scheduler queue runner are disabled. Gateways have no real credentials.
All integration customer/token/order fixtures are fabricated. This is not a
network firewall or proof that arbitrary plugins cannot use another transport.

WordPress/WooCommerce/Stripe stable URLs are intentionally moving compatibility
targets; artifacts record the versions actually executed. npm tooling, the RC,
and Plugin Check are pinned. Covered integration behavior does not certify every
checkout UI, provider transaction, webhook replay/crash window, client extension,
migration, backup/database rollback, or production billing path. Private archive,
client sites, live payments, and repository secrets are outside this pipeline.
The existing required CI/security checks remain unchanged; inherited coding-
standard debt is not repaired or hidden by this work.

Primary implementation references: the official `WordPress/plugin-check`
`README.md`, `docs/CLI.md`, and `includes/CLI/Plugin_Check_Command.php`, plus the
locked `@wordpress/env` CLI/config/cleanup source. Read them before altering the
runtime-bootstrap, report-format, config ownership, or cleanup contract.
