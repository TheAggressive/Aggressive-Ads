# Aggressive Ads

**Live means live.** White-label advertising management for WordPress.

Advertisers build campaigns. Staff review them. Approval publishes. Native fill
is the only publisher: there is no AdSanity, no downstream ads CPT, and no
theme dependency. Display name and logo are settings; the code prefix is
`aggr_` forever.

| | |
|---|---|
| Plugin | Aggressive Ads `0.1.0` |
| Slug / text domain | `aggressive-ads` |
| Namespace | `Aggressive\Ads` |
| REST | `aggr/v1` |
| Default UI name | Advertising (Brand settings may replace it) |
| Author | [The Aggressive, LLC](https://theaggressive.com) |
| Repository | [TheAggressive/Aggressive-Ads](https://github.com/TheAggressive/Aggressive-Ads) |

## What it does

**Advertiser portal** at `/advertiser/` — its own document, not a page with a
block. Sign-in, signup, password setup and recovery are portal-owned;
`wp-login.php` is untouched and is how staff reach wp-admin. Advertisers never
enter wp-admin (`Admin_Guard`). Screens: dashboard, campaigns, six-step wizard,
organization, account, help.

**Staff admin** under one Advertising menu: Review, Organizations, Inventory,
Packages, Settings. Distinct capabilities; `aggr_access_staff` is derived, never
granted on a role. Inventory is the placement catalogue (common IAB sizes or
custom W×H). Packages are staff-written; campaigns keep snapshots.

**Native delivery** is always on, not a Modules checkbox. Editors place
`aggr/placement` (or `aggr_placement( 'header-728x90' )` / the shortcode).
Cached HTML is a reserved slot. After paint: `GET /aggr/v1/fill/{slot}`,
`POST /aggr/v1/i` beacon, first-party click hop `/ads/c/{token}`. Equal rotation
among live campaigns on a slot; impressions and clicks follow the filled token.

**Reporting** (when the Reporting module is on) reads `aggr_rollups`: dashboard
tiles, a seven-day sparkline, campaign list/detail columns, REST
`impressions` / `clicks` / `ctr`. House and other orgs are excluded in SQL.
Spend stays absent until billing has a source.

**White-label** is Brand settings: product name, logo, tagline, contrast-gated
`--aggr-*` tokens. A host theme may override tokens; the portal is fully
functional without that.

## Status

Built: campaign domain (eleven statuses, 22 legal edges), org-scoped ownership,
private creative storage, portal and staff UI, notifications, campaign clock
(approved → scheduled → live → complete), native fill, site-scoped tenancy,
GitHub updater with SHA-256 verification, Playwright + axe.

Not built, even where `docs/` describes the design: CSV reporting, i18n
tooling, spend/billing. If a doc describes something you cannot find, it has
not been built.

## Requirements

|           |                                      |
| --------- | ------------------------------------ |
| PHP       | 8.4+                                 |
| WordPress | 6.7+ (wp-env runs 7.0.2)             |
| Node      | 24.x                                 |
| pnpm      | 11.x (`packageManager` is `11.1.2`)  |

No runtime Composer packages. `vendor/` is dev tooling and never ships. The
production autoloader is `inc/class-autoloader.php`. See
[build-and-release.md](docs/build-and-release.md).

## Getting started

```bash
composer install      # PHPCS, PHPStan, PHPUnit — vendor/ never ships
pnpm install          # frontend tools + staged-file Git hooks
pnpm env:start        # dev http://localhost:9960, tests :9970
pnpm build            # src/ → dist/
pnpm dev:seed         # an advertiser, an org, and five campaigns
pnpm qa:fast          # deterministic pre-push quality gate
pnpm qa               # full release rehearsal (Docker + browser dependencies)
```

wp-env mounts this checkout at `wp-content/plugins/aggressive-ads`. Sign in as
the seeded advertiser at `/advertiser/login/`. Staff use wp-admin.

## Commands

```bash
pnpm lint:php                # PHPCS — WordPress + VIP-Go + PHPCompatibility
pnpm analyse:php             # PHPStan level 8, no baseline
pnpm test:php:unit           # no WordPress, no database
pnpm test:php:integration    # integration / security / rest / upgrade (needs wp-env)
pnpm test:php:multisite      # colliding-id tenancy (needs wp-env)
pnpm lint:js                 # ESLint on src/
pnpm typecheck               # tsc --noEmit
pnpm lint:css                # Stylelint on every authored CSS file under src/
pnpm test:js                 # Jest on Interactivity helpers
pnpm lint:files              # file length, repository boundary, permission callbacks
pnpm ci:coverage             # quantitative unit-coverage regression gate
pnpm test:e2e:install        # install Chromium, WebKit, and required system libraries
pnpm test:e2e                # Playwright + axe (needs wp-env, after pnpm build)
pnpm qa:fast                 # pre-push checks; no Docker or browsers
pnpm qa                      # every CI lane, serially; needs Docker
```

Each `ci:*` script maps 1:1 onto a GitHub Actions job. Adding a lane means
adding it to **both** the workflow and `bin/ci/verify.sh`.

`pnpm install` enables the repository's Git hooks. Pre-commit formats and
re-stages only selected files, commit-msg enforces Conventional Commits, and
pre-push runs `pnpm qa:fast`. The full `pnpm qa` rehearsal provisions
Playwright's browser and operating-system dependencies and may request elevated
permission on Linux. See [build-and-release.md](docs/build-and-release.md) for
the CI graph, release artifact chain, and branch-protection contract.

## Architecture

```
aggressive-ads.php                 header, constants, floor guard, hand-off
 └ inc/class-autoloader.php        Aggressive\Ads\X\Y_Z → inc/X/class-y-z.php
 └ inc/class-plugin.php            boot + ordered init_services()
 └ inc/class-service-registrar.php factories — instantiates nothing
```

Two boundaries fail the build when crossed:

- **`inc/Repository/` is the only place data access appears.** No `WP_Query`,
  `get_posts()`, `get_post_meta()`, `$wpdb` anywhere else in `inc/`.
- **`inc/Domain/` calls no WordPress function.** Campaign rules stay unit-testable
  without a bootstrap.

Start with [docs/README.md](docs/README.md). Short version:

| Doc | Covers |
|---|---|
| [architecture.md](docs/architecture.md) | Layers, native fill, Inventory, no downstream ads CPT |
| [domain-model.md](docs/domain-model.md) | Five entities, storage, invariants |
| [campaign-workflow.md](docs/campaign-workflow.md) | The state machine every status write goes through |
| [roles-and-capabilities.md](docs/roles-and-capabilities.md) | Who may do what |
| [threat-model.md](docs/threat-model.md) | Attack surfaces and the test proving each |
| [roadmap.md](docs/roadmap.md) | What shipped vs remaining product |
| [suite-roadmap.md](docs/suite-roadmap.md) | Suite build order |

## License

GPL-2.0-or-later.
