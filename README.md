# Ashbi Subscriptions

A free, open-source WooCommerce subscriptions plugin based only on the pinned,
GPL-licensed WordPress.org source identified in the legal provenance record. The initial
commercial policy is a $0 plugin license for everyone. Installation,
migration, support, monitoring, and managed updates may be offered separately.

## Status

Active pre-release hardening. The pinned public GPL source is imported, legacy
storage and hook compatibility are retained, and the current branch adds
durable renewal claims, gateway reconciliation, migration quarantine, and
security-boundary fixes. It has not been approved or deployed to a production
client site. Local disposable WordPress/WooCommerce lifecycle and admin checks
pass, including candidate installation with representative subscription/order
data and an exact database/package rollback rehearsal. Docker-backed gateway
tests, gateway sandbox validation, and per-site client-clone reconciliation
remain release gates.

## Core operating model

The plugin's core responsibility is intentionally straightforward: retain a
legacy-compatible subscription record, schedule the next billing action, and
create the corresponding WooCommerce order. WooCommerce and its configured
payment gateway remain responsible for payment tokens and payment execution;
Ashbi Subscriptions never stores raw card data.

The surrounding lifecycle, idempotency, migration, reporting, REST, Blocks,
and gateway-reconciliation code protects that core flow and preserves existing
legacy subscription data. Those safeguards are implementation layers around
order creation, not a requirement for a separate Ashbi payment platform.

## Non-negotiable boundaries

- Import only the public WordPress.org GPL package and its compatible dependencies.
- Do not import, decompile, copy, or depend on proprietary add-on code or license keys.
- Preserve upstream copyright and license notices.
- Replace upstream trademarks, logos, screenshots, upgrade links, and vendor-specific copy.
- Keep distributed derivative code GPL-2.0-or-later.
- Never deploy payment lifecycle changes without automated integration tests and a staged renewal test.

## Current release candidate

The plugin keeps the `subscription/` directory and `subscription.php` basename
so it can replace the public plugin in place without changing WordPress's plugin
identity. The first client release will be cut only after independent security
review, green CI, staging migration rehearsals, and an approved pilot.

## Local verification and packaging

```bash
composer test
composer lint:phpstan
vendor/bin/phpcs --warning-severity=0 --sniffs=WordPress.Security.NonceVerification,WordPress.DB.PreparedSQL
npm ci --ignore-scripts
npm run lint:js
npm run env:start
npm run test:integration
npm run env:stop
bash scripts/build-release.sh
bash scripts/build-release-evidence.sh dist/ashbi-subscriptions-2.0.0.zip
bash scripts/verify-release-evidence.sh dist/ashbi-subscriptions-2.0.0.zip
bash scripts/test-release-runtime.sh dist/ashbi-subscriptions-2.0.0.zip
bash scripts/test-release-runtime-matrix.sh dist/ashbi-subscriptions-2.0.0.zip
```

The release ZIP has the legacy-compatible `subscription/` root, excludes the
disposable integration endpoint under `plugin/tests/`, and is reproducible for
a fixed `SOURCE_DATE_EPOCH`. The evidence command writes a SHA-256 sidecar and
a per-file manifest next to the requested evidence prefix. The manifest also
records the source checkout, commit, branch, dirty-tree state and boundary
hashes, PHP version, build epoch, and build-script hashes; keep both files with
the private release record. The verifier checks the archive checksum, exact
member set, safe archive paths, and every recorded member hash. The release-runtime check mounts the extracted ZIP itself and mounts
the disposable integration fixture separately, so a passing source-tree test
cannot mask a packaging omission. It remains a local package/runtime check,
not a client-site migration, gateway sandbox, or rollback rehearsal.

For an isolated gateway rehearsal, set `ASHBI_EXPECTED_GATEWAYS` to the exact
gateway IDs under test (for example, `stripe,wp_subscription_paypal`). The
read-only staging preflight then fails closed if either adapter is missing,
disabled, or not classified as sandbox/offline.

## Documentation

- [Implementation plan](docs/IMPLEMENTATION_PLAN.md)
- [Feature matrix](docs/FEATURE_MATRIX.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Security and payments](docs/SECURITY.md)
- [Database migration 1.5.0](docs/MIGRATION_1.5.0.md)
- [Per-site staging rehearsal and rollback](docs/SITE_REHEARSAL_RUNBOOK.md)
- [Public release checklist](docs/PUBLIC_RELEASE_CHECKLIST.md)
- [Legal provenance](docs/LEGAL_PROVENANCE.md)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
