# Parallel community and GitHub packages

## Two identities, one business implementation

| Distribution | ZIP root/main | Translation domain | Update URI |
| --- | --- | --- | --- |
| GitHub compatibility replacement | `subscription/subscription.php` | `subscription` | `false` (manual verified ZIP updates) |
| Community directory candidate | `ashbi-subscriptions/ashbi-subscriptions.php` | `ashbi-subscriptions` | header absent |

The canonical `plugin/` tree and `scripts/build-release.sh` are unchanged.
Do **not** remove the GitHub header globally: the replacement ZIP must remain
isolated from the inherited upstream directory identity. The existing tested
GitHub RC2 ZIP is retained separately; it is not rebuilt or relabeled as the
community artifact.

The community candidate is not an in-place basename replacement and is not
WordPress.org approved, reserved, submitted, or hosted. The proposed slug and
contributor account require maintainer confirmation and directory approval.
The inherited `Contributors: ashbi` is not an account-ownership attestation;
review that field against the actual submitting account before submission.
No account registration or public publication is part of this work.

## Audited build boundary

`build-community-package.py` accepts a canonical ZIP, rebuilds the canonical
package using the unchanged shell policy, and requires exact member-set/byte
agreement with that source checkout. A partial ZIP, wrong root, foreign source,
changed Update URI policy, or extra member fails. No fallback fabricates source
or package evidence when a required tool is unavailable.

The only approved differences are:

- New directory/main filename, community plugin name, unique text-domain header,
  removed Update URI, and the WooCommerce dependency header.
- PHP `token_get_all(..., TOKEN_PARSE)` selects **only literal domain argument
  positions** in WordPress gettext functions and translation registrations.
  Function/method lookalikes, text arguments, comments, hooks and storage values
  are not replaced. Composite legacy domains fail instead of being guessed.
  Files declaring gettext-name functions, function imports (including aliases /
  grouped imports), or qualified namespace gettext lookalikes fail closed before
  any edits. Unshadowed global calls and PHP's unqualified global fallback inside
  the existing SpringDevs namespaces remain recognized.
- Existing locked `@babel/parser` examines source and compiled JS; lexical
  bindings recognize imported i18n functions, `wp.i18n`/`window.wp.i18n`, and
  their compiled aliases/sequence calls. All bindings are collected before alias
  classification, including later hoisted declarations. Direct function aliases
  and namespace-alias chains are recognized; destructuring known i18n aliases is
  explicitly unsupported and fails closed. Writes/redeclarations affecting known
  translation aliases, global namespace ancestors or namespace members also fail
  before edits, rather than erasing identity and falsely passing the archive
  checker's remaining-domain scan. Shadowed aliases and other custom calls retain
  business literals. Only domain literal byte ranges change; no bundler
  installation or claimed recompilation is involved.
- Compiled JS asset versions are derived from the transformed bundle SHA-256;
  dependency handles stay unchanged. The two installer CSS basename selectors
  deliberately target the new directory/main.
- Community installation/FAQ copy explicitly describes package switching.
- `THIRD_PARTY_NOTICES.md` becomes `.txt`, **byte-identically**, only inside the
  community ZIP; GPL/dependency copyright notices, licenses and vendor files
  remain byte-identical. The canonical notices file stays unchanged.
- The inherited stale `languages/subscription.pot` is omitted. **English only**:
  there is no newly generated POT, MO or JSON catalog. Calls/registrations use
  the new domain, ready for a later complete extraction and reviewed directory
  translations. Existing upstream-domain translations are not promised.

Everything else is preserved. In particular: post types, option/meta/table
keys, statuses, cart `subscription` keys, idempotency identities, class names,
legacy constants/aliases, hooks, REST namespaces, gateway/billing logic,
template override directories and non-gettext `subscription` literals remain
unchanged. Remaining legacy literals are intentional business or compatibility
identifiers; never apply a global search-and-replace.

## Repeatable local checks

Build tools: Python 3.9+, PHP 8.2 for the development tokenizer, Node with the
locked npm dependencies, and the canonical bash/rsync/zip/unzip tools. The
plugin's declared PHP 7.4 minimum is unchanged. Install dependencies using the
existing locked tooling; no global parser/toolchain installation is required.

```sh
npm ci --ignore-scripts
composer install --prefer-dist --no-interaction
python3 scripts/test-community-package.py
bash scripts/build-release.sh dist/github-compatibility.zip
bash scripts/test-release-package.sh dist/github-compatibility.zip
python3 scripts/build-community-package.py build \
  --canonical dist/github-compatibility.zip --output dist/community-candidate.zip
python3 scripts/build-community-package.py verify \
  --canonical dist/github-compatibility.zip --output dist/community-candidate.zip
python3 scripts/check-community-package.py \
  --canonical dist/github-compatibility.zip --community dist/community-candidate.zip \
  --report dist/community-local-checks.json
(cd dist && sha256sum --check community-candidate.sha256)
```

Build final evidence only after committing and confirming `git status --short`
is empty. The `.manifest.json` records the exact commit/branch, dirty-tree flag,
transform hashes, canonical ZIP hash and both member SHA-256 inventories.
The `.sha256` authenticates the exact community ZIP. Do not describe a dirty
build or fixture ZIP as the final candidate.

On Windows, use task-local packaging tools in shell PATH via `/c/...`, native
program arguments via `C:/...`, and a task-owned scratch TMPDIR. The Python
builder resolves bash explicitly through PATH to avoid Windows' WSL launcher;
it enables MSYS conversion only in its owned canonical subprocess. Its own
short-lived unpack/rebuild directories are under ignored `dist/`, not system
temporary directories. Do not start local Docker/Playground/browser previews
for this bounded package gate.

The new `community-package.yml` runs these archive contracts independently.
It does not publish or submit anything, and leaves all existing required source,
security-sensitive and WordPress integration workflows unchanged. Hosted
execution of that new workflow was not performed locally.

### Local evidence at implementation handoff

The red/green tracer commands were `python scripts/test-community-package.py`:
missing distinct builder; missing PHP domain transform; missing JS source and
compiled transform; missing distinct archive API; missing canonical completeness
gate; missing independent checker; then composite JS and shadowed-alias failures.
Each expected failure was executed before its implementation and followed by a
passing rerun. A real Windows checksum verification exposed CRLF output; a
13th regression reproduced the filename corruption before switching checksum
writes to literal LF bytes. Negative checks reject corrupt business bytes,
unexpected roots, duplicate ZIP entries, misleading headers, changed canonical
update policy and unsafe traversal members.

Local canonical verification passed PHPUnit **228 tests / 2,017 assertions**,
PHPStan, the **10 metadata-key / 5 hook** compatibility contract, PHP syntax,
**38 JS/JSX** source files, canonical package checks (**134 PHP files**), the
security-sensitive nonce/prepared-SQL sniffs, integration-tooling compatibility,
and **13** published-release harness regressions. Full advisory PHPCS reported
**0 errors / 110 warnings** on this Windows runtime; that debt was not hidden
or renamed. It is not the official Plugin Check report.

Community archive checks passed **211 members**, **134 PHP files linted**,
**38 JS files parsed**, retained copyright/license/vendor bytes, exact-transform
comparison, and the same static storage/hook contract. Actual extracted main
and bootstrap files were included in isolated PHP subprocesses for clean,
foreign-class, foreign-constant and own-identity cases. Direct access stayed
silent and did not load vendor files. These use the existing minimal WordPress
**stubs**, not real WordPress or a real database.

## Switching and rollback

1. Back up files/database and retain the previous exact, verified package.
2. Rehearse on isolated staging with renewal workers paused, email suppressed,
   no live provider credentials, and sandbox-only gateways. Reconcile existing
   subscription/order/token counts first.
3. **Deactivate** Core/WPSubscription and the GitHub fork before activating
   Community. Never activate distributions together. Do not uninstall or delete
   records as a package-switching step.
4. Install Community at its new basename, activate alone and reconcile retained
   records. Its constants/path aliases identify the new main automatically;
   the business keys and compatibility hooks still identify the same records.
5. For code rollback: pause workers, deactivate Community and restore/reactivate
   the previous verified distribution alone. Reconcile before resuming.
   Default uninstall retention is preserved, but explicitly enabled destructive
   removal is not a safe switch/rollback operation.

Byte-preserved storage/renewal logic and local rollback-contract tests are **not
proof of database rollback** after payment or lifecycle state changes. Follow
`SITE_REHEARSAL_RUNBOOK.md` with both file/database backup restore and protected
state reconciliation. Neither package is production-payment certification.

## Remaining gates for the maintainer

- Review/push the local branch, run the new independent package workflow, and
  adapt the exact-ZIP hosted validator to the community basename and manifest.
  The existing hosted RC2 pipeline is pinned to the GitHub identity; a source
  mount or renamed GitHub archive cannot certify the community artifact.
- Install the exact checksum-verified community ZIP in fresh real WordPress /
  WooCommerce environments; read back every installed byte before/after checks,
  execute HPOS off/on plus update-identity isolation and non-coexistence, and
  verify the actual datastore at completion.
- Run the official Plugin Check with all diagnostics retained. Do not suppress
  inherited naming/global/hook warnings or infer a clean report from exit zero.
  Removing the community Update URI is intended to address the directory-only
  updater error, but this new artifact has **not** had official Plugin Check
  executed at handoff.
- Gateway sandbox/crash-window verification, per-client clone migration and
  database/package rollback remain separate gates.
- Confirm the final approved slug, submitting/contributor account and public
  source requirements; complete fresh translation extraction/review, directory
  review and approval before advertising directory installation or updates.

No GitHub writes, pushes, merges, public release, submission, client activation,
live payment requests, private archives or provider secrets were used here.
