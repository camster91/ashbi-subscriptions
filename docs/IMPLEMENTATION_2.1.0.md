# 2.1.0 implementation and proposed commits

Work is uncommitted on `feature/variation-frequency-plans`. The sandbox mounts
`.git` read-only: `git add` failed creating `.git/index.lock`. No push, deployment,
network request or live-site operation was performed. The four proposed commits
below assign every changed file. Split the two independent hunks in
`plugin/subscription.php`: CLI registration belongs to commit 3; header/constant
version changes belong to commit 4. Tests are grouped with their implementation.

## Verification

- PHPUnit: **212 tests, 1,701 assertions**, all passing (174 existing tests retained).
- Compatibility contract: **10 metadata keys, 5 hooks**, passing.
- PHP syntax: **29 changed PHP files**, passing on the installed PHP runtime.
- PHPCS on changed maintained PHP files: **0 errors, 0 warnings**.
  The isolated migration fixture is checked separately because its repository
  double intentionally shares the production class name; bundled vendor code
  remains excluded by the existing ruleset. Existing inherited file
  annotations remain; new files use narrowly scoped filename/fixture exemptions.
- Full PHPStan: **no errors** with unchanged project rules and
  `--debug --memory-limit=4G`. The requested ordinary 2 GB run cannot open its
  sandbox loopback listener; full serial analysis at 2 GB exhausted memory.
- JavaScript/JSX: **38 files** parse using the existing syntax-check script with
  the installed system Babel parser. Its default package import is unavailable;
  no dependency was installed. Both changed scripts also pass `node --check`.
- Release-package policy/checksum tests pass against an offline-built
  `/tmp/ashbi-subscriptions-2.1.0.zip` using the canonical exclusion rules.
  The canonical builder itself is unavailable because `rsync` is missing.
- Whitespace check: `git diff --check` passes.

No real WordPress, WooCommerce database, mobile/desktop browser or Stripe sandbox
was available. [Staging QA](QA_VARIATION_PLANS_2.1.0.md) remains pending. The
migration transaction tests use an isolated in-memory repository/storage double;
they do not prove MySQL/WooCommerce runtime behavior.

## Checkout and renewal findings

`PlanCheckout` stamps the selected term/price onto cart and CRUD order lines,
and its existing checkout listener creates the subscription before the classic
simple-product listener. The variation ID and source policy are snapshotted.
`Helper::get_recurrs_from_cart` classifies the subscription array independently
of product type; Stripe cart force-save and existing mixed-cart validation
consume that state. A regression test proved an order-only Stripe force-save
gap before relation creation; it now uses the immutable plan line marker.
`Helper` already creates renewal lines from the purchased variation product and
copies plan/variation metadata. `AutoRenewal` now preserves live-price snapshots
and plan cadence with the updated-product setting. Durable claims, payment
idempotency and Action Scheduler job identities were retained. These claims are
based on code inspection and offline regressions, not a gateway transaction.

## Proposed commit groupings

### 1. `feat: variation term mapping in plan resolution`

- `plugin/assets/js/frontend/plans.js`
- `plugin/includes/Frontend/PlanCheckout.php`
- `plugin/includes/Frontend/Plans.php`
- `plugin/includes/Illuminate/AutoRenewal.php`
- `plugin/includes/Illuminate/Gateways/Stripe/Stripe.php`
- `plugin/includes/Illuminate/Helper.php`
- `plugin/includes/Illuminate/Plans/PlanRepository.php`
- `plugin/includes/Illuminate/Plans/PlanPrice.php`
- `plugin/includes/Utils/Product.php`
- `tests/compatibility-contract.php`
- `tests/unit/PlanBillingRulesTest.php`
- `tests/unit/VariationPurchaseTest.php`
- `tests/unit/VariationRenewalTest.php`
- `tests/unit/VariationStripeTest.php`

### 2. `feat: variation plan migration service`

- `plugin/includes/Illuminate/Migration/FrequencyParser.php`
- `plugin/includes/Illuminate/Migration/VariationPlanPlanner.php`
- `plugin/includes/Illuminate/Migration/VariationPlanMigrator.php`
- `tests/fixtures/run-variation-migration.php`
- `tests/unit/VariationPlansTest.php`
- `tests/unit/VariationMigrationServiceTest.php`

### 3. `feat: migration admin page and WP-CLI command`

- `plugin/includes/Admin.php`
- `plugin/includes/Admin/Product/Plans.php`
- `plugin/includes/Admin/Product/VariationTerms.php`
- `plugin/includes/Admin/VariationMigration.php`
- `plugin/includes/Admin/views/onboarding-wizard.php`
- `plugin/includes/Api/PlanController.php`
- `plugin/includes/Illuminate/Migration/VariationMigrationCommand.php`
- `plugin/assets/js/admin/variation-migration.js`
- `tests/stubs/runtime.php`
- `plugin/subscription.php`

### 4. `chore: release 2.1.0`

- `CHANGELOG.md`
- `README.md`
- `plugin/changelog.txt`
- `plugin/readme.txt`
- `plugin/languages/subscription.pot`
- `plugin/vendor/composer/installed.php`
- `plugin/subscription.php`
- `docs/MIGRATION_2.1.0.md`
- `docs/QA_VARIATION_PLANS_2.1.0.md`
- `docs/IMPLEMENTATION_2.1.0.md`

## Follow-up review fixes

Classic simple subscriptions are report-only unless the fingerprint-bound
`include_simple` setting is enabled through CLI/admin. Ordinary simple products
are omitted from action rows/totals. Variation metadata and detected attributes
must agree before mapping. Unavailable live prices are skipped on display and
produce a customer-safe Exception on cart addition.

These changes stay in the existing groups: storefront and purchase tests in 1;
planner/migrator and transactional fixtures/tests in 2; CLI/admin controls in 3;
migration/QA/implementation documentation in 4. No files moved between groups.
Follow-up checks run offline: PHPUnit, compatibility contract, PHP syntax and
PHPCS on changed PHP files. Real mobile/desktop staging QA remains pending.
