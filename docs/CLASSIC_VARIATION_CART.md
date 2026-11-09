# Classic subscription variation cart correction

The WooCommerce cart hook must receive the selected variation ID. Classic
subscription variations now retain a snapshot and their configured 1/2/3 billing
interval through both classic and Store API subscription creation. Subscription
records use the parent product ID and exact variation ID for renewal matching.
Mapped plans keep their separate checkout listener; one-time variations do not
create subscriptions. Missing or invalid variation intervals block add-to-cart
with an actionable message instead of inventing a billing period. Restored
classic variation carts are checked against the raw interval as well. Automatic
expired-subscription selection requires the exact variation and no mapped plan;
explicit renewal selections retain their identity and checkout ownership checks.

This correction is scoped to audited variations with no trial or signup fee.
It does not establish variation trial/fee pricing or provider sandbox assurance.
The integration fixture creates only disposable pending orders, blocks outbound
mail/HTTP, checks exact renewal eligibility without dispatch, and cleans up.
Both HPOS modes must pass the classic_variation_cart_lifecycle trace gate.
Existing billing consent route and rejection checks remain required.

No catalog, option, order, customer or schema migration is included. Preserve
installed policy statements, contacts, consent settings, evidence and operational
records during rollout. A file rollback must restore the immediately preceding
protective 2.2.0 package; never downgrade cancellation/evidence protection or
restore a database over new evidence. Use fresh before/after preservation hashes.

The Viva canary requires published variation selection, cart retention and the
store's unchecked consent checkbox at checkout. Do not accept terms, purchase,
charge or cancel. Remove only the preview cart lines created by this verification.
