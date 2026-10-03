# Variation plans in 2.1.0

Ashbi Subscriptions can map one concrete variation to one existing plan term.
The relation uses the unchanged `subscrpt_plan_relation` table: parent `oid`,
variation `vid`, term `plan_id`, and `data={"price_source":"variation"}`. There
is no typed regular-price override and no schema or DB_VERSION change.

The parent opts in through `_subscrpt_variation_term_mode=yes`. Resolution then
uses only the selected variation's own relations. Product-level (`vid=0`) seeds
are suppressed, including the manually entered Subscribe & Save stopgap prices.
An unmapped One Time variation has no plan and remains a normal WooCommerce
purchase, even if it retains old subscription-enabled metadata. Products outside
this mode keep the original inherited-plan resolution and selector.

The price comes from the variation's sale-aware WooCommerce `get_price()` at
add-to-cart. Missing, invalid or negative live prices are skipped in storefront
term displays/selector contexts, so they cannot crash the product page. Cart
addition rejects unavailable terms with a customer-safe notice. The existing plan flow stores that amount in the cart, order line
and subscription (`_subscrpt_price`), plus its cadence and variation ID. Later
renewals use that snapshot. Mapped live-price subscriptions ignore the store's
“updated product prices” renewal setting; an explicit per-subscription custom
renewal-price override still works. Their snapshot includes
`_subscrpt_plan_data.ashbi_price_source`, so removing mappings later does not
change an existing customer's pricing policy. Supported migrated trials have a
zero recurring line at initial checkout; the recurring snapshot and one-time
signup fee remain separate.

Programmatic `WC()->cart->add_to_cart($parent, $qty, $variation, $attributes)`
auto-selects the single mapped term. An unrelated posted plan ID cannot change
that selection. The product page shows a small read-only delivery cadence when
WooCommerce resolves a mapped variation, rather than offering another choice of
terms. Choosing One Time or resetting attributes clears the note.

## Why the earlier setup migrated nothing

The prior `Upgrade` class migrated only pre-1.1.0 `subscrpt_general` metadata.
It had no product/variation-to-plan importer. The imported public free core did
not implement classic per-variation subscription purchases. A delivery-frequency
attribute name or value does not itself create subscription metadata or a plan
relation. Manually connecting three terms to a parent made those terms inherited
choices for every child; it did not map each frequency variation to its own term.

## Run per site, beginning with a dry run

Back up the site's database and plugin package and use an isolated staging copy
first. This migration never requests a payment, updates an order/subscription,
changes stock/prices/tokens, or makes a network call. Production activation and
client deployment still require separate human authorization.

In **Ashbi Subscriptions → Migration**:

1. Keep the default group title **Subscribe & Save**, or choose another title.
2. Leave the attribute filter blank for auto-detection, or enter
   `delivery-frequency` (a taxonomy attribute may need `pa_delivery-frequency`).
3. Keep **Also link classic simple subscription products** unchecked (report-only
   by default). Keep **Also remove product-level stopgap relations** unchecked initially.
4. Click **Scan (dry run)** and review every product and variation.
5. Check the confirmation box and click **Apply reviewed scan**. Review expires
   after 30 minutes and is tied to your administrator account and login session.
   Apply re-plans under the shared lock and refuses changed fingerprints or
   settings. Scan again after any catalogue/plan edits.
6. Re-run the scan/apply: mappings should be `already_linked`, with zero additional
   rows or metadata changes. A no-change apply retains the previous rollback record.

Access requires `manage_woocommerce`; every form action verifies a nonce. The
native product editor's **Variations** panels also offer **Subscription term
(variation price)** / **None — one-time**. Select a term and save the variation to
enable mapping on its parent. Clearing a mapping removes only live-price mappings;
it does not delete typed legacy connections. The parent **General** panel has
**Map variations to subscription terms**. Turning it off restores legacy
inheritance, including retained stopgap rows; preview the resulting storefront
before doing so. The old typed-price editor/API cannot overwrite live mappings.
The wizard's product-connection step links to Migration.

WP-CLI, on each site's WordPress installation:

```sh
wp ashbi-subscriptions migrate-variations --format=json
wp ashbi-subscriptions migrate-variations --attribute=delivery-frequency --product=123,456
wp ashbi-subscriptions migrate-variations --apply --yes --attribute=delivery-frequency --product=123,456
wp ashbi-subscriptions migrate-variations --include-simple --product=123 --format=json
wp ashbi-subscriptions migrate-variations --group-title='Subscribe & Save' --format=yaml
```

The default is dry run. `--apply` prompts for confirmation unless `--yes` is
provided; it still re-plans and compares a fingerprint under the lock. Unlike the
admin UI, CLI apply does not require a previous browser scan. Use the same flags
as your reviewed CLI dry run. Formats are `table`, `json`, and `yaml`; JSON/YAML
include the full structured report in a one-element list. Conflicts/manual-review
results exit non-zero, even when other safe products were applied. Inspect the
report before retrying. `--product` accepts positive parent/simple IDs, not
variation IDs. Invalid/unavailable IDs refuse the scan.

`--remove-product-level-relations` is explicit opt-in removal. It removes only
product-level relations on successfully mapped variable products, records exact
prior rows for rollback, and never removes relations on conflicted products.
The default retains and reports them. Do not select removal until the dry run
and staging purchases prove that suppressing those rows is correct.

Auto-detection considers frequency-related attribute names or recognized values;
unrecognized values are skipped. A site-wide default filter can be set in the
`subscrpt_variation_migration_attribute` option; the CLI/UI field overrides it.
Products are scanned in pages of 50 IDs. Reports are capped at 10,000 products
and 10,000 purchasable entities; use `--product` batches for larger catalogues.

## Detection and conflicts

- Legacy enabled variation metadata with a valid positive integer
  `_subscrpt_timing_per` and `days|weeks|months|years` is compared with detected
  frequency attributes. Different frequency or interval produces `manual_review`
  naming both cadences and blocks the parent. Agreement retains `legacy_meta`.
- Enabled classic simple products default to `classic_simple` with the note:
  “Classic simple subscription product (possible DWL target); report only.
  Re-run with --include-simple to link.” No product/plan writes occur for them.
  Review DWL's actual target before opting in with `--include-simple` or
  **Also link classic simple subscription products**. Opted-in mappings use
  `price_source=product` and retain the existing one-time/default-plan policy.
  This setting defaults off and is included in normalization and the fingerprint;
  changing it requires a fresh review. Ordinary simple products without enabled
  subscription metadata have no report rows and contribute no action totals.
- Recognized labels include 1-Month Subscription, Every 2 months, Monthly,
  Quarterly, Every 6 weeks, Weekly, Biweekly, Every other week, Annual, Yearly,
  and 30 days. Bi-monthly means every two months and carries an ambiguity note.
- One Time, One-time purchase, and Single purchase do not map. An unrecognized
  frequency is reported as skipped; it is not guessed.
- Trials and non-negative numeric signup fees use the existing term columns and
  trial-interval data. Invalid financial metadata, finite classic payment limits,
  or contradictory frequency attributes require manual review.
- Reuse requires matching cadence **and** trial/signup-fee/lifecycle semantics.
  An existing cadence with different semantics is a conflict. No existing term
  is changed to accommodate another product.
- Existing different, excluded, inactive, typed-price or multiple variation
  relations are conflicts; the importer never overwrites them.
- Two variations of one parent resolving to the same cadence are ambiguous.
  The whole product is skipped; no partial mode switch is made. Review manually
  (for example, size × frequency products need explicit administrator choices).
- A One Time/unrecognized child with an existing relation conflicts and blocks
  its parent rather than silently selling that child as a subscription.

The report includes per-entity source, frequency, action, notes, totals, mode,
UTC timestamp, site URL and fingerprint. It is saved in the non-autoloaded
`subscrpt_variation_migration_report` option. A short aggregate line is written
through the redacting plugin logger. Reports contain catalogue configuration,
not customer identities, payment data or webhook bodies.

## Locking, write failures and rollback

Apply/editor changes share a MySQL connection mutex and a non-autoloaded visible
option lock. A crashed worker releases the mutex when its connection dies; a
subsequent worker replaces the stale display option. Apply requires InnoDB plan,
postmeta and options tables and refuses nontransactional storage before writing.
Plan rows, metadata and the rollback journal commit together. A failed write
rolls back all those writes and clears plan/product/meta/option caches.

The non-autoloaded `subscrpt_variation_migration_journal` records created relation,
term and group rows, each changed meta key's prior existence/value, and removed
stopgap rows. Only the last non-empty apply is reversible through this tool.
Before a subsequent non-empty batch apply, retain the report/journal with your
site backup if you need to undo multiple batches.

```sh
wp ashbi-subscriptions migrate-variations rollback --format=json
wp ashbi-subscriptions migrate-variations rollback --apply --yes
```

Rollback defaults to preview. The admin page also has **Preview rollback** and
**Rollback last apply**, with nonce/capability and confirmation checks. Rollback
re-checks ownership under the mutex: edited rows/meta, new term/group connections,
or conflicting replacement stopgaps cause refusal with no catalogue changes.
It restores exact stopgap IDs and previous metadata, then removes only the rows
it created. It never changes existing subscriptions or orders; their stored
prices, periods, variation targets and renewal claims remain intact. Removing a
created term can remove it as a future switching choice, while old subscription
snapshots continue to renew. Restoring the full backup remains the fallback for
catalogues edited after apply or for broader multi-batch rollback.

## Verification boundary

The unit suite exercises parser/planner behavior, production plan resolution,
programmatic cart selection and purchase pricing, mapped-trial initial pricing,
Stripe order-only force-save, immutable renewal filters, and migration apply/
rollback using isolated transactional storage doubles. These are offline tests,
not a real WordPress database or Stripe sandbox proof. Complete
[the staging checklist](QA_VARIATION_PLANS_2.1.0.md) on mobile and desktop before
client rollout, especially WooCommerce Blocks and the installed DWL/Stripe stack.
