# Aggressive Ads

**Live means live.** White-label advertising management for WordPress.

Advertisers build campaigns. Staff review them. Approval publishes. Native fill
is the only publisher: there is no AdSanity, no downstream ads CPT, and no
theme dependency. Display name and logo are settings; the code prefix is
`aggr_` forever.

| | |
|---|---|
| Plugin | Aggressive Ads `1.5.0` |
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
`POST /aggr/v1/i` beacon, first-party click hop `/ads/c/{token}`. Impressions
and clicks follow the filled token.

**The decision engine** picks what fills a slot. An ordered pipeline of pure
stages — eligibility, exact schedule and dayparts, targeting, frequency, pacing,
priority, weighted selection — and every candidate leaves every stage with a
reason, so "why is this ad here?" has an answer. `POST /aggr/v1/decisions`
decides a whole page at once with competitive separation.
`GET /aggr/v1/placements/{id}/decision` returns the decision and its full trace
to staff only, replayable from a supplied clock and seed.

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
(approved → scheduled → live → complete), assignment-based native fill, the
decision engine with targeting, frequency, pacing and priority stages plus
staff-only traces, the request-to-conversion measurement model, CSV reporting
export, i18n tooling and four locale catalogs, site-scoped tenancy, GitHub
updater with SHA-256 verification, Playwright + axe.

Not built, even where `docs/` describes the design: spend/billing. If a doc
describes something you cannot find, it has not been built.

## Requirements

|           |                                      |
| --------- | ------------------------------------ |
| PHP       | 8.4+                                 |
| WordPress | 6.7+ (tests run 7.1)                 |
| Node      | 24.x                                 |
| pnpm      | 11.x (`packageManager` is `11.1.2`)  |

No runtime Composer packages. `vendor/` is dev tooling and never ships. The
production autoloader is `inc/class-autoloader.php`. See
[build-and-release.md](docs/build-and-release.md).

## Getting started

```bash
composer install      # PHPCS, PHPStan, PHPUnit — vendor/ never ships
pnpm install          # frontend tools + staged-file Git hooks
pnpm build            # src/ → dist/
pnpm dev:seed         # an advertiser, an org, and five campaigns
pnpm qa:fast          # Docker-free code quality and unit gate
pnpm qa:local         # qa:fast + the WordPress suites + Studio browser workflows
pnpm qa               # exact containerized CI rehearsal
pnpm qa:fresh         # recreate the environment, then run the same rehearsal
```

`qa:local` discovers the Studio site whose
`wp-content/plugins/aggressive-ads` resolves to this checkout, starts it, and
runs Playwright against the URL Studio reports for it — never one assembled
from a port, which would get the scheme wrong for a site with HTTPS enabled. Set
`AGGR_STUDIO_PATH=/path/to/site` when two sites serve the checkout and the runner
asks you to choose, and `AGGR_STUDIO_URL` when Studio reports no address or you
need to override the one it gives.

That site has to opt in before anything runs, because the suite resets the
`admin` and `advertiser` passwords to match its fixtures and seeds fixture
campaigns — and nothing puts either back:

```bash
touch /path/to/studio/site/.aggr-e2e-site   # or: AGGR_STUDIO_E2E_ALLOW=1
```

Theme, permalink structure and the mail-capture mu-plugin are captured up front
and restored on the way out, whether Playwright passes or fails.

`home` and `siteurl` are the exception: they are set from Studio and left that
way. Studio assigns the port and can reassign it, so a URL captured before a run
can be stale by the next one — restoring it would put the site back to an
address nothing serves. Studio is the source of truth for where a Studio site
lives; `AGGR_STUDIO_URL` overrides it.

Docker Compose remains the reproducible CI environment. It mounts this
checkout into the pinned Docker Official WordPress image and starts a
disposable MySQL database; local Studio uses SQLite, so MySQL integration,
multisite, combined coverage, release-artifact, and forward-PHP checks remain
authoritative in GitHub CI and available locally through `pnpm qa`.

## Commands

```bash
pnpm lint:php                # PHPCS — WordPress + VIP-Go + PHPCompatibility
pnpm analyse:php             # PHPStan level 8, no baseline
pnpm test:php:unit           # no WordPress, no database
pnpm test:php:integration    # integration / security / rest / upgrade
pnpm test:php:multisite      # colliding-id tenancy
pnpm lint:js                 # ESLint on src/
pnpm typecheck               # tsc --noEmit
pnpm lint:css                # Stylelint on every authored CSS file under src/
pnpm test:js                 # Jest on Interactivity helpers
pnpm lint:files              # file length, repository boundary, permission callbacks
pnpm ci:coverage             # combined unit + integration PCOV coverage
pnpm test:e2e:browsers       # install Chromium and WebKit
pnpm test:e2e:install        # the same, plus system libraries (needs sudo; what CI runs)
pnpm test:e2e                # Playwright + axe (after pnpm build and env:start)
pnpm test:php:native         # the WP suites natively; no Docker, no sudo
pnpm db:local                # start|stop|status|destroy the local test MySQL
pnpm test:e2e:studio         # Playwright + axe against the current Studio site
pnpm qa:fast                 # Docker-free pre-push code and unit checks
pnpm qa:local                # qa:fast + native WP suites + Studio browser workflows
pnpm qa                      # every CI lane, serially; requires a clean worktree
pnpm qa:fresh                # clean database/container rehearsal
pnpm env:stop                # remove the disposable containers and database
```

Each `ci:*` script maps 1:1 onto a GitHub Actions job. The local rehearsal
derives its commands from that workflow so the two cannot drift.

`pnpm install` enables the repository's Git hooks. Pre-commit formats and
re-stages only selected files, commit-msg enforces Conventional Commits, and
pre-push runs `pnpm qa:fast`, which needs no Docker at all — ShellCheck comes
from a checksum-pinned binary in `.cache/ci/`, the same way the i18n lane gets
WP-CLI. Run `pnpm qa:local` when a change touches a browser workflow. The full `pnpm qa` rehearsal installs Playwright's
browsers but not their system libraries, so it never asks for a password; CI
installs those with `--with-deps` on a bare runner. See
[build-and-release.md](docs/build-and-release.md) for the CI graph, release
artifact chain, and branch-protection contract.

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
| [platform-p3-decision-engine.md](docs/platform-p3-decision-engine.md) | The serving pipeline, its stages and trace contract |
| [roadmap.md](docs/roadmap.md) | What shipped vs remaining product |
| [suite-roadmap.md](docs/suite-roadmap.md) | Suite build order |

## License

GPL-2.0-or-later.
