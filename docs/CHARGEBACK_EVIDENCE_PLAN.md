# Goal: reliable cancellation and truthful subscription evidence

Owner: this implementation branch, `feature/cancellation-evidence-consent`.
Baseline: canonical public repository main `5e57225` (merged billing consent fixes).
Keep unrelated paused onboarding PR #12 separate. No historical consent backfill.

## Milestones

1. Add tests and an additive, append-only evidence ledger. Distinguish server receipt,
   authorized intent and confirmed result. Preserve survey history and corrections;
   attribute ownership failures to no customer. Keep raw secrets/card data/IPs out.
2. Persist a cancellation barrier, serialize with every provider dispatch, verify
   status writes and keep feedback/email failures from blocking cancellation.
   Retain access-end semantics, truthful pending/confirmed notices, and explicit
   review of already-dispatched charges. Test races, replay and persistence failure.
3. Add versioned approved-terms snapshots with unchecked, server-enforced consent
   across classic, Store API/Blocks, order-pay and accelerated checkout. Snapshot
   text/hash/version and purchased plan before payment. Unsupported routes fail closed
   when enabled. Distinguish acceptance, payment failure and subscription creation.
4. Add scoped admin history/evidence summary with source provenance, missing evidence
   and permissions. No automatic dispute submission or success promises.
5. Independent review; PHP/static/JS/unit and real isolated WP/Woo HPOS/checkout tests;
   gateway sandbox, mobile/keyboard, migration and evidence-preserving rollback tests.
6. Fresh private fleet inventory, owner-approved recovery approach and compatible
   code rollback artifact, then Nourish intended canary
   if eligible. Verify code/schema/counts/schedules/payment settings/customer UI;
   observe and roll remaining applicable sites sequentially with rollback ready.

## Rollout gates

General reversible plugin deployment is authorized. The owner reports existing
daily backups and waived another backup capture; this is not independently verified
recovery proof. Keep a compatible plugin-level rollback artifact. New/material customer contract
wording requires Cameron approval before live enablement. Approved scope/version,
retention/access policy and gateway/checkout coverage must be verified per site.
No real customer test cancellation, live test charge, outgoing client email, new
credential/security grant, destructive migration, deletion or automatic refund.

Append-only tables are preserved on deactivation/uninstall by default. Code rollback
must continue to respect cancellation barriers and preserve new evidence. Never use
an old full database restore after live trading resumes. A previous build lacking the
barrier is not a safe automatic rollback; prepare a compatible rollback package.

## Per-site checklist (private site record)

- [ ] Canonical site identity, installed engine/version, actual checkout routes,
      gateway/version/mode, HPOS and extensions confirmed.
- [ ] Existing approved terms exact text/version/scope and retention/access policy.
- [ ] Owner-approved recovery approach and compatible rollback package/hash; protected state
      fingerprint/counts, schedules and payment settings captured.
- [ ] Isolated site tests and upgrade migration review pass; no customer effects.
- [ ] Canary deployed and exact installed bytes/schema read back.
- [ ] Counts/schedules/payment settings preserved; permitted UI/read-only smoke pass.
- [ ] Monitoring window and rollback owner recorded; status verified/blocked/not applicable.

Keep actual client inventories, historical disputes, customer records and production
fingerprints outside this public repository. Current progress and blockers are in
the task's private rollout record, not a claim of production readiness.
