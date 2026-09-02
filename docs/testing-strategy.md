# Testing strategy

## Prove the test works

Before a meaningful test counts as done:

1. Write it and watch it pass.
2. **Break the implementation deliberately.**
3. Watch it fail — and read the failure message, which is what someone will see at 2am.
4. Restore.
5. Watch it pass.

A test that passes for a reason unrelated to the behaviour it names is worse than no test, because it produces confidence. The most common variety is a test that asserts on a mock it configured itself.

Two have already been caught here this way, and both are worth knowing:

**The autoloader's path-traversal test** asserted null against a path where nothing existed, so `is_file()` rejected it for an unrelated reason and the test passed with the guard removed. It now aims a `..` segment at a file that genuinely exists one level up.

**The "no `wp/v2` route" test** built its own `WP_REST_Server` and scanned it. But `register_rest_route()` resolves its target through `rest_get_server()`, which returns the **global** — so routes registered during `rest_api_init` never reached the local instance, and the test scanned an empty list. It passed just as happily with `show_in_rest => true` on all five post types. Assigning `global $wp_rest_server` first took the suite from 283 assertions to 920.

The lesson both share: **assert your fixture is real before asserting on it.** That test now checks `/wp/v2/posts` is present before concluding anything from the absence of ours.

Two more came from the creative-review notification, and both are about a
*second* mechanism quietly satisfying the assertion:

**A retry test was satisfied by the receipt, not by the guard it named.** It
uploaded, decided the creative, retried, and asserted silence — but every
recipient of that retry had already been sent to, so the per-recipient receipt
suppressed it and deleting the "is this still waiting?" check changed nothing.
The fix is a recipient created *after* the announcement, who holds no receipt,
plus a control retry that must deliver. Without the control, the silence still
proves nothing.

**A `pre_wp_mail` capture returned `true` over an earlier filter's refusal.**
`pre_wp_mail` runs the whole filter chain rather than stopping at the first
non-null value, so a capture at priority 10 that ignores its `$short_circuit`
argument overrides a refusal registered at priority 5. The failure-path test
built that way was asserting that a *successfully delivered* message did not
break the upload. Model transport failure as a value the capture returns, as
`RequestNotificationTest` does, rather than as a second filter.

Two more, from the WordPress suites' own machinery rather than from a mock:

**An index named in a query plan is not an index doing work.** `EXPLAIN`'s
`key` column says which index MySQL touched, not whether it served the filter —
it will happily use one for a `GROUP BY` while scanning every row for the
`WHERE`. Rebuilding P13's `day_outcome` as `(outcome)` alone, useless for a day
range, left the plan still naming `day_outcome` and the assertion still green.
**Assert rows examined**, which caught the same sabotage at 79,996 of 79,980.

**A schema assertion is worthless until the table is dropped.** `dbDelta` adds
an index and never drops one, so a DDL edit that changes or removes a key leaves
the old one in place, still enforcing the old rule, and the suite passes over
the change. This was watched happening during the P12 closeout: dropping the
word UNIQUE from the conversion ledger's deduplication key produced a fully
green run, and only the *second* run — against a table built from the edited
DDL — failed the eight tests it should have. A test asserting something about an
index must first establish that the table it is reading was built from the
schema under test.

**This suite cannot prove a table was created by dropping it first.**
`WP_UnitTestCase` rewrites `CREATE TABLE` and `DROP TABLE` into their
`TEMPORARY` forms, so a repository's `drop_table()` drops nothing and
`SHOW TABLES` cannot see what the suite created. `ConversionSchemaTest` records
what does work, including why it invokes the migration step directly rather than
through `maybe_upgrade()` — whose option-based lock survives the transaction
rollback in the object cache and silently disables a later test's upgrade.

And one about where an assertion belongs: **a REST `permission_callback` answers
before the workflow does**, so a denied request never reaches the manager and
never writes the audit row the manager would write. That is intended — an
unauthenticated probe that audited every attempt would be an unbounded write
anybody could drive — but it means a denial-audit assertion belongs in a manager
test, not a route test.

## Suites

| Suite | Config | Bootstrap | Needs |
|---|---|---|---|
| `unit` | `phpunit.xml.dist` | `tests/php/bootstrap-unit.php` | Nothing. No WordPress, no database |
| `integration` | `phpunit-integration.xml.dist` | `tests/php/bootstrap-wp.php` | WP test suite + MySQL |
| `security` | same | same | same |
| `rest` | same | same | same |
| `upgrade` | same | same | same |
| `multisite` | `phpunit-multisite.xml.dist` | `tests/php/bootstrap-wp.php` + `WP_TESTS_MULTISITE` | same, as a network |
| JS | `jest.config.js` | — | Node |
| E2E | `playwright.config.ts` | — | WordPress Studio locally; WordPress 7.1 Compose in CI |

`pnpm ci:coverage` runs both the isolated unit suite and the single-site
WordPress suites under PCOV in the official-image container. It unions their executable `inc/`
lines, so a statement hit by either suite counts once, and enforces a 69.75%
statement floor against the measured 69.86% PCOV baseline. The exact same run
reports 70.25% under Xdebug because it marks 53 `global` declarations as hit
while PCOV does not; no tested behavior differs. The separate configs and
bootstraps remain intact: collecting the unit report in the container does not
load WordPress into that suite.

Separate PHPUnit configs because **PHPUnit allows exactly one bootstrap per configuration file**. That is the reason for the split, not preference — the unit suite must not load WordPress, and the WordPress suites must. Multisite is its own file so colliding-id tests cannot `markTestSkipped()` on the single-site lane.

## Two PHPUnits, chosen by config file

| Suite | Config | Runner | Installed by |
|---|---|---|---|
| `tests/php/Unit` | `phpunit.xml.dist` | **PHPUnit 13** — `vendor/bin/phpunit` | `composer install` |
| `Integration`, `Security`, `Rest`, `Upgrade` | `phpunit-integration.xml.dist` | **PHPUnit 9.6** — `tests/wp/vendor/bin/phpunit` | `bin/ci/install-wp-runner.sh` |
| the same, multisite | `phpunit-multisite.xml.dist` | **PHPUnit 9.6** | `bin/ci/install-wp-runner.sh` |

`bin/ci/run-wp-tests.sh` picks the binary from the config file. No caller — not
`verify.sh`, not `run-coverage.sh`, not a person typing a command — has to know
which suite sits on which major, and `pnpm test:php:unit` and
`pnpm test:php:integration` are unchanged.

### Why the WordPress suites cannot move

`WP_UnitTestCase_Base` calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`
from its constructor, and PHPUnit removed `PHPUnit\Util\Test` in 10. Measured,
not assumed: `wp-phpunit/wp-phpunit` 7.1.0 on PHPUnit 12.5.33 errors on **every**
integration test before its first assertion. `yoast/phpunit-polyfills` 4.x
declaring support for PHPUnit 11 and 12 is a red herring — it covers the
assertion API, not the annotation parser core's base class reaches for.

The check for when this can be deleted is a grep, recorded in
`tests/wp/README.md`:

```bash
grep -rn "parseTestMethodAnnotations" tests/wp/vendor/wp-phpunit/
```

### Why not simply follow LAAO

The LAAO theme runs PHPUnit 13 with Brain\Monkey only, avoiding the WordPress
test suite's version ceiling by not having a WordPress test suite. This plugin
cannot, and the reason is specific: **the assertions this plugin needs are not
expressible under Brain\Monkey.**

A `map_meta_cap` test written with Brain\Monkey mocks `current_user_can()` — and then proves that the mock returns what it was told to return. The actual question is whether core's capability pipeline, with our filter attached at priority 10 taking four arguments, denies advertiser B on advertiser A's campaign. Answering it needs a real `WP_User`, a real `$wp_filter`, and real `map_meta_cap()`.

The same holds for `dbDelta` idempotence (which depends on MySQL's own type normalization), REST authorization (needs a real `WP_REST_Server` and real nonce verification), uploads (touch GD and the filesystem), and "roles survived the upgrade" (is by definition about real `wp_options` state).

So the pin was moved rather than removed. It now sits on the runner that is
actually constrained, in `tests/wp/composer.json`, instead of holding back the
suite that has no reason to be held back. The container runs WordPress 7.1 and
PHP 8.4 from a digest-pinned Docker Official Image, and
`wp-phpunit/wp-phpunit:7.1.0` supplies the matching Core test library. All of
this is **test-only**; no shipped code changes.

### What the unit suite gave up to move

`yoast/phpunit-polyfills` is gone from the unit suite: its `set_up()` /
`tear_down()` naming existed to bridge PHPUnit majors, and on 13 alone there is
nothing to bridge. Unit tests extend `PHPUnit\Framework\TestCase` and use
`setUp()` / `tearDown()`. Doc-comment metadata is gone too — `@dataProvider` is
now `#[DataProvider]`, because PHPUnit 13 reads attributes and no longer reads
the docblock. The WordPress suites still use the polyfills, via
`WP_UnitTestCase`, out of `tests/wp/vendor`.

## Failure policy

`failOnWarning`, `failOnRisky`, `failOnSkipped`, and `failOnIncomplete` are all `true`.

**A skipped security test is a security test that is not running.** Skips accumulate silently — one environment-conditional skip becomes six, and the suite reports green while covering less every month. If a test genuinely cannot run in an environment, that belongs in the configuration as an excluded suite, where it is visible, not as a runtime skip.

The Playwright config uses the same principle via a reporter that fails CI on any skipped spec.

## Security tests assert the wiring

Ported from Aggressive Apparel, and the most valuable convention here.

A security test that only calls the method under test proves the method is correct. It does not prove the method *runs*. A refactor that drops `add_action( 'admin_init', … )` leaves every behavioural test green and the guard entirely absent.

So security tests assert both:

```php
$this->assertNotFalse( has_action( 'admin_init', array( Admin_Guard::class, 'guard' ) ) );
$this->assertSame( 10, has_filter( 'map_meta_cap', array( Ownership::class, 'map' ) ) );
```

…and then the behaviour. Both halves, every time.

## What each suite covers

**unit** — the transition table; illegal transitions; the validator; URL and date validation; placement and package resolution; the `Portal\Request` grammar; audit value objects; the container; the autoloader; CPT argument maps and slug lengths.

**integration** — activation and reactivation idempotence; both custom tables and every declared column/index; the upgrader replaying 0→current in order, and stopping at the last successful step on failure; roles carrying exactly the declared capabilities; persistence round trips; the rewrite rule's presence; **that no `wp/v2` route exists for any of the five post types**; native publisher cache-bust; placement catalogue create/edit with common and custom sizes, unique slugs, deactivate, house MIME/URL refusals, audit, and nonce enforcement; ending-soon receipt suppression and open-ended exclusion; private-file retention after ninety days with record retention.

`DeliveryScaleTest` is the database-backed performance regression: 1,000 real
live campaigns/creatives, complete eligibility, and cold/warm/token query
budgets. `TrackingDurabilityTest` proves closed-day rollup repair is exact and
idempotent and retention deletes are bounded. Measured baselines and the
reporting command live in [delivery-performance.md](delivery-performance.md).

The measurement suites (P10–P12) are where most of the integration weight now
sits, and they are grouped by what would break rather than by class.
`ConversionLedgerTest` owns the deduplication guarantee: it asserts the unique
key is actually `UNIQUE` rather than merely present, that a duplicate is refused
when the competing row was written by a process this one knows nothing about,
and that the write path issues one statement — a read before the insert would
pass every sequential test and lose the race. `ConversionAttributionTest` and
`ConversionRulesTest` are pure, and carry the window boundaries and the closed
set of refusal reasons. `ConversionRecorderTest` is the one that goes through
the production path end to end, including the projection and its exact
reconcile, and the assertions that a refusal costs no ledger read.
`ConversionRoutesTest` and `ServerConversionRoutesTest` prove the two ingestion
routes attribute identically while differing in exactly one respect — the
browser route has no value parameter to hand — and that their refusals are
indistinguishable from one another. `ConversionMetricsTest` pins the refusal
counters to a single buffered write on `shutdown`, because the cheapest refusal
is the one an attacker repeats.

`viewability.spec.ts` is the browser half of P11: a real advertisement entering
a real viewport, asserting the beacon's *response* rather than the request,
since a 204 is returned only once the row is written.

**security** — the closed REST inventory and default-deny contract; the explicit public `admin-post` allowlist; every IDOR surface in [threat-model.md](threat-model.md) with a Phase-1 endpoint; advertiser A denied on B's campaign **and a co-member of A's org allowed** (the case that proves ownership is org-scoped rather than accidentally author-scoped); deleted objects mapping to `do_not_allow`; nonce-missing and nonce-forged raising `WPDieException`; revoked portal access closing every owner organization mutation without side effects; signup non-enumeration, anonymous rate limiting, unprivileged-before-ownership ordering and mail-failure rollback; private canonical organization matching, no duplicate tenant, pending users without portal capability, owner-only pending emails, cross-tenant approval denial, email-bound and single-use invitation consumption; owner-scoped member removal with the last-owner guard, cross-tenant removal denial, and advertiser-role cleanup that preserves unrelated WordPress roles; ownership transfer only to an existing member, with cross-tenant denial and former-owner demotion to member; organization rename with destination `active_key` reservation, cross-tenant denial, and exact identity collision refusal; staff organization suspend/reactivate requiring `aggr_manage_orgs`, with advertiser denial and audited read-back; portal-owned email change with HMAC token, single-use confirm, taken-address suppression, and details-save still unable to set email/role; portal-only setup and recovery URLs, core reset-key validation, minimum password policy and single-use consumption; the advertiser holding none of `upload_files` / `edit_posts` / `unfiltered_html`; no `wp_ajax_laao_ads*` action registered. The completed audit is [authorization-failure-review.md](authorization-failure-review.md).

**rest** — every route's permission callback, schema validation, sanitization, and the 404-not-403 rule.

**upgrade** — migration ordering, idempotence, the concurrency lock, and stale-lock recovery.

**multisite** — two blogs, colliding post ids, a fill token from site A rejected
on site B, fill-cache isolation, org membership invisible across sites,
`wp_initialize_site` installing only when network-active, and plugin tables
dropped on `wp_uninitialize_site`. Loaded only under `phpunit-multisite.xml.dist`.

**JS** — the pure logic layer only. `helpers.ts` / future `logic.ts` import
nothing from `@wordpress/interactivity`, so Jest exercises them without mocking
the runtime. Hand-authored sources live under `src/interactivity/` and
`src/blocks/`; compiled output under `dist/`. Do not add runtime-mocked
Interactivity unit tests.
No snapshot tests: a snapshot asserts that output has not changed, which is not
the same as asserting it is correct, and the usual response to a failing
snapshot is to update it.

**E2E** — real browser flows against real WordPress. The portal smoke test under Twenty Twenty-Five is the single test that proves the zero-theme-dependency claim; everything else in [architecture.md](architecture.md) about theme independence is a convention, and this is the enforcement.

The campaign browser spec signs in through core, creates a fresh draft, selects
a real seeded package, uploads a generated exact-size PNG through the native
multipart form, schedules, reviews, submits, and reloads the locked result. It
also proves the skip link, step-heading focus after each wizard navigation,
authenticated private preview, dialog keyboard (open, Tab trap, Escape,
focus restore) for preview/remove/replace, non-clickable review
destination, axe conformance on each wizard step plus open overlays, and that
the active Twenty Twenty-Five block theme does not wrap the standalone portal.
The inventory browser spec signs in as an administrator, opens the capability-
gated wp-admin Inventory screen, creates a custom-size placement, and scans
pre- and post-write states with axe.

Global setup seeds and resets deterministic data; teardown deletes the campaign,
its private bytes, and the inventory fixtures. It also hard-flushes
Apache rewrite rules so a rebuilt container cannot turn a stale `.htaccess` file
into a misleading portal failure. Chromium runs with one worker because the
WordPress site is shared mutable state, and retries are zero so a flaky gate
cannot hide.

## Accessibility testing

`@axe-core/playwright`, scoped to the `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, and `wcag22aa` tags. Best-practice rules are excluded deliberately — they are advice, and mixing advice with conformance means the conformance signal gets muted the first time someone needs to ship.

Automated scanning catches roughly a third of real accessibility problems. Keyboard-only traversal, focus order, and screen-reader announcement quality are asserted explicitly per-flow, and manual testing supplements both. See [accessibility.md](accessibility.md).

The main mutable flow remains one-worker Chromium. Separate read-focused
projects exercise 320 CSS-pixel reflow and the custom dialog's focus/inert
contract in WebKit, without replaying account-creation fixtures that are
deliberately single-use.

## Running

```bash
pnpm test:php:unit            # fast, no database
pnpm env:start                # WordPress 7.1 + MySQL 8.4 at localhost:9960
pnpm test:php:integration     # real WordPress + isolated test database
pnpm test:php:multisite       # real multisite bootstrap
pnpm test:php:security
pnpm test:js
pnpm test:e2e:browsers        # install browsers; what pnpm qa runs
pnpm test:e2e:install         # the same, plus system libraries (needs sudo; what CI runs)
pnpm test:e2e                 # needs env:start; setup seeds its own data
pnpm test:php:native          # the WP suites on local MySQL; no Docker, no sudo
pnpm db:local stop            # stop that MySQL (also: start|status|destroy)
pnpm test:e2e:studio          # starts/discovers Studio and runs the same browser specs
pnpm qa:fast                  # Docker-free code quality, build and unit checks
pnpm qa:local                 # qa:fast + the Studio browser workflow
pnpm ci:verify                # everything, serially, as CI would
pnpm qa:fresh                 # recreate containers/database, then run all gates
```

The Studio plugin directory must resolve to this checkout; a symlink is the
normal arrangement. `qa:local` discovers that site automatically, or accepts
`AGGR_STUDIO_PATH=/path/to/site` when more than one site matches. The base URL
is whatever `studio site list` reports for that site; `AGGR_STUDIO_URL` overrides
it, and a site Studio gives no URL for is refused rather than guessed at.

The site must opt in — `.aggr-e2e-site` in its root, or
`AGGR_STUDIO_E2E_ALLOW=1` — because the setup mutates it. Two of those
mutations are permanent: `tests/e2e/seed-users.php` resets the `admin` and
`advertiser` passwords to the fixture values, and the seeds write fixture
campaigns, an organization and a placement. The reversible ones — theme,
permalink structure, the mail-capture mu-plugin — are captured before the run and
restored afterwards on success and on failure, and a failed restore turns a
passing run red rather than reporting a site it left half-changed.

`home` and `siteurl` are deliberately not restored. They are set from whatever
`studio site list` reports and left there, because the value a restore would put
back is not knowably right: Studio assigns the port and can reassign it. The site
this was built against stored `https://laartsonline.local`, which resolved to
127.0.0.1 with nothing listening on 443 — so the old behaviour made the site
reachable for the length of a test run and unreachable again afterwards, and
reported that as a clean restore.

Following Studio is safe rather than merely convenient: a custom hostname for a
Studio site is configured in Studio, so `studio site list` reports it and the
next run picks it up. There is no arrangement where the correct address is one
Studio does not know about, which is why nothing here is a literal.
`AGGR_STUDIO_URL` remains only as the narrow fallback for a site Studio reports
no URL for at all.

The PHP integration suite does not run against Studio's SQLite database because
its schema and `dbDelta` assertions are specifically MySQL behavior.

### The native runner

`pnpm test:php:native` runs the same integration and multisite suites on this
host — no Docker and no sudo. `bin/local/mysql.sh` initializes a private datadir
under `.cache/ci/mysql` and listens on port 13306, so a masked or running system
`mysql.service` is neither required nor disturbed; `bin/local/wp-core.sh` fetches
the WordPress release `compose.yml` pins and refuses to proceed if the two ever
drift apart.

`tests/wp-tests-config.php` is one file for both runners: every value defaults to
the Compose stack, and the native runner overrides `AGGR_TESTS_*`. Two copies of
a database configuration is how two runners start testing different things.

`bin/ci/run-wp-tests.sh` branches on `AGGR_TESTS_RUNNER` (default `docker`) but
shares the JUnit-report verification, because that is the half that catches a
suite dying mid-run.

**This is a feedback loop, not a CI substitute.** CI pins MySQL 8.4 and PHP 8.4;
the native runner uses whatever the host has, and prints both versions at the end
of every run so a local-vs-CI disagreement costs a glance rather than an
afternoon. The schema and `dbDelta` assertions are the ones that can legitimately
differ. `pnpm qa` against the Compose stack remains the contract for declaring a
change finished.

One requirement is not obvious: the checkout must sit inside a directory that
looks like a plugins directory. The multisite suite calls
`activate_plugin( plugin_basename( AGGR_PLUGIN_FILE ) )`, and `plugin_basename()`
resolves a path only by stripping `WP_PLUGIN_DIR` — the bootstrap loads this
plugin straight from the checkout rather than through
`wp_register_plugin_realpath()`, so a symlink into `wp-content/plugins` does not
help and five tests die with "Plugin file does not exist".
