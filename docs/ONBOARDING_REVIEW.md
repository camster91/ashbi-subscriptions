# Reviewed onboarding: billing, retries and publication

## Merchant workflow

1. Choose the plan type and billing durations. Installments require an explicitly
   entered whole number of payments, minimum 2, including the checkout payment.
   The legacy JSON column has no business maximum; REST writes reject values
   outside the cross-language exact-integer range rather than silently truncating
   them or supplying a hidden preset. Existing checkout snapshots are unchanged.
2. Set each enabled duration's price. **Installment prices are total commitments**;
   recurring prices are per-payment amounts. Removing a duration retains prices
   by duration identity, not its former row position. Untouched native product
   prices and one-time purchase settings are not rewritten.
3. Select **Review billing and publication**. This makes no mutation. The review
   lists the total, payment count, cadence, illustrative base checkout/payment
   amounts, final rounding remainder, zero signup fee and no trial. It uses the
   store currency precision and the existing checkout split-payment semantics.
   It is not a tax/shipping/coupon quote or a gateway charge. Checkout remains
   authoritative for those adjustments.
4. Review the choices and tick the confirmation. **Create reviewed draft** is the
   default: group, durations and relations stay draft; a new product stays draft.
   Draft linking does not enable legacy subscription metadata on existing
   products. The plan is not offered to customers until separately activated.
5. Optionally select activation/publication. The review explains the effects and
   a second explicit confirmation precedes writes. All records are first staged
   as drafts and read back before activation. New products are published only
   after the reviewed plan/price records are activated and read back again.
   Existing products are never sent to the publication action: their status is
   unchanged, although activating a plan on an already published product may
   make the reviewed subscriptions available immediately.

The new publication action uses the existing onboarding nonce and capability
boundary. It requires explicit confirmation and a new-product marker owned by
that wizard's administrator; arbitrary linked existing products are refused.
The original creation action now always creates a draft, including requests
from older clients that omit publication choices.

## Retry and uncertainty boundary

The wizard checkpoints the known group, its seeded duration ID, each completed
term and each completed product relation in the current page's controller.
Requests are sequential. Pending ownership blocks retained Continue/Retry/
navigation handlers and overlapping creation flows until settlement. Mutation
bodies are frozen before the first request, including counts, prices, selected
product and publication choice.

A recognized REST pre-write validation/authentication refusal can resume the
same reviewed intent without recreating its group or completed durations.
Changes to the reviewed fields refuse continuation rather than appending stale
records. Restore the original reviewed values to resume that intent, or inspect
and finish/delete the incomplete records through Plans and Products before
starting a different plan.

Group and term POSTs have **no durable server-side idempotency key**. An ambiguous
transport/server failure, unreadable/missing successful record, or failed
independent readback therefore stops automatic resubmission and shows
**Check existing plans and products** guidance. Relations already have a
repository natural-key upsert, but the wizard conservatively checkpoints them
and stops on unknown outcomes too. It does not claim exactly-once creation.

Checkpoints are page-local, not crash/reload recovery. Do not reload and create
another copy after an uncertain request. Open Plans/Products, inspect the known
records, and resolve their state first. Activation spans separate REST writes,
not a transaction: failure can leave some approved records active. No automatic
rollback/deletion is promised, especially on an existing published product.
A new product remains draft unless its explicit final publication succeeds.

## Verification and release boundaries

- `npm run test:onboarding` executes the canonical wizard controller against real
  jQuery/jsdom, including a rendered canonical PHP template. Fetch/AJAX replies
  are explicit HTTP doubles and asynchronous failures use real deferred promises.
- `OnboardingReviewTest` executes actual PHP AJAX/REST callbacks, repository
  serialization/writes/readback and the checkout price model with isolated
  WordPress/WooCommerce/in-memory database doubles. It is not a live REST server,
  MySQL persistence test or gateway transaction.
- Keep PHP unit/compatibility/package, JavaScript syntax, dependency audit,
  security-sensitive standards and static-analysis gates intact.
- Real WordPress/WooCommerce readback, draft/active storefront behavior, keyboard
  and desktop/mobile browser validation, and classic/Blocks purchases remain
  hosted/disposable-runtime gates. No local Docker, preview, browser, client site,
  email delivery or payment-provider operation is authorized by this work.

No table migration is required. Existing subscriptions/orders, recurring/trial
terms, variation mappings and one-time settings are not migrated. Rollback is a
reviewed restoration of the prior package; it does not delete partially created
records or revert a merchant's explicit publication. Record/inspect their IDs
before rollback. Never use the older wizard to blindly repeat an uncertain write.

Production source changes invalidate any previously pinned community-source
snapshot/hash plan. Refresh that plan only after the maintainer freezes and
reviews the combined source; do not bypass the existing stale-source guard.
