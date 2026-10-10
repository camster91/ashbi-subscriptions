# Evidence schema 1.6.0

Activation adds three tables: `subscrpt_cancellation_barrier`,
`subscrpt_evidence_event`, and `subscrpt_contract_acceptance`. The version marker
advances only after all tables can be read back. No existing order, subscription,
feedback, acceptance or customer record is backfilled or deleted. Default uninstall
preserves evidence tables. Existing cancellation feedback now appends new rows;
its latest-row UI remains compatible.

Authorized cancellation records an immutable barrier before the dispatch mutex.
Local renewal creation, dispatch and late callbacks respect that barrier. Busy
dispatch, missing parent order, uncertain provider result, or incomplete persistence
produces pending confirmation. The queue and hourly sweep repair recorded intent;
they never fabricate a customer request. In-flight payment outcomes require review.
PayPal confirms only HTTP 204 cancellation or exact remote CANCELLED readback.

Contract consent starts disabled. Configuration must contain explicit enabled=true,
exact approved version/text/hash and approval reference. The accepted server plan is
checked against final WooCommerce identifiers, monetary totals and canonical terms
before an immutable ledger write. Required order-pay retries verify the original
ledger even after configuration changes or disablement. Legacy orders get no
inferred acceptance. A resumed classic checkout displaying another revision must
start a new order instead of rewriting acceptance.

Blocks/Store API subscription checkout is explicitly unavailable while this consent
feature is enabled. Stripe product/cart/checkout wallets are disabled through the
upstream supported payment-request filters. Gateway-specific routes and installed
versions must pass isolated tests before enablement; these filters do not establish
coverage for every vendor or new Stripe route. Do not enable on an unverified site.

Staff with `manage_woocommerce` can download an exact subscription's bounded JSON
summary through a subscription-specific nonce. It shows ledger provenance, current
order status, missing acceptance and truncation. It does not fetch or submit dispute,
delivery or communication evidence. No cards, IPs, tokens or addresses are exported.
Acceptance, payment outcome and fulfillment are separate facts.

Evidence is retained by default. No automatic purge is introduced; the owner must
approve site-specific access/retention policy before rollout. Administrative exports
must stay private. Existing recovery-event retention is separate from this ledger.

## Compatible recovery

Never replace this build with a package that ignores cancellation barriers or
required-order acceptance. Preserve tables and all new trading records. Never
restore an old whole database after trading resumes. A safe initial rollback is
feature disablement with the protective runtime still installed. The companion
`tools/evidence-preserving-rollback.php` disables new consent UI through an option
filter without altering stored approvals or evidence; it does not undo cancellation
barriers. Install it as an MU plugin only within the approved per-site deployment.

This protective disablement is not a proven rollback to an older engine. Verify
candidate and recovery package checksums, schema/counts/schedules, required unpaid
order gates and cancellation barriers on an isolated clone before production.
Gateway sandbox, mobile/keyboard, installed route coverage and actual fleet checks
remain rollout gates. Unit doubles and CI integration are supporting evidence,
not proof of a successful production deployment.
