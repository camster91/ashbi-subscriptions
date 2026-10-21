# Simple subscription consent and account routes

Classic one-period products store `day`, `week`, `month`, or `year`, while
multi-period plans also use plural units. Consent accepts both existing forms.
The checkbox, approved document, positive interval, and exact snapshot hash
remain required. Existing acceptance records are not rewritten.

Normal admin requests now construct the existing account controller. AJAX
already constructs it through the frontend bootstrap and must not duplicate it.
This keeps configured list and detail endpoints present when WordPress rebuilds
permalink rules. There is no new per-request rewrite flush.

No database migration, gateway change, new plugin, or contact-form automation
is included. After applying the bootstrap fix, save the existing WordPress
permalink settings once without changing their structure if routes are missing.
On an isolated local/staging copy with mail and provider traffic blocked, verify
both account pages and a zero-total checkout using a fabricated customer, then
submit cancellation only for that test subscription.

Regression coverage runs real consent/cart source and real bootstrap/account
registration with isolated WordPress doubles for admin, frontend, and AJAX.
For rollback, restore the prior `bootstrap.php` and
`includes/Frontend/ContractConsent.php`. Keep test order and cancellation evidence.

## Verification on current main — October 21, 2026

Updated against merged cancellation PRs #47–#49, preserving their source,
documentation and integration checks. Only adjacent changelog entries conflicted.

- Unit suite: **295 tests / 3,598 assertions**, compatibility contract passed.
- The added real WooCommerce cart/order regression rejected checked singular-unit
  acceptance on main before the existing fix; it passes with this branch. It
  checks an absent checkbox, changed snapshot, actual order terms, immutable
  acceptance/readback and changed order amount. No payment handler is invoked.
- Full disposable integration passed with HPOS off/on on WordPress 7.1.3,
  WooCommerce 11.2.1, PHP 8.2.34 and MariaDB 10.11.19.
- A real normal-admin bootstrap registered one account-controller callback and
  generated both list and detail permalink rules. WooCommerce also registers
  those endpoints; raw endpoint-array entries are not controller counts.
- Chromium authenticated-customer flow: missing acceptance was rejected; checked
  consent completed a free checkout, the account list/detail opened, cancellation
  was recorded, and its receipt persisted after reload. Stored order acceptance
  matched the immutable ledger and the fabricated owner. Automatic renewal was
  off, exactly one barrier existed, and renewal creation was blocked.
- The free order has no provider attached: its cancellation correctly remains
  **pending confirmation**, with a clear local billing block. The browser harness
  initially expected confirmation and was corrected; no production guard was
  relaxed. This is no proof of Stripe/PayPal acknowledgement. Initial browser
  harness retries also corrected page-loading and DOM serialization timing.
- Package check passed (139 packaged PHP files), full PHPStan reported no errors,
  and the new integration file plus security-sensitive coding checks passed. The
  initial Composer package phase failed on the copied Docker worktree pointer;
  running the same package script with explicit local Git directory/worktree
  selectors passed. No repository verification was weakened.

All data and wording were explicitly fabricated locally. No live setting, order,
subscription, provider account, mail or historical consent was touched. Hosted
results apply to their exact PR heads and remain separate from these local checks.

Hosted follow-up: the first source/candidate HPOS runs failed because the new
admin integration fixture assumed a preloaded WooCommerce session. The local
runner had called `wc_load_cart()` first and masked that setup difference. A
local cold-session run reproduced the null-session error; the fixture now uses
WooCommerce's standard `wc_load_cart()` inside the guarded disposable harness.
The cold-session checkout check and changed-file coding check pass. This changes
only test initialization, preserves the normal WooCommerce request lifecycle,
and does not change customer billing, consent or cancellation behavior. Updated
hosted results must be checked for the new head rather than reusing old passes.


After merging merchant cancellation protection (#50), the updated branch passes
296 unit tests / 3,657 assertions and the complete cold-session source integration
harness with HPOS off/on. Both the simple checkout fixture and merchant durable
cancellation fixture run together. Only adjacent changelog content required a
merge resolution; consent, routes and cancellation behavior were preserved.
