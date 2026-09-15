# Per-site staging rehearsal and rollback proof

This procedure must pass on a fresh, isolated clone of each production site.
It does not authorize production deployment. Record the site, backup identifier,
candidate commit and ZIP checksum, responsible operator, and timestamps in the
client's private change record. Never commit fingerprints, secrets, database
exports, worksheets, or customer data to this repository.

## 1. Isolate and back up

1. Create a database and uploads backup using the host's native backup system.
2. Record its immutable backup identifier and verify that the restore action is
   available to the migration operator.
3. Restore into a private staging environment.
4. Disable outbound customer email, live webhooks, cron, Action Scheduler
   runners, and any host-level cron that can reach the clone.
5. Configure gateway sandbox credentials. Confirm that no live credential or
   production webhook endpoint is active on staging.

## 2. Capture protected state

Create a random comparison key in a restricted server-local location. Keep the
same key for pre-activation, post-activation, and rollback fingerprints; do not
print it or place it in shell history.

```sh
umask 077
openssl rand -hex 32 > /secure/ashbi-fingerprint.key
ASHBI_FINGERPRINT_KEY="$(tr -d '\n' < /secure/ashbi-fingerprint.key)" \
  wp eval-file tools/site-state-fingerprint.php > /secure/ashbi-before.json
```

Sensitive state appears only as counts and HMAC-SHA-256 aggregate, per-row, and
schema digests. The report also includes non-secret site/runtime identifiers and
an HMAC authentication code covering the complete report. The protected input
includes every subscription post and meta row, every
related WooCommerce order and item returned by its active data store, order and
item metadata, every payment-token and token-meta row, stable subscription
settings, and every existing `subscrpt_*` custom table. The source values and
HMAC key never appear in the report.

Also run the aggregate `tools/site-audit.php` report to record runtime versions,
status counts, schedule presence, HPOS mode, and expected migration quarantine.

## 3. Activate and prove preservation

1. Install the candidate ZIP without removing the previous rollback ZIP.
2. Activate Ashbi Subscriptions while all workers remain paused.
3. Capture `/secure/ashbi-after-activation.json` with the same key.
4. Compare the protected state:

   ```sh
   ASHBI_FINGERPRINT_KEY="$(tr -d '\n' < /secure/ashbi-fingerprint.key)" \
     php tools/compare-site-fingerprints.php \
     /secure/ashbi-before.json \
     /secure/ashbi-after-activation.json \
     activation
   ```

Activation mode requires every pre-existing protected row to remain identical
and permits only additional rows in the plugin's known migration tables. Stable
order state excludes `date_modified` and the three metadata keys that the claim
migration is specifically allowed to add; the complete order snapshot remains
in the report and must match in exact rollback mode. Version and
migration-control options are deliberately outside the stable-option digest.
Inspect those controlled deltas separately with the aggregate audit and
migration runbook.

Any mismatch is a release blocker. Do not manually clear quarantine or edit the
fingerprint. Restore the clone, diagnose the named category, and repeat.

## 4. Reconcile overdue records

Follow [the 1.4.0 migration runbook](MIGRATION_1.4.0.md). Generate a schema-v2
worksheet, review every record, dry-run the exact plan, then apply only the
digest-confirmed no-charge actions. No charge, order creation, cancellation, or
gateway request is part of that command. All other dispositions remain held for
their independently approved workflows.

## 5. Exercise behavior

Using new staging-only test records, verify purchase, classic and Blocks
checkout, manual and automatic renewal, retry, cancellation, refund, customer
payment-method change, duplicate and out-of-order webhook delivery, DST, taxes,
shipping, coupons, and the site's actual integrations. Reconcile order count,
relation count, gateway transaction count, subscription status, next date, and
customer-visible state after each case.

No production-derived subscription may be charged in a sandbox test. Use newly
created staging fixtures with sandbox-only payment methods.

## 6. Prove rollback

1. Record the post-test staging state for incident evidence.
2. Restore both the original database backup and previous plugin ZIP.
3. Clear opcode/object caches and leave renewal workers disabled.
4. Capture `/secure/ashbi-after-rollback.json` with the original key.
5. Require exact equivalence:

   ```sh
   ASHBI_FINGERPRINT_KEY="$(tr -d '\n' < /secure/ashbi-fingerprint.key)" \
     php tools/compare-site-fingerprints.php \
     /secure/ashbi-before.json \
     /secure/ashbi-after-rollback.json \
     exact
   ```

Exact mode permits no protected-state, custom-table row, or custom-table schema
difference. A failure means rollback is not proven and production approval must
not be requested.

## 7. Approval package

The private approval record must contain the backup/restore identifiers,
candidate commit and ZIP checksum, redacted audit summaries, fingerprint
comparison results, overdue disposition counts, sandbox transaction references,
test results, rollback proof, monitoring owner, maintenance window, and explicit
approval for that one production hostname.
