# Merchant cancellation protection

## Objective and assumptions

Merchant cancellation must save durable billing protection before lifecycle or
email side effects, using the same evidence engine as customer cancellation.
Preserve the existing choices: ending access now versus the configured scheduled
end. Existing immutable barriers and historical data are never rewritten.
Customer self-service restrictions remain unchanged; merchants retain their
existing `edit_post` permission and form nonce checks. No new permission grant.

## Acceptance and boundaries

- Both admin cancellation actions record intent, block future renewal creation
  and provider dispatch, and distinguish confirmed from pending outcomes.
- Ending now creates an immediate access end for a new request. If an older
  barrier already exists, preserve it; commit the terminal canonical status
  under the dispatch mutex so repair cannot reopen access after a crash.
- Scheduled requests preserve the configured original access end, including on
  replay. A repeated request must not duplicate lifecycle/email side effects.
- Refresh cached lifecycle data under the existing mutex, including repair, so
  stale active status cannot reopen access after a merchant ends it.
- A busy dispatch, unavailable provider, metadata failure or email exception
  cannot remove intent. Failed storage must not claim a cancellation was saved.
- Respect existing merchant edit permissions, including plans that disallow
  customer self-service. Owners without merchant edit permission cannot use the
  new service entry. Keep guest, wrong-type and actor checks fail-closed.
- No schema, dependency, table, polling, new dashboard, historical backfill or
  provider/live-store operation. Do not modify internal `Action::status()`.
- Admin detail copy explains billing protection, access and next review action;
  a status alone cannot prove provider confirmation. Hide impossible reopening.

## Plan and source layout

1. Reproduce the actual admin service failure in the existing isolated evidence
   fixture and PHPUnit suite before changing runtime code.
2. Add a merchant entry to `Illuminate/CancellationEvidence.php`, sharing its
   existing durable request implementation and dispatch mutex. Keep the public
   customer request signature and behavior compatible.
3. Route only admin `Subscriptions::process_status_change()` cancellation actions
   through it. Keep notification failure independent of persisted state.
4. Update the existing details controller/view with clear actions and receipt
   copy; retain the current capability and nonce checks and page layout.
5. Add fabricated real WordPress/MySQL HPOS on/off and admin screen checks. Verify
   denied access/nonces, no provider traffic, replay and mailbox failures.

Use existing PHP 7.4-compatible namespaced WordPress style, e.g.
`public static function request_admin( int $id, int $actor, string $request, string $target ): array`.
Tests belong in `tests/fixtures`, `tests/unit` and the existing guarded integration
harness under `plugin/tests/integration`; merchant copy stays in the existing view.

## Commands

Run `vendor/bin/phpunit --filter MerchantCancellationTest` for the red/green loop,
then `composer test`, `composer lint:phpstan` and
`vendor/bin/phpcs --warning-severity=0 --sniffs=WordPress.Security.NonceVerification,WordPress.DB.PreparedSQL`.
Use the existing disposable source/candidate HPOS matrix and actual local fake
records. No provider, mail or production work is authorized by this specification.

## Compatibility and rollback

No migration is needed. Revert the new entry point, admin routing and display
changes to roll back; retain all barriers, evidence, orders and pending recovery
jobs. Never downgrade the existing protective cancellation engine or infer old
merchant intent from legacy status records.

## Merchant use and remaining boundaries

Open the existing subscription details page. **End subscription now** ends access
and blocks future renewals; **Schedule cancellation** uses the configured
cancellation timing, which can itself be immediate. Both choices save a durable
renewal barrier first. Read the saved notice after processing or reloading.
Confirmed billing protection and the end of access are shown separately.

A pending result needs the cancellation history and payment provider checked.
If another operation holds the dispatch lock, the notice says so. In particular,
ending an already scheduled cancellation now can require retrying **End
subscription now** after that operation finishes. A pending cancellation must never complete the related WooCommerce order.
The old access promise remains
unchanged until the canonical status change succeeds. Already dispatched payments
still need reconciliation; this change does not promise refunds or withdraw a
payment already in progress. Email availability never determines the saved result.

Contact/email intake remains separate: verify the customer and subscription,
perform the explicit cancellation, check the saved result, then record the reply
and resolve the message. Never treat free text or a saved message as consent or
as evidence that billing stopped. Legacy cancellations are not backfilled.

Next priorities remain (1) actual provider sandbox and crash-window staging,
(2) the separate checkout/account-route PR, and (3) contact intake verification
with the installed form plugin. Defer a second dashboard, automatic free-text
cancellation, broad status rewrites and additional tables.

## Regression evidence (disposable local fixtures)

Before the change, the merchant-service regression failed because no cancellation
barrier was saved. A real WordPress cache regression then failed when a stale
active post reopened access during repair. A further offline regression reproduced
an unwanted completed order while cancellation waited for the mutex. These were
reproduced before their respective fixes; no historical customer data was used.

The final unit suite passes: **294 tests, 3,633 assertions**. The compatibility
contract passes (10 meta keys, 5 hooks). PHPStan checks all 100 configured runtime
files without errors. Full WordPress PHPCS and the nonce/prepared-SQL security
checks pass on all six changed runtime/new test files. The package check passes,
including syntax checks on 139 packaged PHP files and exclusion of test fixtures.
The additional finalizer fixture supplies WordPress cache invalidation in its
offline runtime; it does not replace the real database cache regression.

The actual controller checks cover both access choices, valid and invalid nonces,
guest rejection, an unavailable admin notification callback, persisted proof,
blocked renewal creation, immutable replay, ending already scheduled access,
repair, stale cached status, and rendered receipts. External HTTP and email are
blocked in this local environment. Hosted CI and provider sandbox/crash-window
staging remain separate checks; no provider cancellation or release is claimed.

The complete guarded integration harness passes with HPOS **off and on** on
WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.2.34 and MariaDB 10.11.19. The focused
merchant controller run passes 21 assertions, including the real stale-cache
regression. Existing consent, database mutex and variation lifecycle checks pass.

An authenticated Chromium merchant completed both actions using the actual
selector, confirmation and POST form. The correct confirmed receipt and access
choice survived reload, reopening was absent, and the notice fit at 390px and
1440px. The final run had no JavaScript exceptions; Gravatar was blocked. Initial
browser attempts stalled on the disposable admin load and included an uncaught
promise after interrupted navigation. The final pass restarted the local server
with all WordPress HTTP/email blocked; no plugin safeguard was weakened. Browser
screenshots and fixture output are local scratch evidence, not release contents.
