# Staging QA: variation plans 2.1.0

Target: `ashbisubscriptions.example.org`. This checklist is **pending**; no
WordPress runtime, staging site or gateway was accessed during implementation.
Use fabricated customers, Stripe test mode, suppressed email, and a verified
staging database/package backup. Do not activate live gateway mode. Record the
plugin version, WooCommerce/Stripe/DWL versions, HPOS mode and test evidence.

## Migration and controls

- [ ] Confirm header/constant/stable tag report 2.1.0. Activate only this fork.
- [ ] Snapshot counts of products, variations, subscription/order/claim/token
      records and plan rows, plus representative prices/stock and renewal dates.
- [ ] Run the default CLI dry run and the Migration page Scan. Both make no
      product/plan writes; report options are non-autoloaded. Check representative target
      variable products and every frequency/One Time child.
- [ ] Review default auto-detection and explicit `delivery-frequency` filtering.
      Classic simple subscriptions show `classic_simple` and remain unchanged
      after default apply; ordinary simple products add no rows/action totals.
- [ ] Review DWL targets, then scan with `--include-simple` / **Also link classic
      simple subscription products**. Only this opt-in permits simple mappings.
      Changing it after an admin scan refuses apply until a fresh scan.
- [ ] Give a variation legacy monthly timing plus a 2-Month Subscription attribute,
      then a weekly attribute: both show `manual_review` naming both cadences,
      and no parent writes occur. Matching monthly values retain `legacy_meta`.
- [ ] Confirm 1/2/3-month terms reuse the intended active Subscribe & Save group.
      Check trials/signup fees against original metadata where present.
- [ ] Verify different existing mappings, excluded/inactive mappings, typed child
      rows, duplicate cadence children, contradictory attributes and unsupported
      limits are conflicts; affected parents get no partial writes.
- [ ] Default stopgap rows remain; report says they will be suppressed.
- [ ] Apply is unavailable before a session dry run/confirmation. Another login
      cannot use that review; an expired review requires a new scan.
- [ ] Change a term/product/filter after scan: apply refuses the stale digest.
- [ ] Test capability rejection as a customer and nonce rejection for each action.
      Test variation IDs owned by another parent cannot be modified.
- [ ] Apply once: exact `vid` relations contain only `price_source=variation`;
      parent mode and mapped enable/connected markers are set. No subscription,
      order, token, claim, price or stock record changes.
- [ ] Re-run scan/apply twice: `already_linked`; zero row/meta changes; the first
      non-empty apply's rollback journal remains available.
- [ ] Attempt simultaneous CLI/admin applies: only one owns the mutex. Inject a
      write failure in the disposable clone and verify full transactional rollback.
- [ ] Add a new term to the group: mapped variations do not gain a second term.
- [ ] Native editor: change a term, clear one to None, save via full product save
      and variation AJAX. Verify mode toggle behavior and typed-row refusal.

## Product page and purchases (mobile and desktop)

- [ ] Test keyboard/touch attribute selection, initial defaults, reset and switching
      frequency repeatedly. No multi-term selector appears in mapping mode.
- [ ] 1-Month / 2-Month / 3-Month each show the correct read-only cadence. One Time
      shows no cadence note. Notes never linger after reset or one-time selection.
- [ ] Each selected variation uses its native WooCommerce price. Test an active
      sale and a scheduled/expired sale; typed stopgap prices must have no effect.
- [ ] Remove a mapped live price on the disposable clone (also test invalid and
      negative prices): display skips that term without errors or empty term
      groups; direct add-to-cart shows a safe unavailable-subscription notice.
      Restore the price and confirm the term returns.
- [ ] Add mapped variation with no plan POST field via a direct/programmatic add.
      Confirm the correct plan ID, cadence, quantity and recurring total.
- [ ] Submit an unrelated parent/other-variation plan ID: exact mapping wins.
- [ ] One Time stays a plain item with no plan/subscription data and no subscription
      record. Include a child that still has legacy enabled metadata.
- [ ] Preserve existing mixed-cart policy: mapped subscriptions cannot be mixed
      with one-time products or a second subscription. One-time-only carts work.
- [ ] Complete classic checkout with a Stripe test card. Payment method is saved
      for future use; a single subscription is created with correct parent ID,
      variation ID, plan/group, period, quantity and `_subscrpt_price` snapshot.
- [ ] Repeat through WooCommerce Blocks and order-pay/retry. Confirm force-save
      recognizes order-line plan metadata before subscription relations exist.
- [ ] If a migrated term has a trial, initial recurring line is zero, signup fee
      is charged once, and recurring snapshot/next date remain correct. Confirm
      customer-facing trial disclosure and the site's trial eligibility rules.
- [ ] Check cancellation permissions/customer account presentation for the actual
      variations; legacy per-variation settings remain operator-controlled.

## DWL programmatic add-to-cart

- [ ] Exercise the installed DWL Custom Add to Cart UI, not only a console call.
- [ ] Confirm its actual `$product_id`, `$variation_id`, attributes and quantity
      target. A mapped variation auto-selects with no request parameter.
- [ ] If DWL substitutes a classic simple subscription product, review/apply that
      product explicitly with `--include-simple` (or the admin checkbox) and verify its retained one-time/default-plan policy.
- [ ] A substituted one-time target remains one-time; no parent plan leaks in.

## Renewals and rollback

- [ ] After purchase, change variation price/sale and a variable default. Renewal
      still targets the purchased variation and snapshotted price/cadence.
- [ ] Test subscribed and updated-product renewal settings: live-price mappings
      stay on the purchase snapshot. Test an explicit custom renewal price.
- [ ] Use Renew now / the applicable manual renewal path and a due Action Scheduler
      job with Stripe off-session test payment. Inspect renewal line variation ID,
      price, quantity, plan/term metadata, payment token reference and next date.
- [ ] Replay/concurrently trigger the same due job. One canonical renewal claim,
      one order/payment, and one schedule advance. No repeated signup fee/trial.
- [ ] Test declined/authentication-required payment and retry without duplicate
      orders or charges. Verify saved payment-method recovery behavior.
- [ ] Preview rollback: no changes. Apply rollback: only created plan rows/meta
      disappear; retained seeds become visible under prior mode. Existing new and
      old subscriptions/orders still retain snapshots and renew correctly.
- [ ] Separate rehearsal: explicitly remove stopgaps, then rollback. Their IDs,
      data, statuses and natural keys are restored exactly.
- [ ] Edit an owned relation/meta or attach another product to a created term.
      Rollback refuses without partial changes; verify full-backup fallback.
- [ ] Reconcile all before/after counts and unchanged customer/payment/lifecycle
      data. Save evidence and get client approval before any production rollout.
