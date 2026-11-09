# Small-store reliability candidate

## Scope

This candidate combines #45 (optional contact-request review), #46 (simple-product
consent and account routes), #51 (recurring coupon payment limits) and #52 (one
invoice per early/due/cart renewal period). Merge ancestry preserves each PR;
only changelog/audit append conflicts were resolved, retaining both sides.
No onboarding, switching, reporting, fulfillment or new gateway features.

Core runtime continues to use WooCommerce order/payment state, the existing
subscription post, durable renewal claims and cancellation barriers. There is
no new billing table, status, setting, dependency or polling loop. The optional
contact adapter uses private WordPress records, stays disabled by default and
is excluded from the core ZIP. A stored form message cannot cancel a subscription
or count as cancellation confirmation.

## Verification

Runtime assembly: `9522627` (subsequent changes to this document do not alter it).

- PHPUnit: **302 tests / 3,706 assertions passed**, PHP 8.2.34.
- Full WordPress 7.1.3 / WooCommerce 11.2.1 / MariaDB 10.11.19 source checks:
  **HPOS on/off passed**, including real MySQL cancellation contention, billing
  consent readbacks, checkout and early/due/cart invoice collision checks.
- Actual core cancellation + optional contact lifecycle: **22 checks in each
  HPOS mode passed**, including unavailable-provider requests staying open,
  canonical confirmation, required reply attestation and immutable closure.
- Full PHPStan: **102 files, no errors**. Full WordPress PHPCS: **0 errors /
  198 warnings**. Warnings remain and the advisory baseline is not clean.
  The combined scan initially found 88 errors in the adapter test doubles,
  headers and config example; these were corrected with focused fixture-only
  documentation exceptions, retained security checks and passing adapter tests.
- JavaScript/JSX syntax: **38 files passed**. Integration-tooling checks and
  **18 release-harness tests passed**. The harness needed both PHP and Node;
  host lacked PHP, container initially lacked Node. An existing Node binary was
  copied into the disposable container after its package mirror was unavailable.
- Compatibility contract: **10 metadata keys / 5 hooks passed**. PHP syntax,
  nonce/prepared-SQL checks and canonical package validation passed; **139
  packaged PHP files** linted, integration fixtures excluded.
- A local assembly mistake briefly omitted the coupon helper fix. That run
  failed the coupon assertions and is not passing evidence. The helper was
  restored from the merged source before the final passing checks above.

Network/provider calls and outgoing mail were blocked. Fabricated subscriptions,
orders and support messages only; no live settings or customer records changed.
These checks do not prove real gateway charges or installed Elementor/browser
behavior on an actual store. Individual PR CI does not certify this combined
candidate; its own hosted matrix must pass before merging.

## Merchant use

- Customers use **My Account → Subscriptions → View**. If account routes are
  missing after an update, save **Settings → Permalinks** once.
- Use the existing WooCommerce invoice to resolve an unpaid renewal. At its due
  date an early invoice keeps its quote and payment identity. Fresh cart
  checkouts for that same period are refused before payment. Failed/on-hold
  invoices remain the order to inspect; they are not treated as paid.
- Review **Cancellation needs review**, plus On Hold/Pending Cancellation, and
  read the original cancellation receipt and WooCommerce payment history.
  A pending receipt needs confirmation/reconciliation; email delivery is not
  billing proof. Historical invoice conflicts stop automatic dispatch and log
  the subscription ID, but still need manual reconciliation.
- If the optional adapter is separately configured, review open contact requests
  daily. Verify the customer/subscription, explicitly cancel in core, verify the
  result, reply, and only then close the request with the recorded outcome.
  It does not monitor an email inbox or cancel from message wording.

## Remaining release and WordPress.org work

The code-maintenance phase is separate from the proposed next goal of finding
and fixing the remaining issues before directory resubmission. It is not an
assertion that every repository issue is resolved or the plugin is production
payment certified. Existing #8/#9 (community identity/package validation) and
#12 (onboarding safety) remain independent, unmerged work.

1. Verify real Stripe/PayPal sandbox payments, provider outages, crash windows,
   duplicate webhooks and cancellation/payment races in a separately authorized
   disposable setup. Add timezone/DST and persistent-object-cache coverage.
   Do not infer consent, manufacture historical evidence or use live credentials.
2. Refresh/review the separate community builder and hash plan after runtime
   changes are frozen. Test the exact community ZIP on supported WordPress/PHP/
   WooCommerce versions with HPOS on/off, complete official Plugin Check results,
   accessibility/security review, update identity and file/database rollback.
   A canonical GitHub ZIP or diagnostic job success cannot certify Community.
3. Reconcile the remaining audit tickets into reproducible bugs, directory
   requirements, optional features and deferred complexity. Review any actual
   WordPress.org feedback, confirm the submitting account/approved slug and
   contributor details, then prepare a concrete resubmission package for review.
   No directory submission, account change or release is performed here.

Rollback code with the previous verified package; preserve all subscriptions,
orders, relations, consent and cancellation evidence. Do not downgrade the
cancellation protection or restore a pre-cancellation database as a code rollback.
