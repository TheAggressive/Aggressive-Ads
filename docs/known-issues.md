# Known issues

Things that are true, annoying, and worth writing down so nobody rediscovers them. Each says what it is, what it costs, and whether anything can be done.

## Script modules have no translation mechanism

**What.** `wp_set_script_translations()` does not work for the Script Modules API, which the Interactivity API is built on. There is no supported way to ship translated strings to an Interactivity store.

**Cost.** Every user-facing string in a store has to be translated in PHP and hydrated through `wp_interactivity_state()`, and TypeScript may never contain a literal a user will read.

**Status.** Core gap, no workaround needed beyond the hydration convention. If core closes it, the hydration is removable — see [interactivity-stores.md](interactivity-stores.md).

## nginx ignores `.htaccess`

**What.** Private creative storage writes `.htaccess`, `web.config`, and `index.php` deny files. **nginx reads none of them.** If production runs nginx, that layer contributes nothing.

**Cost.** Defence in depth is one layer thinner than it looks.

**Current mitigation.** Path unguessability (UUID filename plus a 32-char token) is the layer that actually holds, because it does not depend on server configuration. Reads go through an authorized streaming endpoint that never redirects to the raw file. The Site Health warning and production nginx deployment snippet remain Phase 11 work; until then an nginx deployment must verify the deny rule operationally.

## Every integration run logs two `WP_MEMORY_LIMIT` warnings

**What.** The first two lines of `pnpm test:php:integration` are always:

```
PHP Warning:  Constant WP_MEMORY_LIMIT already defined in /wordpress-phpunit/includes/bootstrap.php
PHP Warning:  Constant WP_MAX_MEMORY_LIMIT already defined in /wordpress-phpunit/includes/bootstrap.php
```

**Cause.** `@wordpress/env` writes both constants into the tests `wp-config.php`
unconditionally, and the WordPress core test bootstrap then defines them again.
Both sides use the same values, so nothing behaves differently.

**Cost.** Noise that looks like a defect. It was mistaken for one during Phase 1
and "fixed" twice before being measured: removing the keys from `.wp-env.json`
entirely, and running `wp-env start --update`, both leave the constants in place,
because they were never ours.

**Status.** Not fixable from this repository. Not a PHPUnit warning either, so
`failOnWarning` is unaffected and the suite reports honestly. Ignore the two
lines; do not add memory constants to `.wp-env.json` trying to silence them.

## PHPUnit is pinned to 9.6

**What.** The WordPress core test suite requires PHPUnit 9.x. The LAAO theme runs PHPUnit 13 and has no integration suite for exactly this reason; this plugin needs integration tests more than it needs a modern runner.

**Cost.** Newer PHPUnit features are unavailable. `yoast/phpunit-polyfills` covers the assertion API gap.

**Status.** Test-only; no shipped code is affected. Revisit whenever the core test suite supports a newer PHPUnit.

## Rewrite rules can go stale

**What.** The portal route depends on rewrite rules being flushed. Activation hard-flushes so `/advertiser/` does not wait for Save Permalinks. A file-only deploy that does not bump `aggr_rewrite_version` after a route change still leaves the old rules in place. Pretty permalinks must already be on; this plugin does not set `permalink_structure`.

**Cost.** `/advertiser/` 404s, and it looks like a broken deploy rather than a stale cache.

**Current mitigation.** `Rewrite_Flusher` hard-flushes on activation, on `wp_initialize_site`, and once when a rewrite version constant moves. Bumping the constant is the documented procedure for shipping a route change after the plugin is already active, and deployment verification must request the portal URL. The Site Health assertion and Tools re-flush control remain Phase 11 work.
