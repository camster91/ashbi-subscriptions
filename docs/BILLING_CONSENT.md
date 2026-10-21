# Renewal mode and customer consent

## Store renewal mode (AUX-01)

Settings → Renewals → Renewal Process accepts only the exact values `auto` and
`manual`. The registration now uses its own strict validator; the active-role
allowlist remains unchanged. Invalid submissions use Manual and display a
validation error explaining how to explicitly select Automatic.

Readers first consult `wp_subscription_renewal_process`. Only a genuinely absent
current option consults `subscrpt_renewal_process`. When both are absent, the
historical Automatic default is retained. Any present empty, false, role-name,
or otherwise unrecognized mode resolves to Manual. The screen and workers use
the same reader. There is no bulk migration or option write on this read path.
A store with a previously corrupted value must review its intended mode and save
Automatic explicitly if appropriate; installing this fix does not enable it.

## Authoritative customer consent (CUX-01)

`_subscrpt_auto_renew` remains the canonical key. Metadata existence, not PHP
truthiness, determines whether the historical global default can be inherited.
Only absent metadata inherits the store's configured mode. Existing `0`, `"0"`,
false, `"false"`, `"no"`, empty, null, arrays, and unknown values are not affirmative
consent and are never overwritten by reads or Stripe metadata cloning. The
existing affirmative spellings `1`, `"1"`, true, `"true"`, and `"yes"` are recognized
consistently. WordPress normally reads scalar metadata as strings.

Consent is separate from availability: an explicit opt-in remains stored when
the store selects Manual or disables Stripe renewals, but neither configuration
permits an off-session Stripe dispatch. The customer `renew-on`/`renew-off`
controller retains its login, nonce, owner/admin and finite-payment checks.
Installing the fix does not rewrite choices, revoke tokens, or refund payments.

## Traced entry points

- Account actions write explicit `1`/`0`; account display consumes
  `Helper::get_subscription_data()` and the shared read-only consent resolver.
- Expiry cron (`AutoRenewal::after_subscription_expired`) calls the due renewal
  helper only in store Automatic mode. The due helper and customer-triggered
  automatic-mode manual renewal use `create_renewal_order()`.
- Due and early order creation both call `subscrpt_before_saving_renewal_order`.
  Stripe's callback calls the actual shared metadata cloner; the adapter now
  also gates subscription-ID copying and Bancontact-to-SEPA lookup on consent
  and current store availability. Early renewal remains customer-paid.
- Canonical-order resume, including durable retries/hourly repair, dispatches
  `subscrpt_after_create_renew_order`; Stripe re-reads consent there and again at
  the public `pay_renew_order()` entry before provider preparation.
- Sibling reads were inspected: user-cancellation booleans do not inherit global
  renewal consent; timing/price/product fallbacks are outside this change.
  The inherited admin Stripe info-row string comparison and gateway payment
  metadata classification still have narrower display/provider-label contracts;
  they do not authorize off-session dispatch and are not changed in this wave.

## Verification boundary and rollback

PHPUnit subprocess fixtures load the actual setting registration, role sanitizer,
option readers, account controller, Helper data/cloning/canonical-resume methods,
and Stripe preparation/dispatch entries. Controlled WordPress metadata and
claim/order doubles are isolated from the canonical suite. Provider doubles
record dispatch, lookup, and the first preparation boundary and never make a
network call. These are not live WordPress UI or Stripe sandbox results. Full
new-order construction through cron/manual/early checkout, authenticated browser
rendering, and provider/webhook execution remain hosted/disposable staging gates.

No schema, storage-key, hook, entitlement, pricing, or cart contract changes.
Rollback can restore the prior plugin package without a database migration;
however, that restores the unsafe opt-out/setting behavior and must not be used
as an automatic remediation. Previously saved customer consent remains intact.
The parent release workflow must refresh its reviewed community source-hash plan
only after this behavior is frozen; this change does not alter that plan.
