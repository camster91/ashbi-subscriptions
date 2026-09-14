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
- Use independent Ashbi branding and a distinct plugin slug/text domain.
- Do not imply endorsement by Convers Lab, WPSubscription, WordPress, or WooCommerce.

## Commercial model

The plugin license fee is initially $0. Ashbi may charge for implementation,
migration, configuration, support, monitoring, hosting, or maintenance. Client
access to the source and GPL rights must not depend on an active support plan.

## Release checklist

Before any client distribution, obtain a human legal review of trademark use,
copyright notices, bundled dependency licenses, privacy disclosures, and the
client agreement. This file is project guidance, not legal advice.
