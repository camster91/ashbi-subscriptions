# Legal provenance and distribution policy

## Permitted source baseline

The only permitted upstream is the public WordPress.org plugin package at:

`https://downloads.wordpress.org/plugin/subscription.zip`

The imported public release is version 2.0.0 and declared
`GPL-2.0-or-later` in both the plugin header and `composer.json`. Its immutable
record is:

- URL: `https://downloads.wordpress.org/plugin/subscription.2.0.0.zip`
- SHA-256: `232e0eb4bfb5535d1ddcb4de19aba2d60f2461b14f5af1c1354919faa9842a90`
- Unmodified import commit: `5508935`

The public package was published under the WPSubscription name by Convers Lab.
Those names remain here solely to identify the origin of the GPL-covered work;
they are not used as Ashbi product branding and do not imply endorsement.

Run `bash scripts/verify-upstream.sh` to download that exact package, verify
its digest, and compare it byte-for-byte with the imported tree.

## Prohibited material

Do not use WPSubscription Pro code, licensed binaries, license keys, customer
portal downloads, proprietary update endpoints, vendor logos, screenshots,
marketing copy, or documentation as source material. A paid subscription grants
use/support under the vendor terms; it is not treated here as permission to
redistribute premium code.

## Fork obligations

- Preserve original copyright and GPL notices for copied files.
- License the distributed derivative under GPL-2.0-or-later.
- Give each client the corresponding source code for the version supplied.
- Preserve license notices for Composer, JavaScript, fonts, and other assets.
- Document every imported upstream release and every local modification.
- The GitHub compatibility distribution uses independent Ashbi branding while
  retaining the legacy plugin directory, main filename, text domain, hooks,
  slugs, option keys, and database identifiers required for in-place upgrades
  and existing-store data compatibility. The separately built community
  directory candidate has a new basename/text domain but retains all business
  storage/hooks/aliases; see `COMMUNITY_PACKAGE.md`. Neither variant authorizes
  redistribution of private source or removal of legal notices.
- Do not distribute imported upstream or third-party provider logo assets; the
  release packager excludes unused image directories and built-in integration
  cards use neutral initials for nominative compatibility references.
- Do not imply endorsement by Convers Lab, WPSubscription, WordPress, or WooCommerce.

## Commercial model

The plugin license fee is initially $0. Ashbi may charge for implementation,
migration, configuration, support, monitoring, hosting, or maintenance. Client
access to the source and GPL rights must not depend on an active support plan.

## Release checklist

Before any client distribution, obtain a human legal review of trademark use,
copyright notices, bundled dependency licenses, privacy disclosures, and the
client agreement. This file is project guidance, not legal advice.

## Rebranding record

The Ashbi-maintained distribution is named **Ashbi Subscriptions**. Customer-facing
vendor sales, account, support, upgrade, and license-activation calls to action
are removed from the maintained branding surfaces. Original GPL, copyright, and
dependency notices remain intact. Technical identifiers inherited from the public
package are retained only where changing them could break installations, stored
data, integrations, translations, or extension compatibility.
