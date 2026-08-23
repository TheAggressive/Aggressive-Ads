# The WordPress test runner

This directory exists for one reason: **`wp-phpunit` cannot run on PHPUnit 10 or
later.** `WP_UnitTestCase_Base` calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`
from its constructor, and PHPUnit removed `PHPUnit\Util\Test` in 10. Tried
against `wp-phpunit/wp-phpunit` 7.1.0 on PHPUnit 12.5.33, every integration test
errors before its first assertion.

The plugin's own `composer.json` runs **PHPUnit 13**, matching the LAAO theme.
Two majors of one package cannot live in one `composer.json`, so the old one
lives here, in its own project with its own `vendor/`, and the constraint stays
attached to the thing that is actually constrained.

## What runs where

| Suite | Config | Runner |
|---|---|---|
| `tests/php/Unit` | `phpunit.xml.dist` | `vendor/bin/phpunit` — PHPUnit 13 |
| `tests/php/{Integration,Security,Rest,Upgrade}` | `phpunit-integration.xml.dist` | `tests/wp/vendor/bin/phpunit` — PHPUnit 9.6 |
| the same, multisite | `phpunit-multisite.xml.dist` | `tests/wp/vendor/bin/phpunit` — PHPUnit 9.6 |

`bin/ci/run-wp-tests.sh` picks the binary from the config file, so nothing else
has to know. `bin/ci/install-wp-runner.sh` installs this project and is called by
every path that needs it.

## Why the autoload block points at `../php/`

So this project's autoloader is the **only** one the WordPress suites load. If
both autoloaders were registered, a `PHPUnit\…` class not already declared could
resolve out of the plugin's PHPUnit 13 while a 9.6 run was in progress. One
autoloader per run removes the question.

## When this can be deleted

When `wp-phpunit` stops calling `parseTestMethodAnnotations()`:

```bash
grep -rn "parseTestMethodAnnotations" tests/wp/vendor/wp-phpunit/
```

That is the whole test. When it returns nothing, this directory can be
deleted, `phpunit/phpunit` in the plugin's `composer.json` covers every suite,
and `bin/ci/run-wp-tests.sh` loses its `case` block. The rest of the reasoning
lives in `docs/testing-strategy.md`.
