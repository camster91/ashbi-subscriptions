# Contact request review adapter

Optional separate plugin in `tools/contact-request-review/`; deliberately excluded
from the core release ZIP. No billing, cancellation, refund, subscription or
email-sending code is added. It closes the operational gap between free-text
support intake and an explicitly verified cancellation.

## Behavior

The Elementor `elementor_pro/forms/process` hook runs after validated fields and
before notification actions. Every message from one uniquely named opted-in form
is stored as a private WordPress post before email is attempted. Wording detection
never decides which requests to ignore. Failures to persist/read back the receipt
return a form error. Open duplicate requests serialize under a site-scoped MySQL
mutex and share one reference. After staff close one, an identical later message
is a new request. The adapter requires MySQL advisory locks; it does not silently
fall back when unavailable.

Tools > Contact requests and persistent administrator notices show the backlog.
Requests older than 24 hours generate a stronger warning. Twenty requests per page,
oldest first, with an archive of closed outcomes. No cron/email alert dependency.
All views/actions require `manage_options`; HTTP closure requires an exact request
nonce. Source text is escaped in the staff view. The private post type has no public
query, REST route, export UI or standard post-edit UI. Standard WordPress backups
still contain these records; site data access and retention must be approved.

Staff verify identity and the exact subscription separately, process the request
using the existing subscription controls, and reply to the customer. Closing a
cancellation requires a cancelled/pending-cancellation subscription, a verified
Ashbi cancellation barrier and confirmed-result marker, a staff note and an explicit
attestation that the customer has been notified. The adapter only reads that proof;
it never starts a cancellation or provider operation. Other messages require a
documented resolution and the reply attestation. Staff can misclassify a message:
this remains an operational responsibility, not a promise of unattended handling.
The closed outcome attributes the actor/time and preserves original source text.

## Opt-in and rollout

1. Name the daily reviewer and backup; confirm they can access the queue without
   expanding their role. If no suitable existing administrator is available,
   review a narrower permission model separately; do not grant new access silently.
2. On an isolated site copy, install the adapter as its own plugin. Copy the
   disabled sample configuration to an MU plugin, choose an exact unique form
   name and existing administrator ID, verify field IDs, and enable only there.
3. Test the installed Elementor version with fabricated data: plain cancellation,
   different wording, ordinary inquiry, invalid email, repeated submission,
   mail failure, database failure, manager/subscriber access, invalid nonce,
   unconfirmed vs confirmed cancellation, source readback, closed/new request,
   24-hour warning and pagination. Suppress all outgoing mail/provider calls.
4. Review customer copy: “We received your message. If you asked to cancel, our
   support team will check your account and send you a confirmation. This message
   does not confirm that your subscription is cancelled.” Add a clear link to
   the actual verified account subscription page. Never claim cancellation before
   a durable authorized outcome. This proposed copy needs site approval.
5. Capture installed plugin/core/Elementor versions, backups and recovery approach,
   current settings and protected subscription/order counts. Verify compatible
   cancellation protection before approving the adapter's confirmation path.
6. Approve one store's exact package/config/form copy and retention/access policy.
   Installation does not backfill or process old requests. Separately review the
   existing submissions backlog before treating the queue as complete.
7. Read back installed hashes and form configuration; observe the queue with safe
   synthetic input only when separately permitted. Do not create live customer
   cancellations or charges to test it.

## Daily procedure

The owner checks both the support inbox and queue each business day, and checks
open cancellation requests before any manual renewal/fulfillment run. A new queue
is not a substitute for monitoring email-only requests. Verify identity, stop the
correct subscription, check the actual result, reply, then record the outcome.
Escalate pending/failed cancellation or an already-processing payment immediately.
The backup reviewer covers absences; do not leave requests waiting for the owner.

## Rollback and data preservation

Disable intake through the configuration first. Keep the plugin active so open
requests remain visible until handed off. Removing it hides the queue, so export
or review pending requests through an approved private workflow before removal.
No uninstall deletion exists. Restore the adapter's prior files/configuration,
not an old production database. It never changes the core cancellation barrier
and does not authorize downgrading the protective subscription engine.

## Validation boundaries

The offline fixture loads the real adapter with isolated WordPress/database doubles.
The dedicated CI workflow tests real WordPress/MySQL storage, private-post access,
deduplication, closure and preservation without WooCommerce or gateways. Neither
is an executed test of the site's installed Elementor, browser UI, merchant email,
gateway sandbox or named-owner response. Those remain per-site staging gates.

Primary integration reference:
https://developers.elementor.com/docs/hooks/forms.html#form-processing
