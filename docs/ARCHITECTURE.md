# Architecture

## Design goals

The plugin must work without an Ashbi SaaS dependency. Store data remains in
WordPress/WooCommerce. Optional remote monitoring must be explicit, documented,
minimal, and disabled by default.

## Module boundaries

- `Domain`: plans, subscriptions, billing schedules, lifecycle state machine.
- `WooCommerce`: products, carts, orders, refunds, HPOS-safe persistence.
- `Payments`: gateway adapters, token references, webhook normalization.
- `Jobs`: renewal creation, charging, retries, reminders, expiry processing.
- `Admin`: plans, subscriptions, settings, audit trail, diagnostics.
- `Customer`: account portal, payment method changes, pause/cancel/renew.
- `API`: authenticated REST resources with stable versioning.
- `Reporting`: MRR, churn, recovery, failed-payment and cohort calculations.
- `Updates`: signed release metadata with no client-embedded GitHub credential.

## Persistence strategy

Phase 1 preserves upstream post types, table names, metadata keys, hooks, and
statuses to avoid corrupting existing subscriptions. New names are introduced
behind adapters. A later migration may normalize storage only after dual-read,
dry-run, backup, rollback, and reconciliation tests exist.

## Subscription lifecycle

Canonical states: `pending`, `trialing`, `active`, `past_due`, `on_hold`,
`pending_cancel`, `cancelled`, `expired`, and `failed`. State changes must be
validated, timestamped, auditable, and safe to replay.

## Renewal flow

1. Every renewal entry point atomically claims `(subscription_id, billing-period)` in `subscrpt_renewal_claim`; a five-minute lease permits recovery only before an order is attached.
2. The service validates status, due date, gateway, and payment token.
3. A renewal order is fully prepared, atomically attached as the period's canonical order, and recorded in the unchanged legacy relation history.
4. The gateway adapter reconciles the canonical order's existing remote payment object, then requests payment with stable per-period idempotency metadata without handling raw card data.
5. Signed webhook events reconcile authoritative payment state; PayPal lifecycle delivery is ordered with full-precision event and remote status-update timestamps.

Canonical subscription posts and internal item posts are not exposed through the
generic WordPress REST posts controller. Administrative plan APIs use the
versioned plugin controller and WooCommerce management authorization.

Guest checkout never treats possession of an existing billing email as account
ownership. Existing-email orders remain guest-owned unless that exact customer
is already authenticated, isolating account records and saved payment methods.
6. Success advances and marks the exact order's schedule under a database lock before activation; persistence failure remains fail-closed in durable claim state, enters Action Scheduler, and is independently swept by the hourly repair job.
7. Every step writes a redacted audit event.

## Update delivery

Use versioned ZIP releases and a signed update manifest served from an
Ashbi-controlled endpoint. Private GitHub credentials must never be embedded in
client sites. Updates require signature/hash verification and rollback support.
