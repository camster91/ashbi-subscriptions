# Changelog

All notable Ashbi-maintained changes after the immutable upstream import are
documented here. The original vendor changelog remains at `plugin/changelog.txt`.

## Unreleased

### Changed

- Rebrand the maintained customer-facing plugin surfaces as Ashbi Subscriptions while retaining all legacy runtime, storage, hook, slug, and text-domain identifiers required for in-place compatibility.
- Remove upstream commercial, account, support, license-activation, and upgrade calls to action from the maintained admin, email, and package-description surfaces.

### Fixed

- Serialize automated and checkout renewals by subscription billing period so concurrent workers cannot create or charge duplicate renewal orders.
- Reuse the canonical renewal order on safe retries, reconcile existing Stripe PaymentIntents before creating one, and cancel checkout orders that lose the period claim.
- Keep uncertain Stripe transport/gateway outcomes in durable recovery, and enforce the persisted retry timestamp in queued payment and schedule callbacks.
- Keep renewal activation fail-closed until the exact canonical order durably advances the billing date and marker; queue bounded schedule-repair jobs when persistence is unavailable.
- Count only paid orders toward installment limits and finalize the last installment only after the canonical renewal's durable schedule gate.
- Backfill unambiguous open legacy renewals and make ambiguous open orders non-payable pending human reconciliation, without changing their status, completed orders, subscription dates, payment tokens, or relation history.
- Quarantine only pre-existing overdue subscription IDs before cron activation, preserving their status and dates while unrelated purchases and on-time renewals continue.
- Keep claim, overdue, and operator quarantine inventories source-owned; rebuild their active union on repeated upgrades, verify every option write before recording completion, and preserve legacy global blocks fail-closed.
- Bind PayPal returns to the exact PayPal subscription stored on the WooCommerce order.
- Fail PayPal webhooks closed without downloading request-selected certificates, while returning a retryable response when PayPal verification is unavailable.
- Reconcile PayPal lifecycle webhooks against authoritative remote state and full-precision status timestamps so same-second, stale, and out-of-order deliveries cannot overwrite newer state.
- Preserve the stored variation ID during manual subscription renewal.
- Avoid an undefined variable when persisting PayPal product metadata.
- Catch global exceptions correctly from namespaced plugin code.
- Replace a PHP 8-only string helper to retain declared PHP 7.4 compatibility.
- Return the Store API validation error object on successful validation.
- Initialize the next-date value before extension filters run.
- Replace a dormant integration debug fatal with a capability-checked JSON response.
- Load the current WooCommerce Stripe exception compatibility class before declaring the Ashbi renewal adapter, preventing an activation-time fatal on current Stripe releases.
- Restrict customer reactivation to an unexpired pending cancellation; terminal subscriptions still require a paid renewal.
- Keep existing-email guest checkouts guest-owned unless that exact customer is already authenticated, preventing email-only account and saved-payment binding.

### Security and operations

- Add a read-only, privacy-minimal fleet migration audit.
- Enforce the fleet audit schema and rebuild nested summaries from fixed aggregate allowlists so unexpected record-level fields cannot cross the fleet-safe boundary.
- Validate HPOS order references against the active WooCommerce order store.
- Require Stripe auto-renewal charges to originate from the canonical claimed renewal order, with stable per-period idempotency metadata and existing-intent recovery.
- Remove payment-sensitive subscription and item records from the generic WordPress REST posts controller.
- Require action-specific nonces and plugin-management capabilities for WooCommerce dependency installation and activation.
- Add Docker-backed WordPress/WooCommerce security-boundary checks and a fresh-archive release packager that preserves the legacy plugin path while excluding test-only endpoints.
