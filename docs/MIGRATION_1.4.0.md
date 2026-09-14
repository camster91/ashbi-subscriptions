# Database migration 1.4.0

## Purpose

Version 1.4.0 adds `{$wpdb->prefix}subscrpt_renewal_claim`, an additive table
that makes one WooCommerce order canonical for each subscription billing
period. It does not rename, rewrite, or delete subscription posts, order data,
payment-token metadata, PayPal mappings, or legacy
`subscrpt_order_relation` history.

## Upgrade behavior

On the first loaded request after upgrade, the installer creates the claim
table and inspects legacy renewal relations for each subscription. A single
open order becomes canonical only when its creation time is consistent with
the current due-date anchor. Multiple open orders or a stale/undated order are
ambiguous: every affected order is retained with its original status, marked
as migration-quarantined, and made non-payable until a human reconciles it.
Renewal creation is paused site-wide while any ambiguity remains. Completed
orders are excluded because their subscription should already point at the
next billing period.

Open Stripe renewals are intentionally stricter: they become automatically
recoverable only when the canonical order already contains both its historical
Stripe customer and exact PaymentIntent ID. Otherwise they are quarantined for
human reconciliation, because a pre-upgrade worker could have created a remote
intent before the local ID was saved.

The backfill completion marker is not written if the legacy query, claim write,
or canonical order marker fails. A marker failure quarantines the order. The
plugin retries the additive migration on a later request and logs the affected
subscription and order IDs.

Each claim also carries non-customer schedule-repair state. A paid renewal marks
that state pending before changing the billing date and complete only after the
date and exact-order marker are verified. Action Scheduler performs the prompt
retry; the established hourly cron independently sweeps any pending rows whose
one-shot queue write was lost, using capped exponential backoff.

Stripe dispatch has the same durable pattern. The canonical order freezes its
customer and renewal identity before any remote request; stale Stripe order
locks and interrupted workers leave a pending claim row that is retried after
the persisted backoff and independently swept by the hourly job.

## Staging verification

Before production activation:

1. Restore a fresh database and uploads backup into an isolated staging site.
2. Disable outbound email and use gateway sandbox credentials.
3. Record counts and checksums for subscription posts, relation rows, gateway
   mappings, and payment-token references before activation.
4. Activate the candidate and confirm the 1.4.0 schema plus backfill marker.
5. Verify every unambiguous open legacy renewal maps to one claim, no completed
   renewal claimed the current or future period, and every ambiguity is listed
   for human reconciliation before the migration block is cleared.
6. Trigger the same due renewal concurrently and across a simulated crash after
   remote PaymentIntent creation; confirm one order, one relation, one stable
   renewal identity, and at most one sandbox charge even after replay.
7. Exercise classic and Blocks checkout manual renewal, Stripe retry, PayPal
   return, valid and invalid PayPal webhooks, cancellation, and refund paths.
8. Reconcile the original counts and verify that only expected additive claim
   rows and test transactions changed.
9. Force a next-date or marker write failure and verify the subscription remains
   non-active until the exact renewal order's Action Scheduler repair succeeds.

## Rollback

Take a database backup and retain the previously deployed plugin ZIP before
activation. To roll back code, place the site in maintenance mode, disable
scheduled renewal processing, restore the previous plugin ZIP, clear opcode
caches, and run the reconciliation report before resuming traffic.

The older plugin does not read the additive claim table, so an urgent code
rollback does not require deleting it. Do not drop the table or remove the
backfill marker during an incident; both are useful forensic evidence and
deleting them can erase the canonical-order record. If any real gateway charge
or subscription schedule changed, restore or reconcile those records from the
site-specific preflight backup rather than attempting a blanket database
rollback.

Production activation remains blocked until this procedure passes on a current
site clone and a human approves that individual site.
