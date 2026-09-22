=== Ashbi Subscriptions ===
Contributors: ashbi
Tags: woocommerce subscriptions, subscriptions, recurring payments, stripe, paypal
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 10.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recurring purchases, subscription management, and automated renewals for WooCommerce stores.

== Description ==

Ashbi Subscriptions adds subscription products and recurring purchase workflows
to WooCommerce while retaining the storage and integration identifiers required
to upgrade existing installations in place.

Core capabilities include:

* Daily, weekly, monthly, and yearly billing schedules.
* Free trials and one-time sign-up fees.
* Automatic renewal orders with Stripe and PayPal support.
* Manual renewal and customer payment-method updates.
* Customer pause, cancellation, and reactivation controls.
* Subscription activity and renewal-order history.
* Fixed-count installment schedules.
* WooCommerce HPOS compatibility.

No commercial upgrade, vendor account, or license activation is required by this
Ashbi-maintained build. Third-party services remain optional adapters and must be
configured independently. For help, use the local Help page in WordPress or
contact the administrator responsible for your managed site.

== Installation ==

1. Back up the WordPress files and database.
2. Upload the plugin so its directory remains named `subscription`.
3. Activate Ashbi Subscriptions from the Plugins screen.
4. Open **Ashbi Subscriptions > Setup Wizard** and confirm the store settings.
5. Test checkout and renewal behavior with gateway sandbox credentials before enabling live sales.

When replacing an existing compatible installation, do not rename the plugin
directory or delete subscription records. Follow the Ashbi migration and rollback
procedure supplied with the release.

== Frequently Asked Questions ==

= Does this require a paid license? =

No. This Ashbi-maintained GPL build has no plugin license fee or upstream account
activation requirement. Managed implementation, hosting, support, and maintenance
may be provided separately.

= Will existing subscriptions remain available after an in-place upgrade? =

The plugin intentionally retains the legacy directory, text domain, hooks, slugs,
option keys, metadata keys, and database identifiers. Always back up and rehearse
the migration on staging before updating a live store.

= Which gateways support automatic renewals in this build? =

Stripe and PayPal renewal integrations are included. Confirm gateway configuration
and complete sandbox renewal tests for each store before enabling production use.

= Where can I get help? =

Open **Ashbi Subscriptions > Help** in WordPress for local guidance, then contact
the administrator or support contact responsible for your managed site.

== Screenshots ==

1. Subscription overview.
2. Subscription list and status filters.
3. Subscription details and renewal history.
4. Product subscription settings.
5. Setup wizard.

== Changelog ==

= 2.0.0 =

* Ashbi-maintained compatibility, renewal-safety, security, packaging, and branding work.
* Retains the legacy plugin identity and storage identifiers for in-place upgrades.

== Upgrade Notice ==

= 2.0.0 =

Back up the full site and rehearse this update on staging before replacing an
existing subscription installation.
