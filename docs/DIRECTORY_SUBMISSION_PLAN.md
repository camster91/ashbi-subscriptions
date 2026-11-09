# Directory submission and brand plan

## Objective and working assumptions

Prepare Ashbi Subscriptions for WordPress.org with honest marketing for small
WooCommerce stores. Preserve subscription state, orders, hooks, consent and
cancellation evidence. The user authorized fixes, branding, marketing and
submission, and confirmed the WordPress.org username **camster91**.

Keep the existing Ashbi Subscriptions name and orange identity (`#ff4d00`).
The proposed slug is `ashbi-subscriptions`; directory allocation and any previous
submission/ticket remain unverified. No WordPress endorsement or approval claim.

| Module | Responsibility | Depends on |
| --- | --- | --- |
| release-fixes | Resolve verified blockers without new feature growth | — |
| community-package | Exact separate directory identity and package verification | release-fixes |
| brand-assets | Original icon, banners and concise brand guide | community-package identity |
| directory-marketing | Readme, listing and launch drafts grounded in evidence | brand-assets, verified features |
| submission | Upload the verified artifact using the confirmed account | all above, account access, release gates |

Build order follows the user's request: fixes → branding → marketing → submission.
Brand and copy drafts may be prepared while external verification is pending;
publication is never evidence that an unverified feature works.

## Structure and commands

`plugin/` remains canonical runtime. `scripts/build-community-package.py` and
its parser helpers create the distinct Community archive. Directory artwork and
marketing live outside the core ZIP. Tests stay in `tests/` and the guarded
`plugin/tests/integration/` harness; reports/ZIPs remain ignored build artifacts.

Run with the locked development dependencies and PHP/Node available:

```
python3 scripts/test-published-release.py
python3 scripts/test-community-runtime.py
python3 scripts/test-community-package.py
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=2G
npm run lint:js
npm run test:tooling
bash scripts/build-release.sh dist/github-compatibility.zip
python3 scripts/build-community-package.py build --canonical dist/github-compatibility.zip --output dist/community-candidate.zip
python3 scripts/check-community-package.py --canonical dist/github-compatibility.zip --community dist/community-candidate.zip --report dist/community-local-checks.json
```

Use the existing WordPress coding style and prepared identifiers; for example:
`$wpdb->prepare( 'SELECT id FROM %i WHERE subscription_id = %d', $table, $id )`.
Keep the GitHub Update URI protection. Community omits it and renames only
reviewed presentation/translation fields, never business identifiers or history.

## Acceptance and evidence

- Current canonical, Community parser/archive and hosted installed-byte/HPOS
  checks pass on the actual candidate. Preserve both distribution paths and
  GPL/third-party notice bytes. Community has zero official Plugin Check errors;
  warnings receive explicit triage instead of blind identifier/hook renaming.
- Directory metadata uses the confirmed contributor, consistent product name,
  slug/main/text domain and accurate dependency/version information.
- Artwork uses original vector sources plus exact WordPress icon/banner sizes.
  Screenshots show real fabricated-data UI, not invented features or testimonials.
- Copy describes supported workflows, gateway/service dependencies and fees,
  license scope and important limitations. Do not claim production payment
  certification, performance improvements, automatic inbox cancellation or
  directory approval without evidence.
- Gateway sandbox/crash tests, rollback, time-zone/cache and browser/accessibility
  gaps remain tracked release gates; local doubles do not close them.
- Submission uses the verified ZIP and confirmed WordPress.org account. Read back
  the receipt/ticket/result. If login, reviewer feedback or a required release
  gate is unavailable, preserve the completed package/brand/copy and identify
  the exact remaining dependency; never invent a submitted/approved status.

## Scope boundaries and sources

No new dashboard, campaign engine, delivery scheduler, paid dependency, live
store/provider mutation, customer message or unrelated marketing publication.
The original no-live-store boundary remains. Account secrets stay out of code,
logs and chat. Actual submission is authorized once the concrete package is
verified and the account is accessible; this does not authorize a public release.

Official sources retrieved 2026-10-21:
- https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- https://wordpress.org/plugins/developers/add/

WordPress derives the initial slug from the plugin display name. Use one product
name across distributions; explain installation identity separately, rather than
adding an unrequested Community edition name that changes the proposed slug.
