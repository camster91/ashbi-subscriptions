# Small-store workflow review — 2026-10-20

Reviewed main `c70272f` (merged PR #44), the preceding consent/variation fixes,
all existing `docs/` guidance and open PRs #8, #9, #12, #45 and #46 before editing.
This change is based on main and does not merge or replace those branches.
No production store, provider, customer record, setting, email or release was touched.

## Ranked work

1. **Checkout/account access:** review and land [PR #46](https://github.com/camster91/ashbi-subscriptions/pull/46).
   Main rejects singular simple-product billing units when explicit contract
   consent is enabled; normal admin permalink rebuilds omit account endpoints.
   Its focused tests and fixes already exist. Do not duplicate them here.
2. **Cancellation clarity (this patch):** remove an impossible Reactivate action
   after protected customer cancellation, show the full Pending Cancellation
   label, and stop presenting retained billing dates as future payments after
   cancellation. Preserve legacy reversible cancellations and all billing guards.
3. **Merchant follow-through:** finish reviewing [PR #45](https://github.com/camster91/ashbi-subscriptions/pull/45)
   and add a focused needs-attention filter to the existing subscription list for
   recorded cancellations awaiting confirmation. Reuse barrier/evidence records;
   distinguish access ending later from an unconfirmed provider stop.
4. **Prove billing boundaries:** complete real sandbox renewals, customer
   cancellation/provider outages, crash recovery, coupon payment limits and
   timezone/cache cases before production approval. Local doubles are insufficient.
5. **Defer feature growth:** no new reporting dashboard, cohort/recovery campaign,
   retention offer, switching engine, delivery schedule or integration expansion
   until the above work is proven. Reuse WooCommerce orders/emails/coupons and
   Action Scheduler. Do not remove existing compatibility/evidence tables or
   features used by stores without an inventory and migration plan.

## Workflow findings and evidence

| Workflow | Assessment on reviewed main | Evidence / practical limit |
| --- | --- | --- |
| Select product, cart, create subscription | Implemented, with recent corrective work | `Frontend/Cart.php`, `Checkout.php`, `PlanCheckout.php`; `CLASSIC_VARIATION_CART.md` describes preserved variation/interval snapshots. Trial/fee variation coverage remains narrower than the feature matrix implies. |
| Complete checkout with consent | Bug plus deliberate unsupported routes | `ContractConsent::valid_snapshot()` accepts only plural units on main. PR #46 handles the existing singular units. Consent is disabled by default; enabling it rejects Blocks subscription checkout and disables registered express routes. Do not enable consent on an unverified checkout. No historical acceptance is inferred. |
| Recurring price and discount | Implemented with WooCommerce amounts; incomplete merchant controls | `Helper::build_cart_recurring_price_data()`, `get_subscription_display_totals()` and `add_recurring_coupons_to_renewal_order()` distinguish recurring from first-order discounts. Recurring eligibility/limits are filters, not a complete coupon editor. Price precedence is custom override, configured updated product, then purchase snapshot; mapped live-price plans retain snapshots (`AutoRenewal.php`). |
| Coupon payment limits | Needs targeted billing verification | Limit calculations count relation rows, including potentially unpaid early orders, rather than paid installments (`Helper::get_subscription_recurring_discount()` and `add_recurring_coupons_to_renewal_order()`). Verify abandoned early renewals before promising a discount for a number of successful payments. Do not rewrite existing financial history. |
| Account list/detail | Works in code; route and screen defects | `MyAccount.php` checks login, owner and post type; saved Stripe tokens use WooCommerce ownership checks. PR #46 fixes admin route registration. This patch fixes misleading cancellation dates and truncated labels. Theme template overrides can retain old output. |
| Customer cancellation | Substantial durable protection already implemented | `ActionController` verifies login/nonce/ownership, calls `CancellationEvidence::request()`, and displays confirmed/pending/failure accurately. Intent is inserted before the dispatch mutex. `Helper` and Stripe respect the barrier; PayPal requires remote acknowledgement. A running payment can still require review. |
| Reopen cancelled subscription | Confirmed screen/eligibility bug, fixed here | The account showed Reactivate for every `pe_cancelled` record; its eligibility predicate ignored the immutable barrier, while `Action::status()` refused reopening. The action could silently do nothing. Predicate and screen now agree, including fail-closed storage errors and elapsed legacy access. |
| Cancellation completion and email | Existing state protection, limited notification proof | `CancellationEvidence::repair()/sweep()` and `Cancellation::cancel()` preserve intent and repair state. Mail failure cannot remove the barrier. The cancelled email follows lifecycle state; it is not independent proof of provider acknowledgement or email delivery. There is no new delivery tracking in this patch. |
| Contact form/email cancellation request | Incomplete in core; separate PR available | No core intake queue was found. PR #45 uses private WordPress posts for received messages, dates, open/closed outcomes and review actions; it is optional, Elementor-specific and excluded from the core ZIP. It does not monitor an email inbox or backfill old requests. Its closure requires cancellation proof. A saved message is never cancellation. |
| Merchant attention and history | Useful existing screens; incomplete unified attention view | Existing subscription list filters include On Hold and Pending Cancellation; detail pages link customer/order/payment and read legacy/current activity notes. `EvidenceExport` exposes bounded authorized evidence with explicit limitations. No dedicated provider-confirmation backlog filter exists. Admin status cancellation has its legacy lifecycle semantics, distinct from the customer's immutable request workflow. Verify each route separately. |
| Scheduling and overhead | Durable claims/retries; remaining maintenance debt | `RenewalClaim` provides a canonical order per period; Stripe and cancellation repairs use Action Scheduler with hourly recovery. `Cron` still uses WP-Cron for hourly scans and `Email` for delayed expiry mail. Cron/report queries include unbounded scans. Migrate selectively to bounded scheduled work, preserving due jobs and data. |

Source paths above are under `plugin/includes/` unless stated otherwise.
No end-to-end purchase, provider charge, inbox delivery or browser usability
success is claimed from source inspection.

## What changed

The actual account controller now uses the same reactivation predicate as the
request handler. The predicate rejects a durable cancellation barrier or unreadable
barrier storage. Legacy scheduled cancellations without a barrier remain reversible
only before their access end and payment limit. A rejected direct action tells the
customer to contact the store rather than suggesting payment can reopen a barrier.

Both account templates stop showing a next payment for `pe_cancelled`/`cancelled`.
The list retains its column with a dash and shows full status text. This does not
assert that an already-processing payment has been refunded or a provider has
confirmed cancellation. Original dates, access-end notices, consent and history
remain available and unchanged in storage. No schema migration or new dependency.

Follow-up regression: the shared display data also attached payment grace to
`pe_cancelled` when an old billing date was in the last seven days. Customer and
merchant templates then displayed Active instead of Pending Cancellation. Exclude
pending cancellation from this display grace calculation; existing payment-failure
grace semantics remain unchanged. This changes no entitlement, billing or dates.

## Verification

- Baseline PHP suite: 287 tests / 3,470 assertions passed on PHP 8.2.34.
- New account regressions failed before the patch (barrier incorrectly eligible;
  cancelled detail still advertised a payment). Both passed afterward.
- Updated PHP suite: 290 tests / 3,503 assertions passed. Includes real-controller
  owner cancellation with mail failure/replay and actual account/template rendering
  against isolated WordPress/WooCommerce/database doubles.
- Compatibility contract: 10 metadata keys / 5 hooks passed.
- Canonical release-package check: passed, including 137 packaged PHP files.
  Initial container failures were missing zip, Git mount ownership and old mawk's
  unsupported interval expressions; adding tools/config inside the disposable
  container resolved them without changing repository checks.
- JavaScript/JSX syntax: 38 files passed; integration-tooling compatibility passed.
- Full PHPStan: no errors. PHP syntax, security-sensitive PHPCS (nonce/prepared SQL)
  and `git diff --check` passed. Full PHPCS on the new PHPUnit class also passed;
  this does not claim the repository's inherited coding-standard debt is fixed.
- WordPress integration was attempted but did not start: wp-env's configuration
  network request failed with ECONNREFUSED; a local-source/proxy retry did not
  become ready. The added real-database barrier/reactivation assertion is therefore
  **not locally verified**. Existing PR #45/#46 CI results belong to those refs,
  not this patch. Gateway sandbox, concurrent real MySQL execution, HPOS matrix,
  mobile/keyboard, timezone/DST and persistent-object-cache runtime checks remain
  required. Existing offline race/replay/failure suites are supporting evidence.

Hosted evidence for PR head `78afa50` (workflow merge `28a2bce`) subsequently passed: PHP 7.4/8.2/8.5,
source WordPress/WooCommerce integration with HPOS on/off, and exact candidate
ZIP validation with HPOS on/off. See [source CI](https://github.com/camster91/ashbi-subscriptions/actions/runs/39497056706)
and [candidate validation](https://github.com/camster91/ashbi-subscriptions/actions/runs/39497056715).
The nonblocking coding-standard baseline reported two errors in the new screen
fixture's file header; corrected in the follow-up rather than treating them as
inherited debt. The added grace regression failed before its one-line fix;
the updated suite passed 291 tests / 3,516 assertions, with package, PHPStan,
syntax and security-sensitive PHPCS checks passing locally.
Hosted results for `78afa50` do not validate later commits or real providers.

## Merchant use and next three priorities

Customers open **My Account → Subscriptions → View → Cancel**. A confirmed notice
means future automatic billing is blocked; a pending notice requires staff review.
Pending Cancellation can also mean access continues until the displayed end date.
The account no longer offers an unusable Reactivate button or advertises another
payment after cancellation. Do not promise an automatic refund of an in-flight charge.

Each day, review **Ashbi Subscriptions → Subscriptions**, especially **On Hold**
and **Pending Cancellation**, and open the related WooCommerce order for the actual
payment result. Read the activity/evidence history rather than relying on a status
badge alone. Check the support inbox and existing form submissions separately.
Verify the sender and subscription, process cancellation using an authorized
workflow, verify the outcome and reply. Do not mark a message handled merely
because it was saved or an email was sent. No automated free-text cancellation.

The next three priorities are: land PR #46; finish the request/confirmation
attention workflow around PR #45 and existing records; run the real billing and
cancellation matrix, including failed/abandoned renewal coupon limits.

Rollback this presentation patch by restoring the four changed frontend/template
files and `Helper.php` from protective main `c70272f`. Preserve the installed cancellation engine,
barriers, orders and evidence. No database rollback is needed or authorized.
