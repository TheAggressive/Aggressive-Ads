# Known issues

Things that are true, annoying, and worth writing down so nobody rediscovers them. Each says what it is, what it costs, and whether anything can be done.

## Script modules have no translation mechanism below WordPress 7.0

**What.** `wp_set_script_translations()` does not work for the Script Modules API, which the Interactivity API is built on. Below WordPress 7.0 there is no supported way to ship translated strings to an Interactivity store.

**Cost.** Every user-facing string in a store has to be translated in PHP and hydrated through `wp_interactivity_state()`, and TypeScript may never contain a literal a user will read.

**Status — core has closed this, and the plugin cannot use it yet.** WordPress 7.0 added `WP_Script_Modules::set_translations()` and `print_script_module_translations()` (verified against the 7.1 core in `.cache/ci/wordpress`; both carry `@since 7.0.0`). This plugin's floor is **6.7** — `AGGR_MIN_WP` in `aggressive-ads.php`, and `Requires at least` in the header — so on a supported install the API may simply not be there.

**What removing the hydration would take,** in order: raise `AGGR_MIN_WP` to `7.0` and the plugin header with it; call `set_translations()` for each registered module in `Assets\Assets`; produce the module JSON catalogs in `bin/i18n/compile.sh`; then delete the string members from the `wp_interactivity_state()` payloads and let TypeScript hold the literals.

Raising the floor is the whole cost, and it is not a translation decision — it decides which sites can install the plugin at all. Until somebody wants to make that call, the hydration convention stays and is not a workaround for a gap so much as the price of supporting 6.7. See [interactivity-stores.md](interactivity-stores.md).

## The reviewer-queue e2e test failed once on a cold container

**What.** On the v1.1.1 release run, `tests/e2e/review.spec.ts:15` took 10.8s
against Playwright's 10s expect timeout and failed. A re-run of the same commit
passed, and tests 7 and 8 in the same file — which drive the same screens —
passed in both runs. It is the first test in that file to mount the admin React
bundle after a `wp-login.php` round trip on a freshly started container.

**Cost.** `Package` and `Release` depend on `e2e`, so it blocks a release until
somebody re-runs the job. It cost the first attempt at v1.1.1.

**Status.** Reproduced once more, on the v1.4.0 release run of 2026-08-23, on a
*different* spec: `placement-mapping.spec.ts` waiting for the Inventory heading.
Same shape — the first mount of an admin React bundle on a freshly started
container — and a re-run of the same commit went green, which is what let the
release finish. So this is a class rather than one test, and the entry stays
open. Three of
the four candidate causes are eliminated, measured against a WordPress Studio
site (native PHP, SQLite), which models the container only loosely:

* **Not the REST round trip.** The failing assertion waits 16 ms warm. The
  screen bootstraps from a server-rendered `data-aggr-review` attribute, so
  React mounts synchronously and the `<h1>` never waits on a fetch.
* **Not the server render.** Stopping and restarting the site for a genuinely
  cold PHP process gave 0.74 s for the first review-screen response against
  0.65 s warm — about 90 ms of cold start, not seconds.
* **Not the login redirect,** though it looks exactly like a race: the test
  clicks `#wp-submit` and calls `page.goto()` without awaiting navigation.
  Playwright serialises navigations on a page, and injecting a 4 s delay into
  the login POST still lands on the review screen with the heading visible.
  **Do not "fix" this.**

That leaves first compile and parse of the review admin bundle, and whatever
Apache and MySQL do cold that a native-PHP SQLite site cannot reproduce.

**If it returns, do not guess.** The e2e job uploads `playwright-report/`,
`.playwright-results/` and `test-results/` on failure with seven-day retention,
and `trace: 'retain-on-failure'` is set, so the trace carries per-step timings
for the run that actually failed.

That instruction was unfollowable until 2026-08-23, and the v1.4.0 recurrence is
how it was discovered: the run uploaded nothing. Three causes compounded.
`actions/upload-artifact` excludes hidden files unless told otherwise, and
`.playwright-results/` is hidden, so every trace Playwright wrote was dropped.
`playwright-report/` never existed at all, because no HTML reporter was
configured. And `if-no-files-found` defaults to `warn`, so losing all of it
produced a yellow annotation rather than a failure. All three are fixed —
`include-hidden-files: true`, an `html` reporter, and `if-no-files-found: error`
— so the next occurrence leaves evidence, and a run that somehow leaves none
fails loudly instead of looking fine.

Pull that before changing anything, and
reopen an entry in [open-work.md](open-work.md). Do not reach for
`bin/ci/retry.sh`: it is deliberately scoped to network-bound setup steps,
because a retry around a test turns a fast red into a slow red and hides the
cold-start assumption that is the actual defect.
