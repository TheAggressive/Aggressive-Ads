# Known issues

Things that are true, annoying, and worth writing down so nobody rediscovers them. Each says what it is, what it costs, and whether anything can be done.

## Script modules have no translation mechanism

**What.** `wp_set_script_translations()` does not work for the Script Modules API, which the Interactivity API is built on. There is no supported way to ship translated strings to an Interactivity store.

**Cost.** Every user-facing string in a store has to be translated in PHP and hydrated through `wp_interactivity_state()`, and TypeScript may never contain a literal a user will read.

**Status.** Core gap, no workaround needed beyond the hydration convention. If core closes it, the hydration is removable — see [interactivity-stores.md](interactivity-stores.md).

## nginx ignores `.htaccess`

**What.** Private creative storage writes `.htaccess`, `web.config`, and `index.php` deny files. **nginx reads none of them.** If production runs nginx, that layer contributes nothing.

**Cost.** Defence in depth is one layer thinner than it looks.

**Mitigation.** Path unguessability (UUID filename plus a 32-char token) does not depend on server configuration, and reads go through an authorized streaming endpoint that never redirects to the raw file. Production nginx must also deny the directory directly:

```nginx
location ~ ^/wp-content/uploads(?:/sites/[0-9]+)?/aggr-private(?:/|$) {
    return 404;
}
```

Adapt the prefix when `upload_url_path` or the uploads directory is customized. After deployment, open Tools → Site Health: **Unapproved advertising creative is protected** creates a random harmless probe, requests it through the public uploads URL, requires a 401/403/404/410 response, and removes it. A 2xx response is a critical failure and creative uploads must not be accepted until the server rule is corrected.

## `wp-env run --env-cwd` injects a stray `--` and breaks the command

**What.** On `@wordpress/env` 10.39.0, passing `--env-cwd` puts a literal `--` at the front of the container command. What the container actually receives is `-- <your command>`, and every failure that follows is reported by the *inner* program, in its own vocabulary, naming something you did not type:

```
$ wp-env run tests-cli --env-cwd=wp-content/plugins/aggressive-ads vendor/bin/phpunit …
Error: 'vendor/bin/phpunit' is not a registered wp command. Did you mean 'cap'?

$ wp-env run tests-wordpress --env-cwd=wp-content/plugins/aggressive-ads php -v
/usr/local/bin/docker-entrypoint.sh: line 99: exec: --: invalid option
```

Drop `--env-cwd` and the same commands work.

**Cost.** It cost this repository its entire WordPress lane, silently. `pnpm test:php:integration`, `pnpm test:php:multisite` and `pnpm dev:seed` all carried `--env-cwd`, so **not one integration, security, REST, upgrade or multisite test could execute** — the run failed inside wp-env before PHPUnit was ever reached, and the error talked about wp-cli commands rather than about the suite. `bin/ci/verify.sh` even fails helpfully when Docker is down, which makes the surviving failure look like an environment problem rather than a broken invocation.

**Mitigation.** No `--env-cwd` anywhere. Give the container an absolute or `/var/www/html`-relative path instead, and name the interpreter explicitly:

```jsonc
// PHP in the WordPress container — its entrypoint is a shell, so name `php`.
"test:php:integration": "wp-env run tests-wordpress php wp-content/plugins/aggressive-ads/vendor/bin/phpunit -c wp-content/plugins/aggressive-ads/phpunit-integration.xml.dist"

// WP-CLI in the cli container — name `wp`.
"cli": "wp-env run cli wp"
```

Note the container change too: `phpunit` runs in **`tests-wordpress`**, not `tests-cli`. Both would work once `--env-cwd` is gone, but `tests-wordpress` is a plain shell entrypoint, so the command you write is the command that runs.

**Also.** `wp-env run` spawns with `shell: true` and concatenates arguments without escaping (it warns about this itself, via `DEP0190`). A `;` or `|` in an argument is interpreted by **your** shell, not the container's — so `wp-env run cli bash -c 'a; b'` silently runs `b` on the host. That is worth knowing while debugging, because it makes a host result look like a container result.

**Status.** Upstream behaviour; nothing to fix here beyond not using the flag. Revisit if a later `@wordpress/env` fixes the parse — the flag is more readable than repeating the plugin path.

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

**Current mitigation.** `Rewrite_Flusher` hard-flushes on activation, on `wp_initialize_site`, and once when a rewrite version constant moves. Bumping the constant is the documented procedure for shipping a route change after the plugin is already active.

`Install\Rewrite_Health` now asserts the end state in Tools → Site Health: **The advertiser portal is reachable**. It reads the installed `rewrite_rules` option and reports any advertised path — `/advertiser/`, `/ads/c/` — that no rule would match, and offers an administrator a nonce-checked button to reinstall them.

It deliberately does **not** check `aggr_rewrite_version`. That option records only that a flush was *attempted*; a restored database or a rules row regenerated by another plugin leaves the version current and the rules gone, which is precisely the state worth catching. `RewriteHealthTest` asserts that case directly.

Plain permalinks are reported first and separately, with a link to Settings → Permalinks rather than the repair button — flushing without a permalink structure writes nothing and would otherwise report success to somebody whose portal still 404s.
