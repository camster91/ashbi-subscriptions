# Changelog

All notable Ashbi-maintained changes after the immutable upstream import are
documented here. The original vendor changelog remains at `plugin/changelog.txt`.

## 2.1.1 — 2026-10-03

- Refuse to load when WP Subscription Core or upstream WPSubscription is already
  active, with an admin notice and clean activation refusal, avoiding a fatal
  shared-class clash.
- After successful variation mapping apply, rollback, or editor saves, clear
  product object caches and request well-known full-page cache purges
  (LiteSpeed, WP Rocket, and similar). Migration reports include `cache_purged`
  and remind operators to purge manually if a page still lacks the delivery note.

## 2.1.0 — 2026-10-03

- Add opt-in variation term mapping without schema changes. Each mapped variation
  uses its own WooCommerce purchase-time price, including sales; unmapped
  variations are one-time and inherited product-level relations are suppressed.
- Auto-select the mapped term for programmatic additions and reject unrelated
  plan choices. Show a read-only delivery note instead of the multi-term selector.
- Preserve recurring price/cadence snapshots through renewal, including when the
  store uses updated-product prices; explicit subscription price overrides remain.
- Add secure native variation controls, a dry-run-first Migration page and
  `wp ashbi-subscriptions migrate-variations`, with confirmation, review digests,
  a shared mutex, transactional writes and guarded last-apply rollback.
- Parse frequency attributes and legacy metadata, carry supported trials/signup
  fees, report conflicts and possible DWL simple-product targets, and retain
  stopgap product relations unless removal is explicitly requested.
- Recognize plan order snapshots for Stripe payment-method saving before the
  subscription relation exists. Existing durable renewal claims are unchanged.
- Document per-site migration, rollback and the required staging checkout,
  gateway, mobile and desktop verification. No client runtime was exercised.

## Unreleased

### Changed

- Rebrand the maintained customer-facing plugin surfaces as Ashbi Subscriptions while retaining all legacy runtime, storage, hook, slug, and text-domain identifiers required for in-place compatibility.
- Remove upstream commercial, account, support, license-activation, and upgrade calls to action from the maintained admin, email, and package-description surfaces.
- Make the standalone admin independent of the removed paid vendor plugin and replace the report placeholder path with live MRR, revenue-at-risk, churn, recovery, cancellation-reason, and signup-cohort metrics.
- Add administrator-only aggregate CSV reporting and bounded recovery-event retention controls.
- Add an authenticated, read-only aggregate diagnostics REST route for local
  support, plus deterministic release ZIP and per-file checksum evidence.
- Refresh the generated translation catalog after removing obsolete preview and
  paywall surfaces, and extend the disposable integration harness to exercise
  activation markers, deactivation queue cleanup, and safe default uninstall.
- Persist source checkout, commit, branch, dirty-tree, tool, epoch, and build
  script identity in the release manifest alongside archive and member hashes.
- Add an offline release-evidence verifier that checks the archive checksum,
  exact member set, safe archive paths, and every recorded member hash.
- Add tracked-diff and worktree-status boundary hashes so a dirty release
  checkout remains traceable without copying source contents into evidence.
- Normalize semantically equivalent MySQL table-definition output in keyed
  rollback fingerprints so dump/restore comparisons remain portable across
  supported database engines.
- Document and contract-test the canonical public GPL namespace and legacy
  procedural compatibility boundary without claiming paid-only class aliases.
- Initialize the plugin after WooCommerce's core bootstrap and make the
  disposable integration runner wait for its exact dependency activation rows,
  eliminating first-request races in Playground.
- Redact PayPal webhook and gateway response payloads from debug logs and retain
  a bounded refund-event ledger so repeated refund deliveries are acknowledged
  without duplicating reconciliation notes.
- Use deterministic PayPal refund request identities derived from the order and
  refund sequence so uncertain retries cannot submit the same refund twice.
- Register My Account endpoints on normal requests without flushing global
  rewrite rules; activation and versioned migrations now schedule one bounded
  soft flush instead.
- Decode legacy serialized migration data without instantiating stored classes
  and leave malformed records in place for operator review.
- Remove unused third-party provider/logo assets from the distributable package
  and render built-in integration cards with Ashbi-neutral initials.

### Fixed

- Enforce finite recurring-plan billing lengths and installment counts as the
  subscription's immutable payment limit.
- Split installment totals in WooCommerce currency minor units and apply the
  rounding residual only to the final payment, preventing cumulative
  overcharges.
- Retain legacy order-history metadata until every entry has migrated durably,
  and make migration retries recognize an exact existing relation row.
- Reject guest-owned, wrong-type, zero-customer, and owner-mismatched
  subscription switches before any paid-order mutation.
- Fail closed when a legacy subscription is reactivated without a canonical order-item relation, avoiding PHP warnings while preserving the lifecycle status transition.
- Serialize automated and checkout renewals by subscription billing period so concurrent workers cannot create or charge duplicate renewal orders.
- Reuse the canonical renewal order on safe retries, reconcile existing Stripe PaymentIntents before creating one, and cancel checkout orders that lose the period claim.
- Keep uncertain Stripe transport/gateway outcomes in durable recovery, and enforce the persisted retry timestamp in queued payment and schedule callbacks.
- Keep renewal activation fail-closed until the exact canonical order durably advances the billing date and marker; queue bounded schedule-repair jobs when persistence is unavailable.
- Count only paid orders toward installment limits and finalize the last installment only after the canonical renewal's durable schedule gate.
- Backfill unambiguous open legacy renewals and make ambiguous open orders non-payable pending human reconciliation, without changing their status, completed orders, subscription dates, payment tokens, or relation history.
- Persist activation-time renewal migration markers through metadata-only writes, minimizing WooCommerce-managed order changes while retaining the migration audit trail.
- Quarantine only pre-existing overdue subscription IDs before cron activation, preserving their status and dates while unrelated purchases and on-time renewals continue.
- Keep claim, overdue, and operator quarantine inventories source-owned; rebuild their active union on repeated upgrades, verify every option write before recording completion, and preserve legacy global blocks fail-closed.
- Bind PayPal returns to the exact PayPal subscription stored on the WooCommerce order.
- Resolve unmapped PayPal subscription webhooks through the canonical order ID before falling back to order-item relations.
- Normalize the first order relation before reading its subscription ID during PayPal webhook reconciliation.
- Return a safe 400 response for malformed PayPal webhook JSON after signature verification, without dereferencing untrusted fields.
- Require a PayPal webhook event ID before dispatch so refund replay protection cannot silently degrade on unidentified deliveries.
- Serialize PayPal transaction webhook reconciliation by a bounded transaction lock and reject completed/refunded deliveries that do not identify a transaction.
- Reconcile PayPal catalog products by their WooCommerce name and canonical product URL before creation, and use deterministic request identities for PayPal product, plan, and subscription creation retries.
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
- Defer optional Stripe adapter initialization when plugin load order has not
  exposed the gateway runtime yet, and keep diagnostics from autoloading an
  incomplete optional gateway class.
- Keep activation-time diagnostics safe when WooCommerce has not loaded its
  structured logger helper yet.
- Remove a stray PHPUnit import from the production PayPal gateway so the runtime does not depend on test-only classes.
- Restrict customer reactivation to an unexpired pending cancellation; terminal subscriptions still require a paid renewal.
- Make the public payment-requirement helper fail closed for cancelled, completed, trashed, and draft records while retaining expired manual renewal eligibility.
- Keep existing-email guest checkouts guest-owned unless that exact customer is already authenticated, preventing email-only account and saved-payment binding.
- Mark paid renewals recovered from a failed or on-hold state so retry performance is reported separately from ordinary renewals.
- Make saved payment-method replacement idempotent and use the same owned-token context for subscription portal renewal controls across supported Stripe-compatible gateways.
- Suppress the Stripe renewal failure hook when the failed order has no canonical subscription relation, avoiding a false failure event for subscription ID zero.
- Neutralize formula-like text in aggregate CSV report cells so administrator exports cannot create spreadsheet formulas from stored labels or reasons.
- Require authentication and reject author-ID-zero records in customer subscription views and lifecycle actions so guest-owned records cannot become an anonymous control surface.
- Require customer lifecycle routes to target the subscription post type before any administrator or customer mutation can run.
- Correct opt-in uninstall cleanup to remove the actual `subscrpt_paypal_map` gateway mapping table.
- Persist PayPal completed-sale event IDs and refuse to reopen refunded or cancelled orders on replayed completion deliveries.
- Constrain admin subscription trash, restore, and delete actions to subscription posts that the operator can delete, including bulk and AJAX paths.
- Make the API enable/disable setting control REST route registration and mask configured API keys in the admin settings markup.
- Redact gateway credentials, payment identifiers, email addresses, and credential fields at the shared log boundary, with a bounded diagnostic message length.

### Security and operations

- Add a read-only, privacy-minimal fleet migration audit.
- Add a schema-v2 overdue-disposition worksheet and digest-confirmed, fail-closed no-charge apply command that revalidates live evidence and preserves independent operator holds.
- Add keyed, privacy-preserving full-state fingerprints and strict activation/rollback comparison tooling for per-site staging evidence.
- Add a read-only, fail-closed staging isolation preflight for URLs, workers, webhooks, gateways, and operator-controlled outbound paths.
- Allow isolated staging rehearsals to declare an exact Stripe/PayPal gateway matrix; the read-only preflight now fails closed when an expected adapter is missing, disabled, or not sandbox/offline.
- Enforce the fleet audit schema and rebuild nested summaries from fixed aggregate allowlists so unexpected record-level fields cannot cross the fleet-safe boundary.
- Validate HPOS order references against the active WooCommerce order store.
- Require Stripe auto-renewal charges to originate from the canonical claimed renewal order, with stable per-period idempotency metadata and existing-intent recovery.
- Remove payment-sensitive subscription and item records from the generic WordPress REST posts controller.
- Require action-specific nonces and plugin-management capabilities for WooCommerce dependency installation and activation.
- Add Docker-backed WordPress/WooCommerce security-boundary checks and a fresh-archive release packager that preserves the legacy plugin path while excluding test-only endpoints.
- Add a packaged disposable HPOS on/off runtime matrix covering classic checkout, plan-subscription snapshots, pause/resume, scheduled cancellation, and duplicate-safe early renewal in both WooCommerce order storage modes.
- Reconcile representative pre-existing subscription, order, and relation data across candidate activation on a disposable MySQL clone, with exact database/package rollback proof.
- Exercise classic manual renewal ownership and duplicate-safe renewal relation processing with a fabricated expired subscription in the disposable runtime.
- Exercise custom renewal pricing and grace-period start/end scheduling with restored options and disposable Action Scheduler cleanup.
- Exercise fabricated paid subscription switching in the disposable runtime, including target ownership, snapshot replacement, relation creation, and duplicate callback protection.
- Exercise recurring-coupon classification, bounded payment limits, and renewal-order discount carry-through with a synthetic WooCommerce coupon in the disposable runtime.
- Add customer-owned saved-token payment-method replacement for Stripe renewals with nonce, subscription ownership, token ownership, gateway, and Stripe customer binding checks; cover the path in both disposable HPOS modes without handling raw card data.
