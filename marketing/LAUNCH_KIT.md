# Ashbi Subscriptions launch kit

Status: drafts for review. No campaign, public release, directory approval or
submission is claimed here. Publish launch copy only after the linked package
has passed the release gates and its destination is available.

## Positioning

For small WooCommerce stores that need recurring purchases without a plugin
license fee. Ashbi Subscriptions keeps orders and customer history in WooCommerce
and makes the next billing or cancellation action easier to understand.

Headline: **Recurring orders. Clear next steps.**

Short description: Subscriptions for small WooCommerce stores. Clear recurring
orders, customer account controls and cancellation confirmation. No plugin license fee.

## Landing-page draft

Sell recurring products without adding another billing dashboard to your day.
Create subscription products, use WooCommerce orders and coupons, and let
customers view subscriptions through My Account.

When a customer cancels, check the saved confirmation. Confirmed cancellation
blocks future renewals; access can continue to the end of the paid period under
your store policy. Requests sent by email still need merchant action.

Stripe and PayPal integrations are included. Automatic billing needs a compatible
configured gateway, valid customer authorization and store-specific sandbox tests.
Payment processing fees and separate services may apply.

CTA before launch: **Review the source and test on staging**
Destination: https://github.com/camster91/ashbi-subscriptions
CTA after approval: **Get Ashbi Subscriptions** (add the verified directory URL).

## Announcement draft — publish only after release

Ashbi Subscriptions brings recurring products, WooCommerce renewal orders and
clear cancellation confirmation to small stores, with no plugin license fee.
Customers manage subscriptions in My Account; merchants keep orders and history
in WooCommerce. Test your gateway on staging before live sales. [Verified link]

## Merchant quick start

1. Back up your store and rehearse on staging. Activate only one compatible
   subscription plugin at a time; use the correct ZIP for your installation.
2. Set the subscription product's price/schedule and review renewal discounts,
   cancellation timing and customer consent. Complete a sandbox purchase.
3. Confirm the customer's My Account → Subscriptions page shows the price and
   next action. Save Settings → Permalinks once if account routes are missing.
4. For cancellation, verify the customer/subscription, cancel, and read the saved
   confirmation. Review any pending provider result; a received message is not
   proof that billing stopped. Email failure cannot reverse a saved cancellation.
5. Check failed-payment orders and Cancellation needs review daily. The optional
   contact adapter is a separate installation, off by default; core does not read
   your inbox. Complete the core cancellation and reply before closing a request.

## Claim evidence and restrictions

| Claim | Evidence | Limit |
| --- | --- | --- |
| No plugin license fee | GPL source, no license activation | Providers/hosting/support may charge |
| WooCommerce orders and coupons | `RECURRING_COUPON_LIMITS.md`, `EARLY_RENEWAL_DUE.md` | Store gateway/configuration must be tested |
| Customer cancellation confirmation | `PERSISTENT_CANCELLATION_RECEIPTS.md`, small-store suite | Provider-pending outcomes require review |
| HPOS compatibility | HPOS on/off local and hosted suites | Does not certify every extension or client store |
| Stripe/PayPal integrations included | Source adapters and local doubles | Real sandbox/crash-window certification still open |

Do not add testimonials, customer counts, speed benchmarks, paid-plugin parity,
WordPress approval, guaranteed recovery or a live directory link without evidence.
No email list import, paid advertising, analytics tracking or external account is
needed for this kit. Use support feedback to improve the plugin before adding
campaign machinery.
