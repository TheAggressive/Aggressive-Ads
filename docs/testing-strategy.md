# Testing strategy

## Prove the test works

Before a meaningful test counts as done:

1. Write it and watch it pass.
2. **Break the implementation deliberately.**
3. Watch it fail — and read the failure message, which is what someone will see at 2am.
4. Restore.
5. Watch it pass.

A test that passes for a reason unrelated to the behaviour it names is worse than no test, because it produces confidence. The most common variety is a test that asserts on a mock it configured itself.

## Suites

| Suite | Config | Bootstrap | Needs |
|---|---|---|---|
| `unit` | `phpunit.xml.dist` | `tests/php/bootstrap-unit.php` | Nothing. No WordPress, no database |
| `integration` | `phpunit-integration.xml.dist` | `tests/php/bootstrap-wp.php` | WP test suite + MySQL |
| `security` | same | same | same |
| `rest` | same | same | same |
| `upgrade` | same | same | same |
| JS | `jest.config.js` | — | Node |
| E2E | `playwright.config.ts` | — | wp-env |

Two PHPUnit configs because **PHPUnit allows exactly one bootstrap per configuration file**. That is the reason for the split, not preference — the unit suite must not load WordPress, and the integration suite must.

## PHPUnit 9.6, not 13

The LAAO theme runs PHPUnit 13 with Brain\Monkey only, deliberately avoiding the WordPress test suite's version ceiling. This plugin goes the other way, and the reason is specific: **the assertions this plugin needs are not expressible under Brain\Monkey.**

A `map_meta_cap` test written with Brain\Monkey mocks `current_user_can()` — and then proves that the mock returns what it was told to return. The actual question is whether core's capability pipeline, with our filter attached at priority 10 taking four arguments, denies advertiser B on advertiser A's campaign. Answering it needs a real `WP_User`, a real `$wp_filter`, and real `map_meta_cap()`.

The same holds for `dbDelta` idempotence (which depends on MySQL's own type normalization), REST authorization (needs a real `WP_REST_Server` and real nonce verification), uploads (touch GD and the filesystem), and "roles survived the upgrade" (is by definition about real `wp_options` state).

Aggressive Apparel already runs PHPUnit 9.6 with `yoast/phpunit-polyfills:^4.0` against WordPress 7.0.2 in wp-env, so this is a proven combination rather than a hopeful one. It is a **test-only** constraint — no shipped code changes — and Brain\Monkey `^2.7` runs on 9.6, so unit tests are unaffected. See [ADR-0013](adr/0013-phpunit-9-with-wp-test-suite.md).

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

**integration** — activation and reactivation idempotence; the audit table and every declared index; the upgrader replaying 0→current in order, and stopping at the last successful step on failure; roles carrying exactly the declared capabilities; persistence round trips; the rewrite rule's presence; **that no `wp/v2` route exists for any of the five post types**.

**security** — every IDOR surface in [threat-model.md](threat-model.md) with a Phase-1 endpoint; advertiser A denied on B's campaign **and a co-member of A's org allowed** (the case that proves ownership is org-scoped rather than accidentally author-scoped); deleted objects mapping to `do_not_allow`; nonce-missing and nonce-forged raising `WPDieException`; the advertiser holding none of `upload_files` / `edit_posts` / `unfiltered_html`; no `wp_ajax_laao_ads*` action registered.

**rest** — every route's permission callback, schema validation, sanitization, and the 404-not-403 rule.

**upgrade** — migration ordering, idempotence, the concurrency lock, and stale-lock recovery.

**JS** — the pure logic layer only. `src/interactivity/logic.ts` imports nothing from `@wordpress/interactivity` precisely so Jest can test it without mocking the runtime. No snapshot tests: a snapshot asserts that output has not changed, which is not the same as asserting it is correct, and the usual response to a failing snapshot is to update it.

**E2E** — real browser flows against real WordPress. The portal smoke test under Twenty Twenty-Five is the single test that proves the zero-theme-dependency claim; everything else in [architecture.md](architecture.md) about theme independence is a convention, and this is the enforcement.

## Accessibility testing

`@axe-core/playwright`, scoped to the `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` tags. Best-practice rules are excluded deliberately — they are advice, and mixing advice with conformance means the conformance signal gets muted the first time someone needs to ship.

Automated scanning catches roughly a third of real accessibility problems. Keyboard-only traversal, focus order, and screen-reader announcement quality are asserted explicitly per-flow, and manual testing supplements both. See [accessibility.md](accessibility.md).

## AdSanity in tests

CI cannot install AdSanity — it is licensed and cannot be fetched. So:

- CI runs against `tests/fixtures/mu-plugins/adsanity-contract-stub.php`, which registers the `ads` CPT with AdSanity's exact arguments, the hierarchical `ad-group` taxonomy, `ADSANITY_EOL`, and the `adsanity_ad_sizes` filter. It self-disables when the real plugin is present.
- `tests/php/Contract/AdsanityContractTest.php` skips unless real AdSanity is active, and asserts the stub still matches it field for field. It runs locally and nightly, not on PRs.

This is the honest shape of the constraint. CI does not test the real integration; a separate gate proves the thing CI tests has not drifted from reality. Pretending otherwise would be worse than admitting it. See [ADR-0015](adr/0015-adsanity-contract-stub-for-ci.md).

## Running

```bash
pnpm test:php:unit            # fast, no database
pnpm test:php:integration     # needs wp-env + WP test suite
pnpm test:php:security
pnpm test:js
pnpm test:e2e
pnpm test:contract            # local only, needs real AdSanity
pnpm ci:verify                # everything, serially, as CI would
```
