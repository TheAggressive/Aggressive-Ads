# Plugin translations

Text domain: `aggressive-ads` · Domain Path: `/languages`

Adapted from the Aggressive Apparel theme's pipeline. **Two things differ, and
both are the kind that fail silently** — read "The `.mo` filename" and "The
plugin must register this directory" before changing anything here.

## Who does what

| Role | Action |
|---|---|
| Developers | Wrap strings in `__()`; run `pnpm i18n:pot` when strings change |
| MT + you | CI fills `.po` drafts; you review the PR (never hand-edit `.mo`) |
| Release | `bin/release/package.sh` compiles catalogs into the archive |
| Runtime | WordPress loads the compiled catalog for the site language |

`pnpm build` is asset-only and does not run i18n.

## Commands

```bash
pnpm i18n                  # pot → sync → compile → status
pnpm i18n:pot              # regenerate aggressive-ads.pot from source
pnpm i18n:locale -- pt_BR  # scaffold a new locale .po
pnpm i18n:sync             # merge the pot into every .po
pnpm i18n:compile          # build .mo (+ Jed JSON for classic scripts)
pnpm i18n:status           # coverage table
pnpm i18n:check            # CI gate: pot drift, catalog validity, placeholders
pnpm i18n:translate        # machine-translate empty/fuzzy entries
```

`msgfmt` (gettext) is a hard requirement for validation, deliberately: the old
fallback reported success on an unterminated `msgid` and a placeholder mismatch
alike, so the gate passed unconditionally. `AGGR_I18N_PO_VALIDATOR=skip`
announces itself loudly; a missing tool never chooses it for you.

## The `.mo` filename — this is where the theme shipped broken

`_load_textdomain_just_in_time()` picks the filename from where the registered
path points:

```php
if ( str_starts_with( $path, $template_directory ) || … ) {
    $mofile = "{$path}{$locale}.mo";            // de_DE.mo          ← themes
} else {
    $mofile = "{$path}{$domain}-{$locale}.mo";  // aggressive-ads-de_DE.mo ← us
}
```

A plugin takes the **second** branch, so the prefix stays. `wp i18n make-mo`
already produces that name, which is why `compile.sh` has **no rename step** —
and why it says so in a comment. The theme's `compile.sh` renames to the first
form, and porting that here would disable every locale while every other signal
stayed green.

The JSON catalogs keep the prefix in both cases; `_load_script_textdomain()`
has no equivalent branch.

## The plugin must register this directory

Just-in-time loading does **not** search a plugin's own folder.
`WP_Textdomain_Registry` looks in `WP_LANG_DIR/plugins`, `WP_LANG_DIR/themes`,
and a custom path set only by `load_plugin_textdomain()`. A plugin that ships
`languages/` and calls neither gets English, silently, forever.

`Plugin::load_translations()` makes that call on `init` — on `init` because
loading a text domain earlier is a `_doing_it_wrong` notice since WordPress 6.7.

**Verify translations from `__()` output, never from a return value.**
`load_plugin_textdomain()` returns true whether or not it found anything.
`tests/php/Integration/TranslationLoadingTest.php` asserts on `__()` for that
reason, and both failure modes above are covered by it.

## Machine translation

DeepL when `DEEPL_AUTH_KEY` is set, MyMemory otherwise. MyMemory is a
translation-memory aggregator that returns whole-segment matches from unrelated
corpora, so short UI labels come back with phrasing the source never had — and
this plugin is mostly short UI labels. Skim either way.

Only **empty** or **fuzzy** entries are filled. Each gets an `aggr-mt` flag and
a comment naming the provider.

```bash
cp .env.example .env.local     # gitignored; put DEEPL_AUTH_KEY there
pnpm i18n:translate -- --locale=fr_FR --limit=50
I18N_MT_PROVIDER=deepl pnpm i18n:translate
```

`.env.local` is **parsed**, not sourced — a file whose job is holding an API key
should not be able to run shell. The shell and CI environment win over it.

CI: [`i18n-translate.yml`](../.github/workflows/i18n-translate.yml) runs on a POT
push to `master` or on demand, opens a PR, and no-ops when no catalog exists.

## When translations reach users

Translations are not their own release. The draft PR merges as `chore(i18n)`, so
it cuts no version; the shipped `.mo` is compiled at release time from whatever
`.po` is on `master`. Merged translations ride the next `feat`/`fix` release.

A feature's new strings therefore show in English for translated locales until
that next release. To ship a release already translated, fill and review on the
feature branch before merging.

Do **not** move MT into `pnpm build` or the release job. That ships unreviewed
machine output straight to publishers.

## Files

| File | Purpose |
|---|---|
| `aggressive-ads.pot` | Source catalog (committed) |
| `aggressive-ads-<locale>.po` | Drafts and reviewed strings (committed) |
| `aggressive-ads-<locale>.mo` | Compiled catalog (**gitignored**, shipped) |
| `aggressive-ads-<locale>-*.json` | Classic-script translations (**gitignored**) |

`bin/release/verify-package.sh` refuses an archive containing a `.po` without
its `.mo`, which is what catches a release that skipped compilation — otherwise
invisible, because the site just renders English.

Interactivity API modules cannot use `wp_set_script_translations()`; their
strings are translated in PHP and hydrated through `wp_interactivity_state()`.
See [../docs/i18n.md](../docs/i18n.md).
