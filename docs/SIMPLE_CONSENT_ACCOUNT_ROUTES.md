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
Verify both account pages and a zero-total simple subscription checkout, then
immediately cancel only that test subscription.

Regression coverage runs real consent/cart source and real bootstrap/account
registration with isolated WordPress doubles for admin, frontend, and AJAX.
For rollback, restore the prior `bootstrap.php` and
`includes/Frontend/ContractConsent.php`. Keep test order and cancellation evidence.
