# Recurring coupon payment limits

## Small implementation slice

The existing recurring-coupon filter documents its limit as payments including
checkout. Unpaid, failed, abandoned and duplicate related-order links currently
consume that limit in both the account price and renewal builder. Reuse
`subscrpt_count_payments_made()` (WooCommerce paid states, unique eligible order
IDs) for both calculations. No new counter, table, option or historical inference. Read payment history only
for finite limits greater than one; first-payment-only discounts never recur,
including when legacy relation history is absent. Unlimited and non-recurring
coupons need no payment-history scan.

Verify a finite discount survives failed/pending/duplicate links, expires after
its paid-payment limit, and updates within the same request after an order becomes
paid or the price changes. Remove unsafe request-static discount memoization if
it masks those changes; use WooCommerce's existing object caching. Keep coupon
classification, filter signatures, proportional pricing/tax and stored orders.

Tests first: isolated actual-helper regressions, then real WordPress/WooCommerce
source integration with HPOS on/off. Inspect pending-invoice totals before/after;
never reprice saved invoices, reapply usage limits, rewrite acceptance or contact
providers. Do not create a coupon-management UI or broaden discount policy.

Compatibility: reuse the existing paid-payment definition and payment-order-type
filter. Processing/completed count through WooCommerce; refunded/unpaid states do
not, and duplicate links count once. Missing/deleted orders do not invent payment
proof. Existing refunds and invoices remain untouched. Roll back only the helper
changes; preserve all financial data and existing billing/cancellation safeguards.


## Merchant use

For a store already using recurring-coupon rules, the payment limit includes the
initial paid order. A failed or unpaid renewal does not use another payment's
allowance. Processing and completed orders count once through WooCommerce, even
if old relation rows are duplicated. Fully refunded orders follow the existing
paid-payment counter policy and do not count. Existing invoices retain their
saved amounts; this change does not reprice them or issue refunds.

No new coupon editor or setting is added. Existing coupon eligibility/limit
filters and proportional price/tax calculations remain in place. Verify the next
renewal price in the account/admin screen and the actual WooCommerce order.

## Verification

The isolated actual-helper regressions failed first: relation rows exhausted a
finite discount, and request-static results hid a changed price. A further failing
case proved first-payment-only coupons could recur without legacy history. The
focused suite now passes 2 tests / 16 assertions; the full suite passes **296 tests
/ 3,652 assertions**. Both display and renewal construction use the existing paid
counter, preserving its deduplication, relation-type and WooCommerce paid-state
policy. No new counting query or financial-state store was introduced.

The complete disposable WordPress/WooCommerce source harness passes with HPOS off
and on (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.2.34, MariaDB 10.11.19). Seven
additional real-order checks cover failed/pending/refunded/duplicate history,
finite expiry, proportional discount after price change, same-request refresh and
an unchanged saved pending invoice. These exercise actual WooCommerce coupon
lines and the renewal constructor; there is no provider charge or sandbox claim.

Compatibility, full PHPStan (100 configured files), changed/new-file WordPress
PHPCS, nonce/prepared-SQL checks and whitespace checks pass. Release-package
results are recorded on the PR. The initial local integration launcher referenced
an older worktree and failed with a duplicate callback declaration; pointing it at
this source resolved that harness setup error. No production guard was changed.

Next priorities: provider sandbox/crash windows, review the separate checkout and
request-queue PRs, and installed-form/site-specific workflow verification. Defer a
new coupon editor, historical invoice repricing, discount-policy rewrites and new
dashboards. Any old incorrect invoice needs explicit review rather than inferred
historical coupon or payment evidence.
