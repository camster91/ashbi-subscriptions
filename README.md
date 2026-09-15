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
client site. Docker-backed WordPress/WooCommerce tests, clone-based migration
rehearsals, gateway sandbox validation, and per-site reconciliation remain
release gates.

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
npm run env:start
npm run test:integration
npm run env:stop
bash scripts/build-release.sh
```

The release ZIP has the legacy-compatible `subscription/` root and excludes
the disposable integration endpoint under `plugin/tests/`.

## Documentation

- [Implementation plan](docs/IMPLEMENTATION_PLAN.md)
- [Feature matrix](docs/FEATURE_MATRIX.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Security and payments](docs/SECURITY.md)
- [Legal provenance](docs/LEGAL_PROVENANCE.md)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
