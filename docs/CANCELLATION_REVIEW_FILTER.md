# Cancellation confirmation review filter

## Objective and acceptance

Add one read-only filter to the existing merchant Subscriptions list. A store
owner must be able to find recorded cancellation requests without confirmed
outcomes, including requests whose status write failed or whose provider is
unavailable. This is not a contact-form/email intake queue (separate PR #45).

- Match a `subscrpt_order` with an existing cancellation barrier and without
  `_ashbi_cancellation_confirmed=1`. Legacy pending cancellations without durable
  request evidence do not match. A confirmation marker without a barrier does
  not invent a request. Confirmed requests are excluded even while access continues.
- Reuse existing customer/order/detail links, pagination and permission boundaries.
  Combine search/status/date filters; clearing the filter returns the normal list.
- Explain the next action: inspect the subscription's evidence and payment/provider
  outcome. The view never requests cancellation, charges, retries, emails or refunds.
- Read current storage on each filtered page request. No new table, status, polling,
  scheduled job, count widget or dependency. Missing query storage must show an
  unavailable error, not a reassuring empty backlog.

## Plan and structure

1. Test the scoped query and fail-visible cleanup behavior before implementation.
2. Add `Admin/CancellationReview.php` for the specialized read query. Scope its
   WordPress query filter to one exact WP_Query object and remove it in `finally`.
3. Wire the existing `Admin/Menu.php` list and `views/subscription-list.php` form.
   Use the list's existing management capability and nonce-protected POST redirect.
4. Add actual WordPress/MySQL membership/pagination tests to the existing isolated
   integration harness, with fabricated records and cleanup. Document merchant use.

## Commands and conventions

Use PHP 7.4-compatible namespaced WordPress style, for example
`public static function query( array $args ): \WP_Query`. Preserve legacy keys.
Unit fixtures live under `tests/fixtures/`, PHPUnit tests under `tests/unit/`;
real-database coverage stays under `plugin/tests/integration/` (not packaged).
SQL `EXISTS` predicates keep filtering, counts and pagination in the database;
the screen does not load an unbounded list of request IDs first.

Run `composer test`, `composer lint:phpstan`, `composer lint:syntax`, and
`vendor/bin/phpcs --warning-severity=0 --sniffs=WordPress.Security.NonceVerification,WordPress.DB.PreparedSQL`.
Run full PHPCS on new maintained code. Hosted source/candidate HPOS-on/off CI must
pass on this change; local doubles do not establish database correctness.

## Boundaries

Always preserve billing barriers, evidence, WooCommerce order CRUD and pagination.
No production operations, data migration, provider call or external communication.
No generic status change to close an unresolved provider request. Schema/dependency
changes and any deployment remain outside this task. Roll back this filter's files
only; preserve the protective engine and all business records. No open product
decision is required: scope follows the authorized small-store audit priorities.

## Merchant procedure

In **Ashbi Subscriptions → Subscriptions**, check **Cancellation needs review**
and select **Filter**. Open each result using **View / Edit**. Review the stored
request/evidence, related WooCommerce order and payment provider outcome. A
cancelled access status alone does not prove provider billing has stopped. Do not
reopen billing, refund, or mark a free-text request cancelled from this screen.
Successful repair/confirmation removes a record on the next filtered page load.
**Clear** removes this filter along with the other list filters.

Check the inbox and contact-form submissions separately. This view only contains
durable requests already recorded by the cancellation engine. PR #45's separate
intake queue addresses received messages; this filter does not backfill them.
An empty filtered result means no records match these filters, not that the
store has no unresolved support messages. A storage error displays unavailable.

No migration is required. Restore the prior Menu/list-template files and remove
the new read helper to revert this feature. Keep all cancellation barriers,
confirmation metadata, evidence and WooCommerce records. No database rollback.

## Executed verification

The new PHPUnit regression failed before implementation. Scope/cleanup, permission
and invalid-nonce rejection tests now pass. New maintained files pass full PHPCS.
The inherited list template used an undefined `$order_item`; actual WordPress
rendering reproduced two warnings, then passed after using the current row's
`$subscription_order_item` for quantity and totals.

Focused real WordPress 7.1.3 / WooCommerce 11.2.1 / PHP 8.2.34 / MariaDB tests passed
locally with HPOS off and on: membership (including confirmed, unrecorded,
wrong-type and status-write-failed cases), pagination/counts, combined filters,
fresh confirmation reads, missing-storage error/cleanup, and the rendered list.
All records were fabricated. Mail and provider requests were blocked; schedulers
were disabled. These are local database/rendering results, not gateway sandbox
transactions, a browser accessibility review or production certification.

The complete existing integration callback also passed locally with HPOS on
and off, including billing-consent readbacks, real MySQL cancellation contention
and variation-cart lifecycle checks. A used HPOS database refused a storage-mode
switch because fabricated orders were out of sync; a fresh disposable database
was used for the off run rather than bypassing WooCommerce's guard. The first CLI
attempt loaded an already-loaded integration function twice; using `require_once`
in the external local runner corrected that setup error.

Local unit suite: 288 tests / 3,499 assertions. Compatibility contract, package
checks (138 PHP files), PHPStan, PHP syntax, security-sensitive PHPCS and whitespace
checks passed. This independent branch starts at main `c70272f`; PR #47's separate
customer-screen/grace tests are not included in this count. Hosted checks for this
branch remain separate from PR #47's results. No new JavaScript is introduced.
