# Database migration 1.5.0

## Purpose

Version 1.5.0 adds the additive
`{$wpdb->prefix}subscrpt_recovery_event` table. It records deduplicated local
retention-campaign outcomes so the Reports page can distinguish offers issued,
offers accepted, saves, and attributed win-back payments.

The migration does not rename, rewrite, or delete subscription posts, orders,
payment tokens, cancellation feedback, or legacy order-relation history. The
table is created through the normal installer upgrade path and has no remote
analytics or outbound campaign dependency.

## Privacy and idempotency

The table stores internal subscription, customer, and order references so
operators can reconcile events locally, plus a campaign key and optional coupon
code. Aggregate report queries return counts only; they never select customer
IDs, comments, email addresses, or coupon contents. The unique event key is a
SHA-256 digest, so repeated AJAX requests, webhooks, and order callbacks are
safe no-ops.

The built-in campaign key is `cancellation-retention`. A retention offer is
recorded when issued and accepted. The first later paid order for that
subscription is attributed as a win-back; ordinary renewal orders are not
counted as recovered payments unless the renewal itself was marked recovered
after a failed or on-hold state.

Campaign events are retained for 365 days by default and can be bounded between
30 and 3,650 days in Cancellation Flow settings. The hourly worker prunes only
expired rows from this event table. The Reports page export contains aggregate
metrics, reason labels, cohorts, and campaign totals; it does not include
subscription IDs, order IDs, customer IDs, email addresses, comments, or coupon
codes.

## Rehearsal

On an isolated staging clone:

1. Back up the database and record the existing subscription, order-relation,
   and cancellation-feedback counts.
2. Activate the candidate and confirm the 1.5.0 schema version plus the new
   recovery-event table.
3. Exercise a retention-offer issue, duplicate offer claim, accepted offer,
   subsequent paid renewal, and duplicate callback. Confirm one row per event
   key and the expected aggregate counts.
4. Confirm the Reports page renders empty-state copy when no campaign events
   exist and aggregate values after the fabricated staging flow.
5. Uninstall without the explicit removal option and confirm the recovery table
   and all business records remain. Destructive removal remains an explicit,
   separately reviewed operation.

Production activation remains release-gated until this rehearsal and the
gateway sandbox renewal matrix pass on a current site clone.
