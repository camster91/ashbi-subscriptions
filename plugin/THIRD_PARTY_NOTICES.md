# Third-party notices

This file describes third-party code bundled in the distributable plugin. It
does not cover libraries supplied at runtime by WordPress, WooCommerce, or a
payment-gateway plugin.

## Chart.js 4.5.1

- File: `assets/js/chart.js`
- Project: https://www.chartjs.org/
- Source tag: https://github.com/chartjs/Chart.js/tree/v4.5.1
- License: MIT
- License text: `licenses/Chart.js-MIT.txt`
- Bundled file header: Copyright 2025 Chart.js Contributors

## Composer runtime

- Files: `vendor/autoload.php` and `vendor/composer/`
- Project: https://getcomposer.org/
- License: MIT
- Copyright: Nils Adermann and Jordi Boggiano
- License text: `vendor/composer/LICENSE`

`vendor/composer/installed.json` reports no bundled Composer packages; these
files are the Composer-generated autoload runtime only.

## Runtime-provided dependencies

The plugin requests WordPress- or WooCommerce-provided handles including
jQuery and WordPress packages. Those libraries are not copied into this plugin
and remain governed by the licenses of the host installation.

## Nominative integration names

The admin integration catalog uses provider names to identify optional payment,
learning, CRM, automation, email, and licensing adapters. These are nominative
compatibility references, not Ashbi branding or endorsements. Built-in cards
render Ashbi-neutral initials rather than provider logos, and the release
packager excludes the imported third-party logo directories and unused upstream
image assets. Any future provider artwork requires a separate license and legal
review before distribution.
