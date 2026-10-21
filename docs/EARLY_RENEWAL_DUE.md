# Early invoices at the billing date

## Scope and acceptance

An unpaid early-renewal invoice represents the next stored billing date. When
that date arrives, reuse that WooCommerce order as the existing durable claim's
canonical order. Keep its amount, coupons, payment identifiers and original
`early-renew` relation. Repeated due jobs must return the same order. Dispatch
continues through the existing gateway consent, cancellation and reconciliation
checks; selecting early renewal never supplies new automatic-payment consent.

Once claimed at its due date, apply paid callbacks through the normal durable
renewal activation and repair path, including when access has expired. Preserve
the early-payment path for invoices paid before their date. A failed/on-hold
invoice must never be recorded as paid just because it needs no immediate dispatch.

Cancelled/refunded early invoices do not reserve a future billing period. A
matching paid invoice requires local activation repair, not another charge.
Ambiguous multiple matching invoices must stop creation for manual review.
Do not rewrite old invoices or automatically cancel historical duplicates.
No schema changes, provider calls, new settings, inbox integration or release.

## Reproduction

Real disposable WooCommerce, HPOS enabled: create an early invoice, move only
fabricated fixture timestamps across the due boundary, then request renewal.
Before the fix the integration assertion reports: “Due renewal created a second
invoice instead of reusing the unpaid early invoice.” Existing invoice amount
and identity remain intact, but two orders exist for the same period.

## Merchant use and rollback

Use the existing subscription links and WooCommerce order screen to inspect the
invoice and payment outcome. Do not ask for payment on a second order for the
same period. Historical duplicates still require payment/provider reconciliation.
Restore the previous plugin files to roll back code; preserve all orders,
relations and durable claims. No data rollback or financial history editing.

## Review and limits

The change reuses `RenewalClaim`, WooCommerce CRUD, existing activation repairs
and the cancellation mutex. No alternate billing state, table or scheduler is
introduced. A claimed early invoice uses normal due-renewal failure/activation
behavior; its database relation and the `early-renew` next-date filter argument
remain unchanged. Unclaimed invoices paid early keep their existing behavior.

A matching invoice retains its quoted price and coupon even if product prices
change later. Automatic payment still requires the existing consent and gateway
checks. Failed/on-hold invoices remain the order to inspect and resolve; they
are not automatically replaced or treated as paid.

Historical conflicts fail closed with a subscription-ID-only diagnostic log.
They do not yet have a dedicated merchant attention filter. This patch does
not reconcile duplicate past charges, invent consent, backfill payment evidence,
or prove real Stripe/PayPal or persistent-cache concurrency. These remain
staging/release gates. No live provider requests were made during local checks.

## Verification — 2026-10-21

- Original real-order due-date reproduction failed before the runtime fix by
  producing a second invoice. The new invoice-selection unit fixture initially
  failed before the selection helper existed.
- Full PHPUnit on PHP 8.2.34: **295 tests / 3,638 assertions**, passed.
- Full guarded source integration on WordPress 7.1.3 / WooCommerce 11.2.1 /
  MariaDB 10.11.19: **HPOS on and off passed**. Added real-order checks cover
  conflicting invoices/claims, an exception during dispatch, repeated jobs,
  failed/on-hold unpaid phases, payment after expiry, repeated payment/activation,
  unchanged quote/identity/history, cancellation, and payment arriving before
  the due worker claims the invoice. Network and mail were blocked locally.
- Full PHPStan (100 files), changed-file WordPress PHPCS, repository nonce/SQL
  checks, compatibility contract (10 metadata keys / 5 hooks), PHP syntax and
  whitespace checks: passed.
- Canonical package verification passed; **139 packaged PHP files** linted,
  integration fixtures excluded. Hosted checks for this new patch are pending.
- Initial local checks exposed a misplaced selection block, an incomplete offline
  order double and a cancellation fixture using a customer as merchant. Corrected
  those before the final passing runs; no production safeguard was relaxed.
