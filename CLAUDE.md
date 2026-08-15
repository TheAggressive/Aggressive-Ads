# CLAUDE.md — Aggressive Ads

Guidance for AI assistants working in this plugin.

Architectural patterns are adapted from the LAAO and Aggressive Apparel themes.
**Nothing is inherited at runtime**, and where the three differ, this file is
authoritative here. In particular: this is a plugin, not a theme; there is no
WooCommerce, no Tailwind history, the only public block is `aggr/placement`,
and the test stack is deliberately older than LAAO's.

## Read the docs first

This repository's architecture is already written down, in `docs/`. Do not
re-derive it, and do not restate it here.

| Question | File |
|---|---|
| How are the layers separated, and what enforces it? | `docs/architecture.md` |
| What are the entities and their invariants? | `docs/domain-model.md` |
| How does a campaign change status? | `docs/campaign-workflow.md` |
| Who may do what? | `docs/roles-and-capabilities.md` |
| What does native fill and Inventory do? | `docs/architecture.md` |
| What are we defending against? | `docs/threat-model.md` |
| What is the Aggressive Ads suite build order? | `docs/suite-roadmap.md` |

Product rules live in `docs/`. Put a reversed decision in the same living
doc in the same change; do not add an `adr/` log.

## Status — read this before assuming anything exists

The runtime and security foundation is built, while i18n/release automation and
implementation across later roadmap phases remain in progress. CSV reporting
and deeper analytics remain open. What is built:

- root plugin file, autoloader, service container, composition root
- five private post types, eleven campaign statuses
- the capability vocabulary and the two role matrices, installed by the installer
- the audit table, `Audit_Event`, and `Audit_Repository`, including
  object-and-organization-scoped timeline reads
- installer, version-driven upgrader with a tested migration walker, `uninstall.php`
- org-scoped `Ownership::map()`, `Org_Repository`, `Admin_Guard`, `Portal\Routes`
- `Domain\Transition_Table` — the 22 legal edges, with all 121 pairs asserted
- `Campaign_State_Machine`, the campaign validator, and the campaign/creative/
  placement repositories — submission works end to end
- `Integration\Native\Publisher` — fill-cache bust on publish/pause/resume;
  there is no downstream ads CPT
- Advertising → Inventory creates placements (common IAB sizes or custom WxH)
- wp-env (dev `:9960`, tests `:9970`) and the integration/security/rest/upgrade suites
- PHPCS / PHPStan / PHPUnit / structural guards, wired into `bin/ci/verify.sh`
- creative upload, two-stage private storage, the promoter, and the REST routes
- the portal route, `Portal\Router`, `Portal\View_Data`, and the screens it
  serves: dashboard, campaign list, campaign detail, 403, 404, placeholder
- advertiser draft creation and the first wizard step (details, placements,
  schedule, and advertiser notes), with an HTML form path, REST create/autosave,
  optimistic concurrency, and shared `Campaign_Editor` validation
- `src/styles/` — the `--aggr-*` token layer and components (`pnpm build` →
  `dist/styles/`), contrast-gated by `tests/php/Unit/Assets/PortalContrastTest.php`
- TypeScript Interactivity modules under `src/interactivity/` (dialog, wizard,
  autosave, upload, scroll-lock, helpers, logic), compiled to `dist/interactivity/`
- `src/blocks/placement/` — Block Editor slot (`aggr/placement`), compiled to
  `dist/blocks/placement/`. Core `supports` (align, spacing, color, border)
  style the reserved box; fill still happens after paint
- portal dialogs on the shared overlay: creative replace, live-ad preview,
  draft preview, and remove confirmation (no-JS `:target`, overlays in `wp_footer`)
- the capability-gated staff review queue and campaign review detail, with
  private creative previews, internal notes, transition controls, and audit
  history using the same design tokens
- capability-resolved, individualized submission/resubmission notifications,
  with per-recipient duplicate suppression, bounded cron retry, localized
  plain-text messages, and failure auditing that cannot reverse a transition
- release packaging and independent archive verification
- `Workflow\Campaign_Clock` — the hourly reconcile that drives approved →
  scheduled → live → complete, without which status freezes at approval
- `Workflow\Ending_Soon_Notifier` and `Notification\Ending_Soon_Mailer` — the
  seven-day live/paused reminder with receipt-backed fan-out
- `Workflow\Creative_Retention` — the daily ninety-day private-file purge
- staff review queue, Inventory (placement catalogue), and package catalogue screens, and the advertiser-facing
  notifications for changes, rejection, approval, going live and completion
- pause, resume and cancel, which need no new UI: the review screen's buttons
  are derived from `Transition_Table`, so an edge added there appears by itself
- Playwright + axe (`tests/e2e/`), wired into `ci:verify` as its own lane
- the portal's own sign-in form (`Portal\Login_Actions`), authenticated by
  core's `wp_signon()`. wp-login.php is untouched and is still how staff reach
  wp-admin; only the portal's gate was redirected away from it
- public advertiser signup, gated by WordPress's registration policy, with
  anonymous-client rate limiting, non-enumerating responses, compensating
  user/organization writes, and a core one-time password setup key used only
  through the portal-owned setup and recovery screens
- every portal route now has its own screen: dashboard, campaigns, organization,
  account, help, login, signup, password setup and password recovery. `Portal\Account_Actions` is the only way a portal user can
  write anything about their own user record, because `Admin_Guard` closes
  wp-admin to them
- `Core\Settings` / `Domain\Settings_Schema` — one `aggr_settings` option,
  module kill-switches, contrast-gated brand tokens. `aggr_manage_settings`
  finally gates a screen
- unified Advertising wp-admin parent (`aggr`); Review, Organizations,
  Inventory, Packages, and Settings are submenus with distinct caps. `aggr_access_staff`
  is derived, never granted on a role
- portal rail and sign-in screens read the Brand product name (default
  “Advertising”), optional logo, and optional tagline
- native delivery (always on, not a Modules checkbox): reserved placement slot, `GET /aggr/v1/fill/{slot}`,
  `POST /aggr/v1/i` beacon, first-party click hop `/ads/c/{token}`, events and
  rollup tables (schema v5). Native is the only publisher. Equal rotation among live
  campaigns on a slot; impressions and clicks follow the filled token, not the
  oldest live row.
- campaign copy: renew (completed) and duplicate (any readable campaign) create
  a new draft with the stored snapshot and private creative bytes, never a
  backwards transition. HTML form and `POST /aggr/v1/campaigns/{id}/copy`
- reporting from `aggr_rollups`: dashboard tiles, a seven-day sparkline, campaign
  list/detail columns, and REST `impressions`/`clicks`/`ctr` only while Reporting
  is on. House and other orgs are excluded in SQL. Spend stays absent.
- site-scoped tenancy: fill tokens and fill-cache keys bind `blog_id`;
  network-active installs run on `wp_initialize_site`; a dedicated multisite
  PHPUnit config proves colliding post ids cannot cross sites. Network-wide
  organizations are out of scope.

What does **not** exist yet, despite being described in `docs/`: CSV reporting
and i18n tooling.
`docs/` describes the design; `docs/roadmap.md` says which phase builds it. If a
doc describes something you cannot find, it has not been built — that is
expected, not a bug.

The screens that exist are the ones with real data behind them. Impression,
click and CTR tiles, a seven-day sparkline, and table CTR appear only when
Reporting is on.
Spend stays absent until billing has a source.

## Commands

```bash
composer install        # dev tooling only; vendor/ never ships
pnpm install            # webpack / TypeScript / Playwright
pnpm build              # src/ → dist/
pnpm wp-env start       # dev :9960, tests :9970
pnpm dev:seed           # an advertiser, an org and five campaigns to look at
pnpm ci:verify          # the contract for declaring a change finished

pnpm lint:php           # PHPCS
pnpm analyse:php        # PHPStan level 8, no baseline
pnpm test:php:unit      # unit suite — no WordPress, no database
pnpm test:php:integration  # WP integration/security/rest/upgrade (needs wp-env)
pnpm test:php:multisite    # colliding-id tenancy; WP_TESTS_MULTISITE (needs wp-env)
pnpm lint:js            # ESLint on src/
pnpm typecheck          # tsc --noEmit
pnpm lint:css           # Stylelint on src/styles/
pnpm lint:files         # file length + architecture boundaries + permission callbacks
pnpm lint:php:fix       # phpcbf
```

Every `ci:*` script maps 1:1 onto a CI job. Adding a lane means adding it to
**both** the workflow and `bin/ci/verify.sh`; adding it to one is how the two
drift.

## Architecture, in brief

```
aggressive-ads.php   header, constants, floor guard, hand-off. Never a fifth job.
  └ inc/class-autoloader.php   Aggressive\Ads\X\Y_Z → inc/X/class-y-z.php
      └ inc/class-plugin.php   boot + ordered init_services()
      └ inc/class-service-registrar.php   register() factories — instantiates nothing
```

Registration stores a closure and runs nothing. Behaviour begins only at
`init_services()`. Adding a service costs two greppable edits: one
`Service_Registrar::register()` line and, when hooks are needed, one entry in
`Plugin::service_init_order()`. There is no autowiring to reverse-engineer.

Two boundaries carry most of the weight, and both fail the build when crossed:

- **`inc/Repository/` is the only place data access appears.** No `WP_Query`,
  `get_posts()`, `get_post_meta()`, `$wpdb` anywhere else in `inc/`.
- **AdSanity identifiers appear nowhere in `inc/` or `templates/`.**
- **`inc/Domain/` calls no WordPress function.** Constants from other classes
  are fine; a function call is not.

`inc/Domain/` may not call WordPress **at all**. That is what makes the campaign
rules testable in milliseconds with no bootstrap, which is what makes it
affordable to test them exhaustively.

## Testing

PHPUnit is pinned to **9.6**, not 13 like the LAAO theme. This is deliberate and
test-only: the assertions that matter here — org-scoped `map_meta_cap`, `dbDelta`
idempotence, real REST authorization, real uploads — are not expressible under
Brain\Monkey, and the WordPress core test suite requires 9.x. Two config files because **PHPUnit allows exactly one bootstrap per file**. The
unit suite must not load WordPress; the integration suite must.

`failOnWarning`, `failOnRisky`, `failOnSkipped` and `failOnIncomplete` are all
true. A skipped security test is a security test that is not running.

### Prove the test works

Write it, watch it pass, **break the implementation deliberately**, watch it
fail, read the failure message, restore, watch it pass.

This is not ceremony. Three tests here have already been caught passing for the
wrong reason, and each hid a real defect:

- The autoloader's path-traversal test asserted null against a path where
  nothing existed, so `is_file()` rejected it for an unrelated reason.
- The "no `wp/v2` route" test scanned a locally built `WP_REST_Server`, which
  never receives the routes — `register_rest_route()` writes to the global.
- The ownership tests exposed that `map_meta_cap` **never passes a custom meta
  capability to the filter**; it recurses with the generic `edit_post`. The
  filter was silently inert, and reads were being granted by core.

A test that passes for the wrong reason is worse than no test, because it
produces confidence. Assert your fixture is real before asserting on it.

## Gotchas that cost real time

- **`wp_posts.post_type` and `post_status` are `varchar(20)`.** A longer slug does
  not error — it truncates on write and then never matches on read, producing
  rows that exist and cannot be queried. `aggr_scheduled` is 14 characters.
  Do not invent a longer status slug.
- **No runtime Composer dependencies, ever.** WordPress has no dependency
  isolation; two plugins shipping different versions of one package fatal the
  site. `composer.json` `require` is `{"php": ">=8.4"}` and stays that way. See
  `docs/build-and-release.md` for the core substitution table before reaching
  for a package.
- **The production autoloader is ours, not Composer's.** `inc/class-autoloader.php`
  is listed in the packaging script's required files for that reason.
- **Your editor's PHPCS is not the project's.** IDE integrations often run stock
  WordPress standards and will flag `tests/php/**/FooTest.php` filenames.
  `phpcs.xml.dist` excludes `WordPress.Files.FileName` there on purpose — tests
  follow PHPUnit conventions. `vendor/bin/phpcs` is the authority.
- **Exception messages are exempt from the escaping sniff**, narrowly and with a
  reason in `phpcs.xml.dist`: they are boot-time developer diagnostics, never
  rendered. Anything a user can cause returns `WP_Error` instead.

## Working style

- **Verify before asserting.** Read the installed source rather than inferring
  from documentation or a plugin's UI.
- **Do not weaken a gate to get green.** Fix the cause, or change the rule
  deliberately with an ADR. A gate that fires on legitimate code is itself a
  defect — fix the pattern, do not add an exception.
- **Comments explain why, not what.** A comment restating the line below it is
  noise; one recording the incident that made the line necessary is the most
  valuable thing in the file.
- **Security, accessibility, idempotency and failure recovery are requirements,
  not a later pass.** They block a release.
- Conventional commits (`feat:`, `fix:`, `docs:`, `ci:`, …). semantic-release
  owns published versions; release packaging stamps the planned version into
  the staged plugin without rewriting the checkout.
