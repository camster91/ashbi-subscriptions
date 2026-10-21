=== Ashbi Subscriptions ===
Contributors: camster91
Tags: woocommerce subscriptions, subscriptions, recurring payments, stripe, paypal
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 2.2.0
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 11.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Subscriptions for small WooCommerce stores. Clear recurring orders, customer account controls and cancellation confirmation. No plugin license fee.

== Description ==

Ashbi Subscriptions adds recurring purchases to WooCommerce, with subscription
records linked to WooCommerce customers, orders and payment history. It retains
the storage and integration identifiers required to upgrade existing installations in place.

**Keep everyday work in WooCommerce**

* Create subscription products with daily, weekly, monthly or yearly schedules.
* Show the recurring price and billing terms before purchase.
* Use WooCommerce orders and coupons for checkout and renewal invoices.
* Let customers view subscriptions and request cancellation in My Account.
* See the saved cancellation result, related orders and subscription activity.
* Review failed payments and cancellations that need attention.
* Use WooCommerce HPOS or traditional order storage.

**Cancellation has a saved result**

A confirmed cancellation blocks future renewals. Depending on the store's policy,
access may continue until the end of the paid period. Email delivery cannot undo
a saved cancellation. A contact-form message or email alone does not cancel a
subscription: the merchant must verify the request, cancel the correct record,
check the saved result and reply. The optional contact-request review adapter is
separate from this plugin ZIP; this plugin does not monitor an email inbox.

**Payment integrations and limits**

Stripe and PayPal integrations are included. Automatic billing requires a
supported, configured gateway and valid customer authorization. Test checkout,
renewals, failures and cancellation in sandbox mode before live sales. Local
regression tests do not certify live provider payments. Saved Stripe payment
methods can be updated; replacing a PayPal billing agreement locally is not
supported. Provider charges, hosting and any separate gateway extensions may
have fees. There is no plugin license activation or paid feature unlock.

**External services**

When configured and used for payments, the Stripe integration uses the installed
WooCommerce Stripe gateway and its API client. It sends payment amounts/currency,
saved customer/payment references and payment metadata to Stripe to create or
retrieve charges and reconcile payments. See [Stripe terms](https://stripe.com/legal)
and [Stripe privacy](https://stripe.com/privacy). The installed Stripe gateway
also controls its checkout data collection.

The PayPal integration connects to api-m.paypal.com, or
api-m.sandbox.paypal.com in sandbox mode, for authentication, subscription/payment
operations and webhook verification. Requests can include merchant credentials,
customer/billing details, subscription/plan references, amounts/currency and
transaction/webhook identifiers as required by the operation. Configure it only
with an authorized merchant account. See [PayPal terms](https://www.paypal.com/legalhub/useragreement-full)
and [PayPal privacy](https://www.paypal.com/legalhub/privacy-full).

This is an Ashbi-maintained GPL fork of public upstream source. Original
copyright and dependency notices are retained. Ashbi Subscriptions is independently
maintained and is not endorsed by WooCommerce, WordPress, Stripe or PayPal.

== Installation ==

1. Back up the WordPress files and database.
2. Upload the plugin so its directory remains named `subscription`.
3. Activate Ashbi Subscriptions from the Plugins screen.
4. Open **Ashbi Subscriptions > Settings** and review billing and cancellation settings.
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

Open **Ashbi Subscriptions > Help** in WordPress for local guidance. Report
reproducible bugs at https://github.com/camster91/ashbi-subscriptions/issues.
Never include customer data, payment tokens, passwords or private store exports.

== Screenshots ==

1. Merchant subscription details: saved cancellation confirmation, linked order and activity sections. Fabricated local test data; no payment was performed.

== Changelog ==

= 2.2.0 =
* Keep one renewal invoice per period across early, due and cart checkout paths.
* Apply recurring coupon payment limits using WooCommerce paid orders.
* Restore customer account routes and simple-product billing consent wording.
* Prepare a distinct directory package while preserving the GitHub update identity.
* Fix cancellation receipt accuracy, retain contractual export fields and guard admin status side effects.
* Add durable customer cancellation barriers, repair and private evidence export.
* Add opt-in approved billing contracts and immutable order-bound checkout acceptance.
* Preserve renewal opt-outs and validate automatic/manual settings.
* Keep Update URI: false and the legacy plugin identity.

= 2.1.2 =
* Fix self-conflict during bootstrap and refuse conflicting Core activation cleanly.
* Restore administrator Migration access and recognize custom WooCommerce folders.
* Restore saved variation carts through exact migrated terms; preserve conflicting lines and block checkout for review.

= 2.1.1 =

* Refuse to load alongside WP Subscription Core or another active WP Subscription copy to avoid a fatal class clash.
* Purge product page caches after variation mapping apply, rollback, and editor saves.

= 2.1.0 =

* Map each delivery-frequency variation to one term using its live WooCommerce price.
* Auto-select mapped terms for programmatic add-to-cart and snapshot purchase prices for renewal.
* Add a dry-run-first migration page and WP-CLI command with guarded rollback.
* Preserve product-level stopgaps unless removal is explicitly selected.

= 2.0.0 =

* Ashbi-maintained compatibility, renewal-safety, security, packaging, and branding work.
* Retains the legacy plugin identity and storage identifiers for in-place upgrades.

== Upgrade Notice ==

= 2.2.0 =

Rehearse on staging. Consent remains disabled until approved configuration.
Preserve schema 1.6.0 and cancellation barriers during rollback; see the release
notes and MIGRATION_1.6.0.md. Candidate review is pending; not a published release.

= 2.1.2 =

Fixes the 2.1.1 loading failure and Migration administrator access. Deactivate
WP Subscription Core before activating Ashbi Subscriptions. No schema changes.

= 2.1.1 =

Deactivate WP Subscription Core or any other active WP Subscription copy before activating.
Mapping changes now purge common product page caches.

= 2.1.0 =

Back up and test on staging. Mapping is opt-in and never migrates existing
subscriptions or charges payments. Use Migration to scan each site before apply.

= 2.0.0 =

Back up the full site and rehearse this update on staging before replacing an
existing subscription installation.
