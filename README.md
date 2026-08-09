# LAAO Advertiser Portal

Self-service advertising portal for [LAArtsOnline](https://laartsonline.com). Advertisers build their own campaigns; staff review and approve; approval publishes and configures the campaign in AdSanity automatically.

The portal owns the business workflow. AdSanity owns ad delivery. The LAAO theme owns site presentation. Those boundaries hold throughout this codebase.

## Status

**Phase 1 — foundation.** No campaign can be created yet, deliberately: the security foundation lands before the UI does. See [docs/roadmap.md](docs/roadmap.md).

## Requirements


|           |                                                               |
| --------- | ------------------------------------------------------------- |
| PHP       | 8.4+                                                          |
| WordPress | 6.7+                                                          |
| Node      | 24.x                                                          |
| pnpm      | 11.x                                                          |
| AdSanity  | 2.0.1+ — **optional**; the portal degrades, it does not break |


The plugin has **no theme dependency**. It runs under Twenty Twenty-Five with no functional degradation, and that claim is enforced by an e2e test rather than by discipline. See [ADR-0001](docs/adr/0001-standalone-plugin-zero-theme-dependency.md).

## Getting started

```bash
composer install     # dev tooling only — vendor/ never ships
pnpm install
pnpm ci:verify       # the contract for declaring a change finished
```

`vendor/` is dev-only. The plugin has no runtime Composer dependencies and ships with its own autoloader. See [ADR-0011](docs/adr/0011-no-composer-runtime-dependencies.md) and [ADR-0012](docs/adr/0012-own-autoloader-in-production.md).

### Commands

```bash
pnpm lint:php          # PHPCS — WordPress + VIP security/performance
pnpm analyse:php       # PHPStan level 8, no baseline
pnpm test:php:unit     # unit suite — no WordPress, no database
pnpm lint:files        # file length, architecture boundaries, permission callbacks
pnpm ci:php            # all three PHP gates
pnpm ci:verify         # every lane, serially, as CI would
```



## Documentation

Start with [docs/README.md](docs/README.md), which gives a reading order and indexes the seventeen [Architecture Decision Records](docs/adr/).

The short version:

- [architecture.md](docs/architecture.md) — the layers, and the rules that keep them apart
- [domain-model.md](docs/domain-model.md) — five entities, their storage, their invariants
- [campaign-workflow.md](docs/campaign-workflow.md) — the state machine every status write goes through
- [threat-model.md](docs/threat-model.md) — attack surfaces, mitigations, and the test proving each
- [adsanity-integration.md](docs/adsanity-integration.md) — what AdSanity actually does, verified against its source



## License

GPL-2.0-or-later.
