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

## Extension compatibility boundary

Ashbi Subscriptions deliberately keeps the public GPL namespace
`SpringDevs\\Subscription\\` as the canonical class namespace. The imported
source uses that namespace and its PSR-4 path is part of the public extension
surface; changing it would break extensions that use the free plugin's classes
even if the WordPress storage and hooks remained unchanged.

The plugin also loads `includes/LegacyCompat.php`, which preserves the legacy
`WP_SUBSCRIPTION_*` constants, `wp_*` helper functions, deprecated filter names,
plugin path, text domain, slugs, post types, table names, metadata keys, and
subscription hooks used by existing installations. Those identifiers are
adapters onto the Ashbi-maintained implementation, not a second runtime.

There are intentionally no aliases for the paid product's private
`SpringDevs\\SubscriptionPro\\` classes. The public GPL source does not provide
the proprietary implementation or a supported contract for those classes, so
emulating them would create a false drop-in guarantee and could expose an
extension to unsafe lifecycle or payment behavior. An extension that depends on
paid-only classes must be ported to the documented public namespace and hooks;
that port is a separate compatibility change with its own tests and release
notes.

This boundary is contract-tested in the unit suite. Namespace migration is
deferred until a future release can provide an inventory of dependent
extensions, explicit adapters, dual-read/dual-write coverage where storage is
affected, and a rollback-tested migration.

## Persistence strategy

Phase 1 preserves upstream post types, table names, metadata keys, hooks, and
statuses to avoid corrupting existing subscriptions. New names are introduced
behind adapters. A later migration may normalize storage only after dual-read,
dry-run, backup, rollback, and reconciliation tests exist.

## Subscription lifecycle

Canonical persisted subscription states are the legacy-compatible
`subscrpt_order` post statuses: `pending`, `active`, `on_hold`, `pe_cancelled`,
`cancelled`, `expired`, and `completed`. The `pe_cancelled` status is displayed
as “Pending Cancellation”; the historical `on-hold` value is read as an alias
for `on_hold`. `completed` is the terminal state for finite split-payment
subscriptions.

Trialing is represented by the subscription's trial metadata while its
subscription status remains `pending` or `active`. Renewal payment failures
are represented by the WooCommerce renewal order and the durable renewal-claim
payment state; the subscription may enter `on_hold` while recovery is pending.
Consequently, provider vocabulary such as `trialing`, `past_due`,
`pending_cancel`, and `failed` is mapped to these persisted states and is not
written as a second set of WordPress subscription statuses. Every persisted
state change must be validated, timestamped, auditable, and safe to replay.

## Renewal flow

1. Every renewal entry point atomically claims `(subscription_id, billing-period)` in `subscrpt_renewal_claim`; a five-minute lease permits recovery only before an order is attached.
2. The service validates status, due date, gateway, and payment token.
3. A renewal order is fully prepared, atomically attached as the period's canonical order, and recorded in the unchanged legacy relation history.

Early renewals use a separate, customer-paid order path. The subscription stays
active while that order is pending; the order records the current next-date
anchor and `early-renew` relation, and payment-complete/status callbacks advance
the anchor under a subscription/order lock. Replays are absorbed by the order's
application marker, so a failed or abandoned early checkout cannot cancel access
or move the billing schedule.

Renewal pricing follows this precedence: an explicit per-subscription
`_subscrpt_custom_renewal_price` override, the store's updated-product renewal
setting, or the immutable subscribed `_subscrpt_price` snapshot. The subscribed
variation and plan snapshot metadata are copied to each renewal line so an
updated variable-product default cannot silently change an existing customer's
renewal target.
4. The gateway adapter reconciles the canonical order's existing remote payment object, then requests payment with stable per-period idempotency metadata without handling raw card data. PayPal catalog products are reconciled by the WooCommerce product name and canonical URL before creation, and PayPal create requests use deterministic retry identities.
5. Signed webhook events reconcile authoritative payment state; PayPal lifecycle delivery is ordered with full-precision event and remote status-update timestamps.

Canonical subscription posts and internal item posts are not exposed through the
generic WordPress REST posts controller. Administrative plan APIs use the
versioned plugin controller and WooCommerce management authorization. The
read-only `wpsubscription/v1/diagnostics` route reports aggregate lifecycle,
migration, runtime, gateway capability, and schema state for support. It never
returns customer/order identifiers, payment tokens, webhook secrets, or
administrator-provided URLs.

Guest checkout never treats possession of an existing billing email as account
ownership. Existing-email orders remain guest-owned unless that exact customer
is already authenticated, isolating account records and saved payment methods.
6. Success advances and marks the exact order's schedule under a database lock before activation; persistence failure remains fail-closed in durable claim state, enters Action Scheduler, and is independently swept by the hourly repair job.
7. Every step writes a redacted audit event.

## Reporting definitions

Reporting is computed from the store's local subscription and WooCommerce data;
it is not a remote analytics service. Active MRR sums each active subscription's
stored recurring price after normalizing its billing interval. Revenue at risk
sums that same normalized value for `on_hold` and `pe_cancelled` subscriptions.

The 30-day churn metric counts subscriptions whose current post status became
`cancelled` or `expired` in the last 30 days. The displayed rate divides that
count by the current active count plus the churned count, and is therefore a
bounded operational indicator rather than a historical event-sourced cohort
rate. Recovery counts only renewal orders explicitly marked after a persisted
failed or on-hold state later becomes paid; ordinary successful renewals are not
recovery. Cancellation reasons are aggregated from the latest per-subscription
feedback row without exposing comments, customer IDs, or email addresses.

Cohorts group subscription posts by creation calendar month and report their
current retained/churned state. Because the legacy schema does not store every
historical state transition, cohort and churn views disclose this current-state
boundary instead of reconstructing events that were never persisted.

The Reports page can export these metrics as aggregate CSV rows only. Recovery
event attribution is retained in a plugin-owned ledger for a bounded,
administrator-configurable window and is pruned without deleting subscription,
order, token, or cancellation-history records.

## Update delivery

Use versioned ZIP releases and the checked-in release manifest plus SHA-256
evidence produced by the release tooling. A signed update manifest served from
an Ashbi-controlled endpoint is an operational follow-up, not currently hosted
by the plugin. Private GitHub credentials must never be embedded in client
sites. Updates require hash verification and rollback support.
