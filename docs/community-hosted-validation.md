# Hosted community candidate validation

The workflow `.github/workflows/community-package.yml` is separate from the
unchanged exact published-RC workflow. It does not publish a release, push a
branch, submit to WordPress.org, reserve a slug, or exercise live payments.

## Artifact contract

The producer tests the parsers and package policies, builds one named community
candidate from the checked-out commit, and uploads it with the canonical GitHub
reference, SHA-256 and manifest. Upload Artifact v4 supplies an immutable artifact
ID. Both serial HPOS jobs and the later Plugin Check job download **that same
ID**, not another checkout-built community ZIP or a public release URL.

Every consumer checks source commit, clean-tree provenance, transform-script
hashes, both archive hashes, exact member hashes and the 211-member community
identity. It independently checks the canonical reference against the unchanged
canonical source packaging policy and applies the approved transform in memory.
Only canonical reference verification rebuilds an archive; consumers never build
or substitute a community candidate. Archive members cannot be paths, symlinks,
directories, duplicate entries, foreign roots or malformed names.

## Real WordPress jobs

The owned wp-env configuration uses PHP 8.2 with no source-plugin mount. It installs
WooCommerce and Stripe from their official directory slugs before installing the
downloaded candidate ZIP. Core must resolve `Requires Plugins: woocommerce` against
the real `woocommerce` directory. WordPress, PHP and exact installed plugin
versions are recorded, not assumed from `latest-stable` URLs.

The runtime matrix runs HPOS `off` and `on`, with `max-parallel: 1`. The external
local/admin-protected fixture uses WordPress authentication and the requested mode
throughout its checks. The runner requires the fixture's actual OrderUtil datastore
trace. The installed plugin's complete member hashes, exact basename,
`ashbi-subscriptions` parsed text domain, missing Update URI header (including no
empty declaration), required WooCommerce slug and actual datastore are checked
before and after. The competing GitHub/source plugin must not be active.

Safety is outside the plugin ZIP: outgoing provider HTTP and mail are blocked,
WP cron and the default Action Scheduler queue runner are disabled. A UUID marker,
configuration hash, marker-bound Compose project and disabled ambient `.env`
prevent adoption/cleanup of unrelated environments. Occupied port 8888 or inherited
Compose/wp-env overrides fail closed. Cleanup runs in `finally` and in an always
workflow step; never replace it with `docker prune`, `destroy` or user-app cleanup.

### Mounted-input trust boundary

The locked wp-env Docker builder emits mappings as
`source.path:/var/www/html/<mapping-key>` with **no read-only option**. Its parser
accepts mapping values only as source strings. Appending `:ro` to a source is not
an approved mount-policy DSL; this runner does not claim read-only enforcement.
The offline suite calls the real locked builder and verifies the generated mounts.

For community jobs, ownership/configuration/cache live in a deterministic sibling
`.ashbi-community-owned-<SHA256 of resolved output identity>`, **outside** the
writable report mount. Run and cleanup derive this path on the host, never from a
mutable report pointer. Only its `mu` subdirectory is mapped as the executed MU
harness; wp-env also mounts its required cache subdirectories, not the ownership
marker or configuration. Published-RC default paths and package behavior remain
unchanged. Forged ownership files under reports are ignored; unknown config hashes
and ambient Compose/wp-env overrides still fail closed.

Before startup the host snapshots candidate ZIP, member manifest, provenance,
candidate manifest, all mounted integration/helper files, the runner/compatibility
helper and the two actual MU copies. MU expectations come from the original source
hashes, not from mutable copies. Plugin Check's ZIP is also checked when used.
Expected hashes remain in host process memory: a plugin cannot update expectations
by forging a provenance or integrity report. Immediately after validation and
**before any cleanup deletes the executed copies**, exact bytes, missing files,
symlinks and changes to mounted fixture/MU member sets are checked again. Detected
drift fails the job even if WordPress commands reported success. This is final
readback, not a sandbox against transient changes restored before readback.

`harness-integrity.json` is saved before cleanup and updated in nested `finally`
with primary validation, integrity and cleanup errors. Mutation plus cleanup timeout
and primary failure plus cleanup failure remain separate evidence entries and both
appear in the raised error. Evidence-write failures also fail the job; cleanup is
still attempted. Report replacement does not follow a forged report-file symlink.
Failed cleanup retains only the UUID/config-hash-owned environment for an explicit
retry using the same Compose project. Installed-byte readback's PHP helper treats
incomplete/unwritable evidence as failure, never as a successful verification.

## Official Plugin Check

The same candidate is installed in a new owned WP environment. Official Plugin
Check 2.1.0 is fetched from WordPress.org and must match SHA-256
`6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`.
The command checks `ashbi-subscriptions/ashbi-subscriptions.php`, uses
`--require=./wp-content/plugins/plugin-check/cli.php` before WordPress boots for
runtime checks, and preserves every strict-JSON finding plus raw stdout, stderr,
CLI status and errors-present policy status. No checks/findings are excluded.

Findings are **initial nonblocking diagnostics**; installation failures, invalid
machine output, hash drift or missing installed-byte readbacks are still blocking
infrastructure failures. This is not directory approval, submission, listing or
slug reservation. The community package has ordinary Core update metadata; the
GitHub `Update URI: false` isolation test is deliberately not reused for it.

## Evidence and manual execution

After review and merging the parser fixes into the same local source, the parent
can run the full offline suites with the locked dependencies installed. The
lightweight runtime suite is:

```bash
python scripts/test-community-runtime.py
python scripts/test-published-release.py
```

These use explicitly fabricated ZIP/PHP/command fixtures and prove orchestration
contracts only, not real WordPress, HPOS, gateways or rollback.

With separate user authorization for external GitHub actions, a pull request or
manual workflow dispatch can execute the hosted jobs. Do not run Docker,
Playground or a preview on the user's machine for this validation. A successful
hosted report requires:

- One producer artifact ID and identical candidate/source SHA in all three jobs.
- Both runtime jobs passing installed-byte readback before/after and authenticated
  integration JSON with the matching requested and actual HPOS mode.
- Observed exact WordPress/WooCommerce/Stripe/PHP versions in reports.
- Official PCP checksum provenance, complete raw and JSON findings, and the raw
  CLI and policy statuses, including failures or nonblocking findings.
- Fixture/candidate integrity readback and cleanup of only the owned environment.

Artifact upload runs even after consumer failures. Outer runner stdout/stderr also
survive failures before the report directory/environment can be created. No live
WordPress result is claimed until an actual hosted run has completed; no gateway,
database rollback or package rollback proof is claimed by this workflow.
