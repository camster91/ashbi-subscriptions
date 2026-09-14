# Implementation plan

## Product decision

Create an independent client-run plugin from the GPL public core. The plugin is
free to Ashbi clients initially. It must not require an upstream license key or
copy any premium implementation. Reliability and migration safety take priority
over immediate feature parity.

## Phase 0 — Provenance and baseline

Deliverables:

- Pin the exact WordPress.org version, URL, SHA-256, timestamp, and manifest.
- Inventory every bundled dependency and license.
- Import upstream unchanged in a dedicated commit.
- Capture plugin activation, schema, scheduled hooks, post types, metadata,
  options, REST routes, gateway adapters, and external URLs.
- Establish upstream behavior on a disposable WooCommerce site.

Exit criteria: reproducible source provenance and a baseline report with no
unexplained files or external services.

## Phase 1 — Clean Ashbi fork (`0.1.0`)

Deliverables:

- Rebrand plugin name, slug, text domain, assets, links, and UI copy.
- Retain copyright and GPL notices.
- Remove upgrade screens, vendor telemetry, license-key UI, and vendor-specific calls.
- Add compatibility adapters for existing `subscrpt_*` storage and hooks.
- Add CI for PHPCS, PHPStan, PHPUnit, JavaScript linting, packaging, and checksum generation.
- Verify free-baseline behavior with HPOS on and off.

Exit criteria: clean install and controlled migration on staging; no live payment use.

## Phase 2 — Payment hardening (`0.2.0`)

Deliverables:

- Formal lifecycle state machine and idempotent renewal service.
- Stripe test-mode adapter using the official WooCommerce Stripe token model.
- Signed webhook reconciliation and replay protection.
- Retry policy, grace periods, dunning notifications, and audit events.
- Versioned REST API for monitoring and support diagnostics.

Exit criteria: repeated sandbox renewals, duplicate/out-of-order webhook tests,
refund tests, DST tests, and disaster recovery pass.

## Phase 3 — Commercial subscription parity (`0.3.0`)

Deliverables:

- Variable product subscriptions and per-variation plans.
- Sign-up fees, split/installment plans, pause/resume, scheduled cancellation.
- Early/manual renewal and custom renewal pricing.
- Customer payment-method change and self-service lifecycle controls.

Exit criteria: complete checkout-to-renewal test matrix across classic and Blocks checkout.

## Phase 4 — Retention and reporting (`0.4.0`)

Deliverables:

- MRR, churn, cohort, revenue-at-risk, retry, and recovery reporting.
- Recurring coupons, cancellation reasons, recovery campaigns, and win-back attribution.
- Privacy-safe export and retention controls.

Exit criteria: metric definitions reconcile to WooCommerce orders and gateway events.

## Phase 5 — Ecosystem and release operations (`1.0.0`)

Deliverables:

- Delivery schedules, multilingual support, Blocks polish, and adapter SDK.
- Selected LMS, CRM, automation, and licensing integrations based on client demand.
- Signed update channel, staged rollout rings, rollback automation, and support runbooks.
- Migration guides from WPSubscription free/Pro where legally and technically supportable.

Exit criteria: two successful client pilots, monitored renewal cycles, no unresolved
high-severity findings, and documented support ownership.

## Migration strategy

1. Inventory the source site and export a redacted subscription reconciliation report.
2. Back up files and database; record plugin/gateway/WooCommerce versions.
3. Clone to staging and disable outbound customer email.
4. Activate the fork alone; run a dry-run compatibility and data audit.
5. Compare subscription counts, statuses, due dates, parent/renewal orders, and tokens.
6. Execute gateway sandbox renewal and cancellation tests.
7. Schedule production change with rollback package and monitoring window.
8. Reconcile every affected subscription after deployment.

## First agent deliverable

The first implementation pull request should contain only provenance tooling,
the untouched public source import, dependency-license output, baseline tests,
and a report of external endpoints. It must not rebrand or change behavior.
