# Persistent customer cancellation receipts

## Problem and scope

A customer could successfully cancel through My Account, then lose the only
explicit billing confirmation when the temporary WooCommerce notice disappeared.
The existing pending-cancellation panel described access expiry without explaining
whether cancellation was confirmed. A status alone does not prove recorded intent
or provider confirmation.

The authorized subscription detail view now renders a persistent receipt using
the existing cancellation barrier and confirmation metadata. It performs one
indexed subscription lookup, with no writes, provider requests, polling, new
settings, tables, dependencies, or lifecycle transitions. Existing ownership and
nonce checks continue to protect account views and cancellation actions.

## What the customer sees

- **Cancellation confirmed:** future automatic billing is blocked. A payment
  already dispatched still requires separate review.
- **Confirmation pending:** the request is recorded and local renewal billing is
  blocked. Provider-managed billing or an in-progress payment requires review;
  contact the store for an update.
- **Details temporarily unavailable:** refresh or contact the store. The screen
  does not claim that cancellation was saved when evidence cannot be read.

When access is scheduled to end, show the original barrier access-end timestamp
in the store timezone, including daylight-saving transitions. A past scheduled
end is described as past rather than promising continuing access. Legacy pending
cancellations retain their scheduled-access notice without invented confirmation.
A confirmation flag without a durable intent record does not create a receipt.
Conflicting confirmation rows are conservatively treated as pending. Reads bypass
WordPress metadata cache so stale flags cannot overstate the stored result.

## Merchant instructions

Ask customers to open **My Account → Subscriptions → their subscription** after
cancelling. Their receipt remains visible on later visits. If it says confirmation
is pending, inspect the subscription, related WooCommerce orders and provider
result before telling the customer billing is stopped. Investigate any payment
already in progress separately. A contact-form submission or email is not a
cancellation receipt and does not cancel a subscription.

The merchant review filter and customer status/reactivation corrections are
separate companion changes (PRs #48 and #47). This patch does not add an inbox,
change payment processing, or reconcile historical records. The existing account
table still has layout and future-payment labeling gaps without those companions.

## Verification

Tests were written before changing the receipt behavior. The original code failed
the saved-confirmation assertion; an added database-exception fixture also failed
before exception handling was added.

- Local Composer suite: **288 tests, 3,499 assertions**, compatibility contract and
  release package checks passed (138 PHP package files).
- PHP syntax, PHPStan, security-sensitive coding checks and changed maintained
  files' coding checks passed.
- Disposable WordPress 7.1.3 / WooCommerce 11.2.1 / PHP 8.2.34 / MariaDB:
  focused receipt checks and the full security-boundary integration harness passed
  in separate fresh databases with HPOS disabled and enabled.
- Real database checks cover absent intent, repeated immutable reads, stale cache,
  conflicting flags, missing lookup table, original access end and Toronto DST.
  Isolated unit fixtures also cover thrown database exceptions.
- Headless Chromium, fabricated authenticated customer, HPOS enabled: customer
  cancellation, reload, repeated request, and receipt visibility at 1440px and
  320px passed with no JavaScript exceptions or external page requests. The
  receipt fits the mobile viewport; the inherited account table still overflows.
  The saved state had automatic renewal off and one barrier; a renewal-order
  attempt returned false without adding an order relation.

All data was fabricated locally. Mail and provider traffic were blocked. No live
store, provider account, production subscription, release or setting was changed.
These checks do not replace outstanding real provider sandbox/crash-window tests.

## Compatibility and rollback

No schema migration, status conversion or backfill is required. Existing barrier,
confirmation and access records remain authoritative and unchanged. Subscription
storage remains the existing post type independently of WooCommerce HPOS.

Rollback this patch's source files and remove the extracted notice template to
restore the previous account notice. Keep the existing cancellation engine,
schema, barriers, evidence and scheduler protections; never remove those records
as part of a display rollback. No settings restoration is needed.
