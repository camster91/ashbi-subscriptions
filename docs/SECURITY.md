# Security and payment requirements

## Trust boundaries

Treat checkout input, REST input, webhooks, cron requests, gateway responses,
import files, update manifests, and administrator-provided URLs as untrusted.

## Required controls

- Capabilities and nonces on every administrator mutation.
- Ownership checks on every customer subscription action.
- Strict REST permission callbacks and schema validation.
- Gateway webhook signature verification, timestamp tolerance, and replay defense.
- Idempotency keys for renewal orders, charges, refunds, retries, and webhooks.
- Escaping at output and prepared queries for all custom SQL.
- No card data or CVV storage; use gateway tokens only.
- Secret values encrypted where feasible and never exposed in HTML or logs.
- Redacted structured logs with configurable retention.
- Signed update metadata, SHA-256 verification, and rollback packages.
- Safe uninstall that never deletes subscriptions or order history by default.

## Test matrix

Test supported PHP, WordPress, WooCommerce, HPOS on/off, classic/Blocks checkout,
single/variable products, guest/registered checkout, timezone/DST boundaries,
zero-decimal currencies, tax/shipping/coupons, partial/full refunds, webhook
duplicates/out-of-order delivery, retry exhaustion, subscription cancellation,
plugin update rollback, and gateway sandbox renewals.

## Release gates

A production release requires clean static analysis, dependency audit, unit and
integration tests, a fresh disposable-site install, upgrade from the previous
release, staged renewal in gateway test mode, backup/restore verification, and
human review of the changelog and data migration.
