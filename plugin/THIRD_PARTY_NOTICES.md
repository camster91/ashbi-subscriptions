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

## Names and logos pending removal

The unmodified upstream package contains vendor, gateway, integration, and
third-party product names and logo/image assets under `assets/images/`. Their
presence in the WordPress.org package is not treated as a trademark license for
Ashbi. They must be removed or replaced before the first Ashbi-branded release,
except for purely nominative references reviewed and approved for compatibility
documentation.
