# Known issues

Things that are true, annoying, and worth writing down so nobody rediscovers them. Each says what it is, what it costs, and whether anything can be done.

## Script modules have no translation mechanism below WordPress 7.0

**What.** `wp_set_script_translations()` does not work for the Script Modules API, which the Interactivity API is built on. Below WordPress 7.0 there is no supported way to ship translated strings to an Interactivity store.

**Cost.** Every user-facing string in a store has to be translated in PHP and hydrated through `wp_interactivity_state()`, and TypeScript may never contain a literal a user will read.

**Status — core has closed this, and the plugin cannot use it yet.** WordPress 7.0 added `WP_Script_Modules::set_translations()` and `print_script_module_translations()` (verified against the 7.1 core in `.cache/ci/wordpress`; both carry `@since 7.0.0`). This plugin's floor is **6.7** — `AGGR_MIN_WP` in `aggressive-ads.php`, and `Requires at least` in the header — so on a supported install the API may simply not be there.

**What removing the hydration would take,** in order: raise `AGGR_MIN_WP` to `7.0` and the plugin header with it; call `set_translations()` for each registered module in `Assets\Assets`; produce the module JSON catalogs in `bin/i18n/compile.sh`; then delete the string members from the `wp_interactivity_state()` payloads and let TypeScript hold the literals.

Raising the floor is the whole cost, and it is not a translation decision — it decides which sites can install the plugin at all. Until somebody wants to make that call, the hydration convention stays and is not a workaround for a gap so much as the price of supporting 6.7. See [interactivity-stores.md](interactivity-stores.md).
