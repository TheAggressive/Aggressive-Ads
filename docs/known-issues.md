# Known issues

Things that are true, annoying, and worth writing down so nobody rediscovers them. Each says what it is, what it costs, and whether anything can be done.

## Script modules have no translation mechanism

**What.** `wp_set_script_translations()` does not work for the Script Modules API, which the Interactivity API is built on. There is no supported way to ship translated strings to an Interactivity store.

**Cost.** Every user-facing string in a store has to be translated in PHP and hydrated through `wp_interactivity_state()`, and TypeScript may never contain a literal a user will read.

**Status.** Core gap, no workaround needed beyond the hydration convention. If core closes it, the hydration is removable — see [interactivity-stores.md](interactivity-stores.md).

## AdSanity `_size` is unvalidated free text

**What.** AdSanity accepts any string for `_size` and stores it. There is no validation at save (`class-adsanity-ads-cpt.php:1648-1782`), and an unrecognized value shows "- invalid size -" in the admin list but renders anyway with an empty CSS class.

**Cost.** AdSanity will not catch our typos. A published ad with a bad size renders unstyled and looks like a CSS bug.

**Mitigation.** We validate `_laao_ads_size` against the current size map — read through the `adsanity_ad_sizes` filter, not the raw option — before publishing, and read the value back afterwards.

## AdSanity's meta hooks are bypassed by core meta functions

**What.** `Adsanity\Meta_Data` fires `adsanity_pre_update_meta`, `adsanity_after_update_meta_{$key}` and friends. Core `update_post_meta()` bypasses all of them.

**Cost.** We use core functions deliberately, so any add-on relying on those hooks will not see our writes. Nothing installed here does today.

**Watch.** If an AdSanity add-on ever starts depending on those hooks for derived data, the publisher would have to route through the wrapper — which means depending on an undocumented internal class. Revisit only if it actually happens.

## nginx ignores `.htaccess`

**What.** Private creative storage writes `.htaccess`, `web.config`, and `index.php` deny files. **nginx reads none of them.** If production runs nginx, that layer contributes nothing.

**Cost.** Defence in depth is one layer thinner than it looks.

**Mitigation.** Path unguessability (UUID filename plus a 32-char token) is the layer that actually holds, because it does not depend on server configuration. Reads go through an authorized streaming endpoint that never redirects to the raw file. A Site Health check warns when the directory cannot be proven blocked, and the nginx `location` snippet belongs in the deployment notes.

## CI cannot test the real AdSanity integration

**What.** AdSanity is licensed and cannot be fetched in CI. CI runs against a contract stub.

**Cost.** The integration is only exercised for real locally and nightly, not on pull requests.

**Mitigation.** `tests/php/Contract/AdsanityContractTest.php` asserts the stub still matches the real plugin field for field, and skips when AdSanity is absent. This does not remove the gap; it makes the gap detectable. See [ADR-0015](adr/0015-adsanity-contract-stub-for-ci.md).

## AdSanity's REST filter checks only the end date

**What.** `lib/rest-api.php:36-64` injects `_end_date >= now` on REST queries for the `ads` post type, but not `_start_date <= now`. A future-dated ad appears in REST while correctly hidden on the front end.

**Cost.** None for us — we do not consume AdSanity's REST output.

**Watch.** Do not use `wp/v2/ads` as a source of truth for "is this campaign live". Ask our own state machine.

## PHPUnit is pinned to 9.6

**What.** The WordPress core test suite requires PHPUnit 9.x. The LAAO theme runs PHPUnit 13 and has no integration suite for exactly this reason; this plugin needs integration tests more than it needs a modern runner.

**Cost.** Newer PHPUnit features are unavailable. `yoast/phpunit-polyfills` covers the assertion API gap.

**Status.** Test-only; no shipped code is affected. Revisit whenever the core test suite supports a newer PHPUnit. See [ADR-0013](adr/0013-phpunit-9-with-wp-test-suite.md).

## Term 19's name contains a multiplication sign

**What.** The live `ad-group` term 19 is named `728×90 Break` with U+00D7, not the letter `x`. Every other term uses `x`.

**Cost.** Any name-based matching works for four of five placements and fails on the fifth in a way that reads as a typo.

**Mitigation.** Mapping keys on term ID and never on term name. See [ADR-0007](adr/0007-placement-mapping-is-explicit-data.md).

## Rewrite rules can go stale

**What.** The portal route depends on rewrite rules being flushed. A deploy that does not bump `laao_ads_rewrite_version` after a route change leaves the old rules in place.

**Cost.** `/advertiser/` 404s, and it looks like a broken deploy rather than a stale cache.

**Mitigation.** A Site Health check asserts the rules are present in `get_option( 'rewrite_rules' )`, and a Tools screen offers a manual re-flush. Bumping the constant is the documented procedure for shipping a route change.
