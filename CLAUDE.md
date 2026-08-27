# CLAUDE.md — Aggressive Ads

Guidance for AI assistants working in this plugin.

Architectural patterns are adapted from the LAAO and Aggressive Apparel themes.
**Nothing is inherited at runtime**, and where the three differ, this file is
authoritative here. In particular: this is a plugin, not a theme; there is no
WooCommerce, no Tailwind history, the only public block is `aggr/placement`,
and the WordPress test suites run an older PHPUnit than LAAO's — the unit suite
does not.

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
| What is the platform sequence beyond today? | `docs/platform-implementation-progress.md` |
| How do pull requests get merged without me? | `docs/pull-request-automation.md` |
| What is half-finished right now, and why? | `docs/open-work.md` |

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
- digest-pinned WordPress 7.1 + MySQL 8.4 Compose stack and the integration/security/rest/upgrade suites
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
- `Notification\Request_Mailer` — the staff email for an advertiser's request
  against a running campaign, on its own `aggr_notify_advertiser_request` hook
  and its own `_aggr_request_revision` counter, because a request is a meta
  write rather than a transition
- release packaging and independent archive verification
- rewrite rules are declared as data — `Router::rules()` and `Click_Hop::rules()`
  are pure and static — so one definition serves the installer, the Site Health
  assertion and `bin/ci/check-rewrite-version.php`, which fingerprints them
  against an append-only contract and fails the build on a rule change that
  forgets its `REWRITE_VERSION` bump
- `Workflow\Campaign_Clock` — the hourly reconcile that drives approved →
  scheduled → live → complete, without which status freezes at approval
- `Workflow\Ending_Soon_Notifier` and `Notification\Ending_Soon_Mailer` — the
  seven-day live/paused reminder with receipt-backed fan-out
- `Workflow\Creative_Retention` — the daily ninety-day private-file purge
- every wp-admin screen is now React over REST: Settings, Packages,
  Organizations, Inventory, and the review queue and campaign detail. The
  review screens keep the plugin's own design system (`src/styles/admin.css`);
  the other four use core's component set. Organizations is additionally a
  DataViews pilot — searchable table, writes behind row actions, and the one
  screen that pages, searches and filters **server-side**: `GET /organizations`
  returns one page plus the real total, and rosters arrive per-organization
  from `/detail` rather than riding on every row. Sorting is by name only,
  because owner/member/campaign counts are derived per row and ordering on them
  would mean assembling every organization in order to page it.
  `@wordpress/dataviews` is **compiled once and shared**, never externalised to
  core and never compiled per screen: WordPress 7.1 uses DataViews internally
  but registers no `wp-dataviews` script or style handle, so externalising it
  builds clean and throws in the browser — while compiling it per screen costs
  490 KB of script and 90 KB of CSS *each*, because admin entries share nothing
  (`splitChunks` is off and every `.asset.php` is its own enqueue).
  `webpack.dataviews.config.mjs` builds it into `dist/admin/dataviews.*`,
  `Admin\Shared_Assets` registers it as `aggr-dataviews`, and
  `webpack.admin.config.mjs` rewrites each screen's ordinary
  `@wordpress/dataviews` import onto that global and handle — so a screen
  imports the package normally and knows nothing about the arrangement. The
  registry is `bin/ci/bundled-packages.mjs`, read by both configs and by
  `bin/ci/check-admin-bundle.mjs`, which fails the build if a screen compiles
  its own copy, reads the global without declaring the handle, names a
  `wp-dataviews` handle core does not have, or drops the stylesheet to
  `"sideEffects": false` again
- the advertiser-facing notifications for changes, rejection, approval, going
  live and completion
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
- organization name lookups survive a salt rotation (schema v10). `active_key`
  is an index over plaintext the same row already stores, so it is salted with
  a plugin-owned option rather than `wp_salt( 'auth' )`, which rotates and used
  to orphan the whole registry — no renames, and no duplicate-name detection.
  `token_hash` still uses `wp_salt( 'auth' )` on purpose: it verifies a bearer
  token, so a rotation *should* invalidate it. An organization holding no
  identity row registers one on first rename instead of being refused forever
- CSV reporting — `Portal\Report_Actions` streams a rollup export for the
  acting organization
- i18n tooling — `bin/i18n/` (pot, sync, compile, status, check, locale,
  validate-po, translate, lint-placeholders) behind the `ci:i18n` lane, which
  `qa:fast` also runs so POT drift fails a push rather than a pull request.
  Four locale catalogs (de_DE, es_ES, fr_FR, it_IT) are committed as `.po`;
  `.mo` is build output. Machine translation is DeepL with a MyMemory fallback,
  and it only ever opens a pull request — `.github/workflows/i18n-translate.yml`
  — because unreviewed machine output must not reach a publisher.
  **Two silent failures are covered by `TranslationLoadingTest`:** a plugin's
  `.mo` keeps the `aggressive-ads-` prefix (the theme's rename is deliberately
  not ported), and JIT loading never searches a plugin's own folder, so
  `Plugin::load_translations()` must call `load_plugin_textdomain()` on `init`.
  Both render English with every other signal green
- private creative lives in `uploads/ads-uploads/` and is **encrypted at rest**
  — `Storage\Creative_Cipher`, XChaCha20-Poly1305 secretstream, chunked so
  reads stay constant-memory and a truncation is a read failure rather than
  half an image. The key comes from `AGGR_CREATIVE_KEY` in wp-config.php when
  it is defined and from the `aggr_creative_key` option otherwise; it is
  deliberately **not** derived from `wp_salt()`, for the reason schema v10
  already recorded. A file without the magic is passed through as plaintext, so
  db version 11 can migrate an existing install a file at a time without taking
  the review queue down. The original is deleted the moment a creative is
  promoted to its Media Library attachment, so only work awaiting review is on
  disk. Promoted attachments carry `_aggr_is_creative` and
  `Admin\Media_Library` keeps them out of the library
- a campaign cannot be submitted carrying the name the plugin invented for it:
  `create()` records `_aggr_title_is_placeholder`, and the validator refuses it
- the WordPress suites run natively as well as in Docker —
  `pnpm test:php:native` against `bin/local/mysql.sh`, and
  `pnpm test:e2e:studio` against a consenting WordPress Studio site.
  `AGGR_TESTS_RUNNER` selects the runner; Docker stays the CI contract

Everything `docs/` describes is now built. `docs/roadmap.md` says which phase
built it, and `docs/open-work.md` is the list of what is started and unfinished.
If a doc describes something you cannot find, check those two before assuming it
is missing.

The screens that exist are the ones with real data behind them. Impression,
click and CTR tiles, a seven-day sparkline, and table CTR appear only when
Reporting is on.
Spend stays absent until billing has a source.

## Commands

```bash
composer install        # dev tooling only; vendor/ never ships
pnpm install            # webpack / TypeScript / Playwright
bash bin/ci/install-wp-runner.sh   # PHPUnit 9.6 for the WordPress suites
pnpm build              # src/ → dist/
pnpm env:start          # disposable WordPress 7.1 at :9960
pnpm dev:seed           # an advertiser, an org and five campaigns to look at
pnpm qa:local           # Docker-free checks + E2E against WordPress Studio
pnpm ci:verify          # the contract for declaring a change finished

pnpm lint:php           # PHPCS
pnpm analyse:php        # PHPStan level 8, no baseline
pnpm test:php:unit      # unit suite — PHPUnit 13, no WordPress, no database
pnpm test:php:native    # the WP suites natively: local MySQL, no Docker
pnpm test:php:integration  # WP integration/security/rest/upgrade, PHPUnit 9.6 (needs env:start)
pnpm test:php:multisite    # colliding-id tenancy; WP_TESTS_MULTISITE (needs env:start)
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

Registrars are split by responsibility, not size: `Service_Registrar` holds
domain services, `Rest_Service_Registrar` the HTTP surface,
`Runtime_Service_Registrar` the hooked admin/delivery/lifecycle services, and
`Install\Migration_Map` the version-to-migration map. A mistake in a route
table exposes an endpoint; a mistake in a factory throws on boot; a mistake in a
migration runs once against real data. Different review standards, different
files.

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

**Two PHPUnits, and the config file picks which.** The unit suite runs
PHPUnit 13 from `vendor/`, matching the LAAO theme. The suites that load real
WordPress run PHPUnit 9.6 from `tests/wp/vendor/`, installed by
`bin/ci/install-wp-runner.sh`, because `WP_UnitTestCase_Base` calls
`PHPUnit\Util\Test::parseTestMethodAnnotations()` and PHPUnit removed that class
in 10 — measured, not assumed. `bin/ci/run-wp-tests.sh` selects the binary, so
no caller knows which suite is on which major. See `tests/wp/README.md`.

The WordPress suites exist at all because the assertions that matter here —
org-scoped `map_meta_cap`, `dbDelta` idempotence, real REST authorization, real
uploads — are not expressible under Brain\Monkey. Separate config files because
**PHPUnit allows exactly one bootstrap per file**: the unit suite must not load
WordPress; the integration suite must.

Unit tests extend `PHPUnit\Framework\TestCase` with `setUp()` / `tearDown()`
and `#[DataProvider]` attributes. The polyfills and `@dataProvider` docblocks
are gone from `tests/php/Unit` — PHPUnit 13 reads attributes, not doc comments.
The WordPress suites still use the polyfills through `WP_UnitTestCase`.

`failOnWarning`, `failOnRisky`, `failOnSkipped` and `failOnIncomplete` are all
true. A skipped security test is a security test that is not running.

### Test the dangerous things first

Anything that **deletes, grants, denies, or guards** gets a test before it is
called done. Hand verification does not count, however obvious the code looks.

Two paths shipped without one. `bin/ci/check-navigation.mjs` is the guard that
keeps the `js/xss-through-dom` sink CodeQL found from returning, and it had no
test — writing one immediately found an `EISDIR` crash in the guard itself, on a
branch hand-checking never reached. `Uninstaller::delete_private_files()`
removes an advertiser's only remaining copy of unapproved artwork, and its
sabotage test is the only thing standing between `rmdir( $root )` and
`rmdir( $root, true )`, which would silently destroy a directory this plugin
never created.

A guard that stops matching does not fail. It reports success over code it is no
longer reading. `check-navigation.mjs`, `check-coverage.mjs`, `check-rewrite-version.php`,
`check-boundaries.php`, `check-permission-callbacks.php` and the rules behind
`check-summary.mjs`, `check-action-pins.sh`, `check-ci-parity.sh`,
`check-styles.mjs`, `check-suppression-reasons.mjs`, `check-worktree.sh`, the
patched-dependency rules and the pull-request policy **all carry tests in
`test:tools`**. Every guard in `bin/ci/` is now covered.

The two security guards were done first, and both were broken. The permission
gate was a grep matching one spelling of one mistake: the same `__return_true`
wrapped onto a second line got through, so did `fn () => true` and
`function () { return true; }`, and so did a route with no `permission_callback`
key at all — which leaves nothing in the source to grep for and which WordPress
still registers as public. Both guards also passed over a *missing* directory,
because `|| true` and an "ok (nothing to scan yet)" branch each turned "I cannot
find the code I guard" into success. Both are now tokenizer-based, both fail on
an empty scan, and both print the file count so a vacuous run is visible.

The two supply-chain guards were done next and one of them had **never worked**.
`check-action-pins.sh` matched `^\s*uses:` — whitespace, then the key — while
actions are written as list items, `- uses: foo/bar@sha`. That dash meant the
pattern saw 9 of this repository's 81 `uses:` lines, so an unpinned action had
always passed the gate whose only job is stopping one.
`check-patched-dependencies.mjs` had the quiet version: an empty resolved set
skipped every assertion and left a round-trip smoke test that passes on
*unpatched* adm-zip too.

The last four came next, and the same shape was in three of them: a missing or
empty scan root produced "ok" over nothing. `check-suppression-reasons.mjs` also
never matched `phpcs:ignoreFile`, which disables *every* sniff in a file, nor
the deprecated `@codingStandardsIgnoreFile|Start|Line`, which PHPCS 3.13 still
honours and which has no `--` reason syntax at all — so a suppression written
that way could never justify itself and was never asked to.

**The pattern across all of them is one thing: a guard that stops matching
reports success.** When touching one, check what it actually reads before
trusting what it says — and make it print a count. Every guard now does.

`check-rewrite-version.php` is the worked example: its own test found that the
lookbehind distinguishing a call from a declaration read `$tokens[$i - 1]`,
which is the whitespace before `function`. The guard was reporting a
declaration as an installation, on a branch no hand-check had reached.

Two habits that earn their keep:

- **Assert a count, not just absence.** Three fixtures in one session were not
  what they looked like — 351 files left by an earlier run, a table that was not
  fresh, 26 deletions where one was expected. The count caught all three; "the
  file is gone" would have passed whether it deleted one file or the directory.
- **Assert the negatives.** For destructive code, what it must *not* touch is
  usually the more valuable half of the test.

### Prove the test works

Write it, watch it pass, **break the implementation deliberately**, watch it
fail, read the failure message, restore, watch it pass.

This is not ceremony. Five tests here have already been caught passing for the
wrong reason. The first three hid a real defect; the last two hid guards nobody
had ever seen work:

- The autoloader's path-traversal test asserted null against a path where
  nothing existed, so `is_file()` rejected it for an unrelated reason.
- The "no `wp/v2` route" test scanned a locally built `WP_REST_Server`, which
  never receives the routes — `register_rest_route()` writes to the global.
- The ownership tests exposed that `map_meta_cap` **never passes a custom meta
  capability to the filter**; it recurses with the generic `edit_post`. The
  filter was silently inert, and reads were being granted by core.
- `OwnershipTest`'s deleted- and nonexistent-object tests asked through
  `current_user_can()`, where **core denies a missing post before our filter
  has an opinion**. Both stayed green with the branch they document deleted.
- `AdminReviewTest`'s three transition-nonce tests set only `$_POST`, but
  **`check_admin_referer()` reads `$_REQUEST`**, which PHP does not populate
  from `$_POST` under CLI. All three presented no nonce and died identically;
  the "valid nonce, wrong capability" one never reached a capability check and
  passed with both gates on that path deleted. Posting to a handler in a test
  means setting `$_REQUEST` too.

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
