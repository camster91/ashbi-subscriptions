# Agent operating guide

## Mission

Build a reliable, client-run WooCommerce subscriptions plugin from the public
GPL WPSubscription free source. The result must be independently branded,
maintained by Ashbi, safe for real recurring payments, and distributable to
clients at a $0 license fee.

## Start here

1. Read every file in `docs/` before editing code.
2. Create an immutable provenance record for the exact upstream ZIP: URL,
   version, retrieval date, SHA-256, file manifest, and license headers.
3. Import the unmodified public source in one dedicated commit named
   `chore: import GPL upstream subscription vX.Y.Z`.
4. Never import files from a paid ZIP, customer portal, licensed installation,
   nulled package, or unknown mirror.
5. Run a dependency-license inventory before rebranding.
6. Rebrand in a separate commit while retaining copyright notices.

## Immediate work queue

- [x] Issue 1a: Record immutable upstream package provenance and manifest.
- [x] Issue 1b: Complete the dependency-license inventory.
- [x] Issue 2: Import the public free source without functional changes.
- [x] Issue 3a: Add Composer, PHPCS, PHPStan, and PHPUnit checks.
- [ ] Issue 3b: Add JavaScript linting and reduce the inherited PHPCS baseline debt.
- [x] Issue 4: Produce an upstream and fleet baseline report.
- [ ] Issue 5: Rebrand plugin header, user-facing copy, assets, namespaces, and update URLs.
- [x] Issue 6: Preserve compatibility aliases for existing `subscrpt_*` storage and hooks.
- [ ] Issue 7: Implement activation, deactivation, uninstall, and rollback tests.
- [x] Issue 8a: Add durable renewal claims, Stripe/PayPal reconciliation, and local regression coverage.
- [ ] Issue 8b: Complete Docker-backed Stripe/PayPal sandbox and crash-window staging tests.
- [x] Issue 9a: Build and verify a legacy-path-compatible release package.
- [ ] Issue 9b: Install the candidate on a current disposable site clone and reconcile its data.

## Engineering rules

- Do not rename database keys or post types until a tested migration exists.
- Do not run the upstream plugin and this fork simultaneously.
- Use WooCommerce CRUD APIs and declare HPOS compatibility explicitly.
- Use Action Scheduler for durable jobs; every renewal job must be idempotent.
- Verify webhook signatures and reject replayed events.
- Never store card data. Let supported gateways tokenize payment methods.
- Protect admin mutations with capabilities and nonces.
- Redact customer data, secrets, tokens, and webhook bodies from logs.
- Keep customer-facing behavior backward compatible unless a migration note says otherwise.
- Add tests before modifying renewal, cancellation, refund, or retry behavior.

## Definition of done for any feature

A feature is complete only when it has unit tests, integration coverage,
capability/nonce checks, idempotency behavior, migration notes where relevant,
user documentation, changelog entry, and a verified rollback path.

## Release restrictions

No agent may deploy to a client site, publish a release, rotate credentials,
or activate live gateway mode without explicit human approval.
