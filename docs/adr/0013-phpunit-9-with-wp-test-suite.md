# ADR-0013 — PHPUnit 9.6 with the WordPress core test suite, two bootstraps

**Status:** Accepted — 2026-08-08

## Context

The LAAO theme runs PHPUnit 13 with Brain\Monkey only, deliberately avoiding the WordPress test suite's version ceiling. Copying that choice here would be the consistent thing to do.

It would also make the most important assertions in this plugin inexpressible.

A `map_meta_cap` test written with Brain\Monkey mocks `current_user_can()` and then proves the mock returns what it was told to return. The real question is whether core's capability pipeline, with our filter attached at priority 10 taking four arguments, denies advertiser B on advertiser A's campaign. Answering it needs a real `WP_User`, a real `$wp_filter`, and real `map_meta_cap()`.

The same holds for `dbDelta` idempotence (depends on MySQL's own type normalization), REST authorization (needs a real `WP_REST_Server` and real nonce verification), uploads (touch GD and the filesystem), and "roles survived the upgrade" (is by definition about real `wp_options` state).

The WordPress core test suite requires PHPUnit 9.x.

## Decision

PHPUnit is pinned to **9.6** with `yoast/phpunit-polyfills:^4.0`, and there are **two configuration files**:

| Config | Bootstrap | Suites | Needs |
|---|---|---|---|
| `phpunit.xml.dist` | `tests/php/bootstrap-unit.php` | `unit` | Nothing — no WordPress, no database |
| `phpunit-integration.xml.dist` | `tests/php/bootstrap-wp.php` | `integration`, `security`, `rest`, `upgrade` | WP test suite + MySQL |

Two configs because **PHPUnit allows exactly one bootstrap per configuration file**. That is the reason for the split, not preference: the unit suite must not load WordPress, and the integration suite must.

`failOnWarning`, `failOnRisky`, `failOnSkipped`, and `failOnIncomplete` are all `true`. **A skipped security test is a security test that is not running.** Skips accumulate silently — one environment-conditional skip becomes six, and the suite reports green while covering less every month. A test that genuinely cannot run in an environment belongs in the config as an excluded suite, where it is visible.

Aggressive Apparel already runs 9.6 with these polyfills against WordPress 7.0.2 in wp-env, so this is a proven combination rather than a hopeful one.

## Consequences

- The assertions that matter — org-scoped denial, co-member allowance, `dbDelta` idempotence, real nonce failures, real upload handling — are testable against real WordPress.
- Newer PHPUnit features are unavailable. The polyfills cover the assertion API gap.
- Two configs mean two commands and two CI lanes. `ci:php` is fast and needs nothing; `ci:php:wp` needs a MySQL service. Making the fast lane genuinely fast is worth the second file.
- This is a **test-only** constraint. No shipped code is affected, and Brain\Monkey `^2.7` runs on 9.6, so unit tests lose nothing.
- The plugin's PHPUnit version differs from the theme's. Recorded in [known-issues.md](../known-issues.md) so the inconsistency reads as a decision.
- Revisit whenever the core test suite supports a newer PHPUnit.

## Alternatives rejected

**PHPUnit 13 with Brain\Monkey only,** matching the theme. Fast, modern, and unable to test capability mapping, `dbDelta`, REST authorization, or uploads — which is most of the security surface. The tests would exist and would be proving that mocks return their configured values.

**Both: 13 for unit, 9.6 for integration.** Two PHPUnit installations in one `composer.json` is not a thing Composer supports without contortions, and the version skew would surface as confusing assertion-API differences between suites.

**Integration testing through wp-env and WP-CLI scripts instead of the core suite.** Loses per-test database rollback, factories, and the assertion infrastructure, all of which would then be reimplemented worse.
