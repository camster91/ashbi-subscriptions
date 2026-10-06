# Public release checklist

Ashbi Subscriptions is free, open-source software under GPL-2.0-or-later.
The public repository is a development project, not a claim that every release
is approved for unattended production billing or WordPress.org distribution.

## Before installing on a store

- Back up the database and plugin files; rehearse restoration on staging.
- Use an isolated WordPress/WooCommerce environment, fabricated customers,
  gateway sandbox credentials, and suppressed outbound customer email.
- Do not activate this plugin alongside another copy of WPSubscription or a
  legacy fork that declares the same runtime classes.
- Review migration dry-run results before applying changes. Preserve orders,
  subscriptions, payment references, and an explicit rollback path.
- Verify checkout, renewals, failed-payment recovery, cancellation, activity
  history, and HPOS enabled/disabled behavior with your gateway and extensions.
- Never commit customer records, store inventories, production operational
  reports, database exports, credentials, or payment-token values.

## Before a general release

- Required PHP, static-analysis, security, package, and real
  WordPress/WooCommerce integration checks must pass on the exact release commit.
- Reconcile current plugin header, stable tag, changelog, and generated ZIP.
- Preserve upstream GPL provenance and bundled third-party license notices.
- Review WordPress.org plugin-directory requirements, trademark use,
  documentation, translations, and dependencies before submission.
- Document supported WordPress, WooCommerce, PHP, gateway, and checkout versions
  from executed tests rather than assumptions.
- Run representative sandbox renewals, retries, replay/concurrency checks,
  migrations, and package/database rollback drills.
- Publish the corresponding source and reproducible package checksums.

## Public-history boundary

This repository contains sanitized development history. Private client
inventories, production audit records, and private rollout discussions are not
part of the public source. Historical local-test claims are not current release
certification; rely on current CI and release-specific evidence.
