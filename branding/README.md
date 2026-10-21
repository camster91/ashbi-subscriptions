# Ashbi Subscriptions brand

**Recurring orders. Clear next steps.**

For owners of small WooCommerce stores who need recurring purchases and a clear
way to handle renewals and cancellation. Free GPL software; payment processing
and other services can have fees. No claims of provider certification or directory
approval until verified.

The original mark combines an A with a return arrow: a recurring cycle with a
clear direction. Created for this project; no upstream vendor artwork or platform
logos are reused. Sources and exported artwork are GPL-2.0-or-later, like the
project. Keep the mark upright, give it at least one quarter of its width in
clear space, and use the supplied light/dark treatment without stretching.

| Colour | Value | Use |
| --- | --- | --- |
| Ashbi orange | `#ff4d00` | Existing product accent; decorative highlights |
| Warm ink | `#211c1a` | Body text and icon background |
| Paper | `#fff8ed` | Background and inverse icon |
| Muted ink | `#675750` | Secondary text on paper |
| Arrow orange | `#ff6a2b` | Mark on warm ink |

Do not use white small text on the bright orange accent. Keep body text warm ink
on paper and use labels/icons alongside colour when conveying state. The product
keeps its system UI font; artwork uses the open DejaVu Sans face with a generic
sans-serif fallback. No webfont request or new runtime dependency is introduced.

Voice: plain, specific, calm. Say “Future renewals are blocked” only when the
saved result confirms it. Say “Request received” when a message still needs review.
Do not promise every gateway, recovered revenue, zero failures or instant support.

Sources: `sources/icon.svg`, `sources/banner.svg`. Directory exports are in
`../wordpress-assets/`: icons 128/256, standard banner 772×250, retina 1544×500.
SVG icon includes a title/description. Banners are decoration; repeat their
message as accessible HTML wherever used outside the WordPress listing.

Assets are published separately under the WordPress SVN `/assets` directory
only after directory access. They are deliberately excluded from plugin ZIPs.
`screenshot-1.png` is an unchanged capture of the real local merchant screen from
the small-store verification, using fabricated records and a non-charging gateway.
It is not a mockup or evidence that a live payment succeeded.

Render SVG sources at their target viewport with Inkscape, e.g.
`inkscape branding/sources/banner.svg --export-width=1544 --export-height=500 --export-filename=wordpress-assets/banner-1544x500.png`.
Inspect every export and verify PNG dimensions before upload.
