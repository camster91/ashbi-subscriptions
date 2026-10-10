# 2.2.0 candidate preparation

This is candidate metadata, not a published release or approval to deploy.
The proposed release tag is `v2.2.0-rc.1`, with package
`ashbi-subscriptions-2.2.0-rc.1.zip`. Following existing RC conventions, the
plugin header, runtime, stable tag and Composer package version are plain `2.2.0`.

## Source traceability

The implementation baseline is
`f8d2e143d5994c7a122690a706d9f67c48117b8f`, which advertised `2.1.2`.
The candidate preparation changes only version and release metadata relative to
that baseline. Bundled Composer `reference` identifies this implementation
baseline, not the eventual release commit. The exact candidate source commit must
be taken from the clean checkout and recorded in the generated release manifest.
Do not infer source identity from the plugin version alone.

Build with `scripts/build-release.sh` and `scripts/build-release-evidence.sh`.
Record the full source commit, clean-tree state, archive SHA-256 and per-file
manifest. Source CI builds evidence for its exact commit. The historical published
ZIP workflow currently targets `v2.1.2-rc.2`; its result is not validation of this
candidate. Validate the actual new archive independently before publishing.

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

Two feature findings need resolution before unconditional merge approval:

- A barrier-storage read failure can produce a customer notice claiming the
  cancellation request was recorded even though receipt was not persisted.
- Private contract evidence export omits accepted `payment_type`, `billing_length`
  and `plan_total` fields.

The existing admin status mutation path also bypasses the new cancellation intent
and status guard. Limit protection claims to verified paths until this integration
debt is addressed. Keep the PR draft while these findings are unresolved. Do not
publish a tag, merge, deploy or activate consent based only on metadata preparation.
