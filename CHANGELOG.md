# Changelog

All notable Ashbi-maintained changes after the immutable upstream import are
documented here. The original vendor changelog remains at `plugin/changelog.txt`.

## Unreleased

### Fixed

- Preserve the stored variation ID during manual subscription renewal.
- Avoid an undefined variable when persisting PayPal product metadata.
- Catch global exceptions correctly from namespaced plugin code.
- Replace a PHP 8-only string helper to retain declared PHP 7.4 compatibility.
- Return the Store API validation error object on successful validation.
- Initialize the next-date value before extension filters run.
- Replace a dormant integration debug fatal with a capability-checked JSON response.

### Security and operations

- Add a read-only, privacy-minimal fleet migration audit.
- Validate HPOS order references against the active WooCommerce order store.
