# CLAUDE.md — archive

Moved out of `CLAUDE.md` because it is *history*, not *rules*. Every turn of
every session re-read it: 478 lines measured at 11,261 tokens, and this file
is 60% of that. What is built is already recorded in `docs/roadmap.md`,
`docs/open-work.md` and git; what these incidents taught is now compressed
into the Testing and Gotchas sections that stayed behind.

Read this when you need the *why* behind a rule. Do not re-inline it.

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
- `src/blocks-interactivity/ad-slot/` — the Block Editor slot (`aggr/ad-slot`),
  compiled to `dist/blocks-interactivity/`. **Blocks are split the way the LAAO
  and Aggressive Apparel themes split them:** `src/blocks/` for standard blocks
  (`build:blocks`), `src/blocks-interactivity/` for Interactivity API blocks
  (`build:interactivity`, `--experimental-modules`), `src/interactivity/` for
  shared stores. Core `supports` (align, spacing, color, border) style the
  reserved box; fill still happens after paint, now through an
  `@wordpress/interactivity` store rather than a DOM script — rotation needs
  per-slot state, and the old `data-aggr-filled="1"` latch made a second fill
  impossible by construction. Renamed from `aggr/placement` in 1.6.0;
  `Placement_Slot::LEGACY_BLOCK` keeps the old name registered and out of the
  inserter so existing content renders untouched, and nothing rewrites
  `post_content`
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
  rollup tables. Native is the only publisher, and impressions and clicks follow
  the filled token, not whichever campaign happened to be queried first
- the **decision engine** (P3–P9) replaces rotation. `Workflow\Decision_Engine`
  runs an ordered pipeline of pure `inc/Domain/` stages — eligibility, exact
  schedule and dayparts, targeting, frequency, pacing, priority, weighted
  selection — over `candidates_for_placement()`, and every candidate leaves
  every stage with a reason. `POST /aggr/v1/decisions` decides a whole page at
  once with competitive separation; `GET /aggr/v1/placements/{id}/decision` is
  the staff-only trace, `no-store`, replayable from a supplied clock and seed.
  See `docs/platform-p3-decision-engine.md`
- the **measurement model** (P10): `request`, `fill`, `no_fill`, `served`,
  `viewable`, `click`, `conversion`. `served` replaced `impression`; rollups
  count both, so history predating the rename still aggregates
- the staff campaign review screen carries a **delivery policy panel** per line
  item — priority, pacing, caps, and the three JSON fields. The JSON is edited
  as text on purpose: the server validates every shape and names the problem, so
  a rule builder can arrive later without changing what is stored.
  `ReviewStringsTest` guards the catalog, because `t()` answers a missing key
  with an empty string and an unlabelled input looks merely unfinished
- delivery policy is **configurable and validated**: `targeting_rules`,
  `frequency_policy` and `delivery_settings` are accepted on the line-item
  route and checked by the Domain class that evaluates each shape. The save is
  strict *because* serve time is permissive — an unrecognised targeting node
  passes during a fill rather than blanking inventory, so a malformed rule that
  reached storage would target nobody forever
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
