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
Ashbi cancellation receipt from the core evidence reader, a staff note and an explicit
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

## Review follow-up — October 21, 2026

Cancellation closure now uses the same read-only core evidence reader as the
customer account receipt. Cached metadata cannot override the database result,
and conflicting confirmation flags leave the request open. An unavailable core
reader also leaves cancellation unresolved; install a compatible reviewed core
before recording a cancellation outcome. The adapter still never cancels or
repairs a subscription, and does not silently fall back to status-only proof.

The stale-cache regression failed before this change. The offline fixture loads
the actual core reader, and the real WordPress/MySQL workflow tests stale cache
and conflicting flags against fabricated records. Its temporary barrier table
shadows only the disposable connection and supplies the real reader's schema.
The workflow also runs when its core evidence-reader dependency changes.

Local follow-up results: **295 tests / 3,580 assertions** passed, including an
isolated missing-core-reader rejection. The dedicated real WordPress 7.1.3 / PHP
8.2.34 / MariaDB 10.11.19 run passed intake, duplicate safety, permissions,
immutable closure/source preservation, stale-cache and conflicting-flag checks.
Full coding checks on the adapter reported zero errors/warnings; production nonce
and prepared-SQL checks passed. Broader source/package CI is recorded separately
for the exact updated PR head. All records were fabricated and mail/provider
traffic blocked. This still does not test an installed Elementor form end to end.

A further replay regression reproduced a stale open-post cache overwriting another
staff member's closed outcome. After acquiring the existing per-request lock,
closure now rechecks the private request row directly from storage. A closed,
missing, changed-type or unreadable row cannot be overwritten from cached state.
The failure message asks staff to refresh and check the existing outcome rather
than falsely claiming the request is still open. Offline and real MySQL stale-post
checks preserve the original outcome and source. No new lock, state or table.

## Next verification slice

Exercise the complete optional workflow against the actual WooCommerce/core
cancellation service, with fabricated validated-form records: receipt first,
closure denied before cancellation, explicit merchant action, pending confirmation
kept open, supported local billing proof, reply attestation, immutable closure.
Run with HPOS on/off and block all HTTP/mail. Assert intake and closure do not
change orders, subscription state or original cancellation evidence. This adds
verification only; it still does not execute the installed Elementor Pro plugin.

The current-main follow-up passes 296 unit tests / 3,639 assertions. The new
real WordPress/WooCommerce lifecycle test passes **22 checks in each HPOS mode**,
including the actual active data-store check. The standalone CI-site activation
sequence also passed locally with legacy order storage. No source/closure changes
orders or cancellation evidence; the unavailable-provider request remains open.
Full PHPCS on the new script and the compatibility contract pass. CI now repeats
this workflow with fresh MySQL databases for HPOS on/off, with email/provider
requests blocked and pipeline failures propagated.

The initial snapshot assertion compared an in-memory new WooCommerce order with
its persisted representation. Reading both snapshots through WooCommerce CRUD
fixed that fixture mismatch; no runtime behavior or cancellation guard changed.
Local CI-site setup initially lacked a plugins directory; creating the disposable
directory allowed the standard activation sequence to pass. These checks use a
fabricated validated-record interface, not Elementor Pro itself, and record only
fabricated reply attestations. Installed-form, provider sandbox and actual message
delivery verification remain separate staging gates.
