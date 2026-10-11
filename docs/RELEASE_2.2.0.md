# 2.2.0 candidate preparation

This is candidate metadata, not a published release or approval to deploy.
The proposed release tag is `v2.2.0-rc.1`, with package
`ashbi-subscriptions-2.2.0-rc.1.zip`. Following existing RC conventions, the
plugin header, runtime, stable tag and Composer package version are plain `2.2.0`.

## Source traceability

The implementation baseline is
`f8d2e143d5994c7a122690a706d9f67c48117b8f`, which advertised `2.1.2`.
The candidate includes corrective changes for truthful cancellation receipts,
complete contractual exports and guarded admin transitions, plus release metadata. Bundled Composer `reference` identifies this implementation
baseline, not the eventual release commit. The exact candidate source commit must
be taken from the clean checkout and recorded in the generated release manifest.
Do not infer source identity from the plugin version alone.

Build with `scripts/build-release.sh` and `scripts/build-release-evidence.sh`.
Record the full source commit, clean-tree state, archive SHA-256 and per-file
manifest. Source CI builds evidence for its exact commit. The package workflow validates both the immutable historical `v2.1.2-rc.2` ZIP
with its pinned release-era fixture and the actual current candidate archive with
current consent/cancellation integration coverage. Historical results are not
candidate validation. Retain the candidate archive, commit and member manifest
before publishing.

## Compatibility and rollback

Retain `subscription/subscription.php`, `Update URI: false`, the legacy namespace,
storage identifiers and schema version `1.6.0`. No network updater is introduced.
The version bump does not change business logic, consent configuration or gateway
settings. Consent remains opt-in and requires approved policy configuration.

Follow `MIGRATION_1.6.0.md` for staging and rollback. Once cancellation barriers or
contract evidence exist, preserve the protective runtime and additive tables;
replacing them with an older engine is not a safe rollback. Do not restore an old
database over subsequent orders or customer activity.

## Review and release gates

Run required PHP 7.4/8.2/8.5 tests, static and security checks, deterministic package
checks and WordPress/WooCommerce integration with HPOS on and off for the exact
candidate commit. Independent review remains a separate gate from passing CI.

The candidate corrects the independent review findings:

- Customer receipt notices require verified durable intent, separately from a
  fail-closed dispatch barrier when storage is unavailable. Exceptions before
  receipt verification do not claim recorded intent.
- Private exports retain accepted `payment_type`, `billing_length` and `plan_total`
  while preserving the existing private-data allowlist.
- Admin transitions invoke the shared status/barrier guard before status-related
  email or order completion. Failed guards leave status and related orders intact.
  Existing capability and nonce boundaries remain unchanged.

Admin cancellation retains its existing lifecycle semantics; this correction does
not introduce a new admin cancellation request workflow or permission model.
Customer durable intent and billing barriers remain authoritative. Independent
review and passing checks are required on the corrective commit before deployment.

Test-only formatting and harness/documentation hygiene can create a later source
commit without changing packaged runtime members. Compare every candidate member
hash against the deployed package manifest before asserting runtime identity.
Archive SHA-256 may differ when the canonical build timestamp changes; matching
version labels alone do not prove identity. Do not redeploy production merely for
excluded test/documentation changes.
