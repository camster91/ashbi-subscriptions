# Upstream provenance: subscription 2.0.0

## Immutable source record

- WordPress.org slug: `subscription`
- Upstream name: Subscriptions for WooCommerce with Stripe Recurring Payments
- Version: `2.0.0`
- Source URL: `https://downloads.wordpress.org/plugin/subscription.2.0.0.zip`
- Canonical latest URL checked: `https://downloads.wordpress.org/plugin/subscription.zip`
- Retrieved: `2026-09-14` in America/Toronto
- ZIP SHA-256: `232e0eb4bfb5535d1ddcb4de19aba2d60f2461b14f5af1c1354919faa9842a90`
- Extracted file count: `224`
- Import path: `plugin/`
- Import commit: `5508935` (`chore: import GPL upstream subscription v2.0.0`)

The versioned and canonical ZIP URLs produced the inspected WordPress.org
release. The `plugin/` tree was imported without source, asset, build, or
bundled Composer changes. Run `scripts/verify-upstream.sh` to download the
versioned ZIP, verify its hash, and compare its extracted tree byte-for-byte
against immutable import commit `5508935`.

## Declared licenses

- Plugin header: GPLv2 or later.
- Root `composer.json`: `GPL-2.0-or-later`.
- Bundled Composer runtime: Composer copyright and MIT license retained at
  `plugin/vendor/composer/LICENSE`.
- Bundled Chart.js file declares Chart.js 4.5.1 and carries its upstream MIT
  header. The upstream MIT license is included at
  `plugin/licenses/Chart.js-MIT.txt` and catalogued in
  `plugin/THIRD_PARTY_NOTICES.md`.
- No additional Composer packages are bundled; `vendor/composer/installed.json`
  contains an empty package list. The Composer autoload runtime retains its MIT
  license at `plugin/vendor/composer/LICENSE`.

No Pro package, account download, license key, proprietary update payload, or
third-party mirror was used.

## Baseline observations

- The plugin loads `plugin/vendor/autoload.php`; bundled vendor files are
  therefore part of the distributable baseline.
- The package includes compiled `plugin/build/` assets; these are also part of
  the distributable baseline.
- Vendor marketing, documentation, support, upgrade, and video URLs exist in
  the public source and must be removed or replaced in the separate rebrand
  phase.
- Live PayPal API endpoints and WordPress.org links exist. External endpoints
  are catalogued separately before behavior changes.

## Reproduction

```bash
scripts/verify-upstream.sh
```

Expected result:

```text
Verified subscription 2.0.0 (232e0eb4bfb5535d1ddcb4de19aba2d60f2461b14f5af1c1354919faa9842a90) against import commit 5508935.
```
