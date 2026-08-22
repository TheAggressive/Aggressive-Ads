# Known issues

Things that are true, annoying, and worth writing down so nobody rediscovers them. Each says what it is, what it costs, and whether anything can be done.

## Script modules have no translation mechanism

**What.** `wp_set_script_translations()` does not work for the Script Modules API, which the Interactivity API is built on. There is no supported way to ship translated strings to an Interactivity store.

**Cost.** Every user-facing string in a store has to be translated in PHP and hydrated through `wp_interactivity_state()`, and TypeScript may never contain a literal a user will read.

**Status.** Core gap, no workaround needed beyond the hydration convention. If core closes it, the hydration is removable — see [interactivity-stores.md](interactivity-stores.md).

## nginx ignores `.htaccess`

**What.** Private creative storage writes `.htaccess`, `web.config`, and `index.php` deny files. **nginx reads none of them.** If production runs nginx, that layer contributes nothing.

**Cost.** Defence in depth is one layer thinner than it looks.

**Mitigation.** Path unguessability (UUID filename plus a 32-char token) does not depend on server configuration, and reads go through an authorized streaming endpoint that never redirects to the raw file. Directory listing is refused by the `index.php` the directory carries, so filenames cannot be enumerated either.

The exposed set is also kept as small as it can be: the private original is deleted the moment a creative is promoted to its Media Library attachment, so the directory holds only creative still awaiting review rather than every creative the site has ever run. That is what shrank the window from "the campaign's whole life plus ninety days" to "until a reviewer decides". `Campaign_Copier` resolves bytes from the private file *or* the attachment, so renew and duplicate are unaffected.

Production nginx must also deny the directory directly:

```nginx
location ~ ^/wp-content/uploads(?:/sites/[0-9]+)?/ads-uploads(?:/|$) {
    return 404;
}
```

Adapt the prefix when `upload_url_path` or the uploads directory is customized. The Site Health test creates a random harmless probe, requests it through the public uploads URL, and removes it. It reports the rule for the server it detects from `SERVER_SOFTWARE`, with the alternatives underneath — an Apache site that reaches this state has a `.htaccess` this plugin already wrote and a server ignoring it, so the fix there is `AllowOverride`, not a new rule.

**A served probe is reported as a recommendation, not a critical failure. That is a deliberate reversal.** It was critical, and there was a dedicated daily probe with an admin notice to interrupt somebody about it; both are gone.

WordPress serves the media of unpublished posts from the same uploads directory and ships no deny rule for it. Calling this a broken install held the site to a standard the platform itself does not meet, and put a red banner on every admin page of any nginx, Caddy, or `AllowOverride None` site — usually in front of somebody with no server access. A warning that cannot be acted on is a warning people learn to dismiss, and that cost is paid by the next one that matters.

What is actually reachable is creative still awaiting review: approved originals are deleted the moment they are promoted to an attachment. Names are UUIDs no code path emits, the directory refuses a listing, and reads go through an authorized streaming endpoint. Reaching a file means guessing 122 bits. The deny rule is worth adding and is defence in depth; it is not the control everything rests on.

If unapproved creative is commercially sensitive — embargoed campaigns, competitive artwork — the portable fix that needs no server access is encrypting the bytes at rest and decrypting only in the streaming endpoint, keyed from `wp_salt()` so the key lives in `wp-config.php` rather than the database. That has not been built.

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
