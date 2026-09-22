# Security and payment requirements

## Trust boundaries

Treat checkout input, REST input, webhooks, cron requests, gateway responses,
import files, update manifests, and administrator-provided URLs as untrusted.

## Required controls

- Capabilities and nonces on every administrator mutation.
- Ownership checks on every customer subscription action.
- Customer subscription views and actions require an authenticated owner; guest-owned records with author ID `0` must never match anonymous user ID `0`.
- Customer subscription views and actions must validate the target post type before reading or mutating any record.
- REST permission callbacks, route-ID validation, repository field allowlists, and
  explicit schemas on parameterized read routes.
- Gateway webhook signature verification, timestamp tolerance, and replay defense.
- PayPal transaction deliveries require a transaction identifier and serialize
  transaction reconciliation, renewal-order creation, and payment-state mutation
  with a bounded database lock; a busy lock returns a retryable response. Completed-sale
  event IDs are retained on the order and terminal refunded/cancelled orders are
  never reopened by a replayed completion event.
- Idempotency keys for renewal orders, charges, refunds, retries, and webhooks.
- Escaping at output and prepared queries for all custom SQL.
- No card data or CVV storage; use gateway tokens only.
- Customer payment-method changes accept only an existing WooCommerce token owned by the authenticated customer, restrict the local replacement path to Stripe-compatible gateways, and preserve the canonical Stripe customer binding. PayPal local token replacement is intentionally unsupported until a provider-managed billing-agreement reauthorization flow is integrated and tested.
- Secret values encrypted where feasible and never exposed in full in HTML or
  logs; the shared logger redacts gateway keys, token-like payment IDs, PayPal
  IDs, email addresses, credential fields, and bounds message length.
- Redacted structured logs with configurable retention. Recovery campaign events
  use a bounded, administrator-configurable retention window and aggregate-only
  report export; customer and order identifiers are never exported.
- Aggregate CSV exports prefix formula-like text cells so stored labels and
  cancellation reasons cannot become spreadsheet formulas when opened by an
  administrator.
- Release manifests, SHA-256 verification, and rollback packages. A signed
  update-manifest endpoint remains an operational follow-up, not a current
  plugin-hosted service.
- Safe uninstall that never deletes subscriptions or order history by default.
- Opt-in uninstall must use the exact plugin-owned PayPal mapping table name (`subscrpt_paypal_map`) so no gateway mapping data is orphaned when complete removal is explicitly selected.
- The authenticated `wpsubscription/v1/diagnostics` route is read-only and
  aggregate-only; it omits customer/order identifiers, payment tokens,
  webhook secrets, and administrator-provided URLs.

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
