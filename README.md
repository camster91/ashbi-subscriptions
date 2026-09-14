# Ashbi Subscriptions

A client-run WooCommerce subscriptions plugin based only on the GPL-licensed
WordPress.org release of `subscription` (WPSubscription free). The initial
commercial policy is a $0 plugin license for everyone. Installation,
migration, support, monitoring, and managed updates may be offered separately.

## Status

Planning repository. No production-ready payment code has been imported yet.
An implementation agent should begin with [AGENTS.md](AGENTS.md) and
[docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md).

## Non-negotiable boundaries

- Import only the public WordPress.org GPL package and its compatible dependencies.
- Do not import, decompile, copy, or depend on WPSubscription Pro code or license keys.
- Preserve upstream copyright and license notices.
- Replace upstream trademarks, logos, screenshots, upgrade links, and vendor-specific copy.
- Keep distributed derivative code GPL-2.0-or-later.
- Never deploy payment lifecycle changes without automated integration tests and a staged renewal test.

## Intended first release

Version `0.1.0` should provide a clean, rebranded, updateable fork with parity
for the public free plugin: simple subscriptions, flexible billing intervals,
free trials, admin/customer subscription views, and guest checkout.

## Documentation

- [Implementation plan](docs/IMPLEMENTATION_PLAN.md)
- [Feature matrix](docs/FEATURE_MATRIX.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Security and payments](docs/SECURITY.md)
- [Legal provenance](docs/LEGAL_PROVENANCE.md)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
