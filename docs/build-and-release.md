# Build and release

## CI parity

**Green locally means green in CI.** The `ci:*` scripts in `package.json` are the source of truth; each maps 1:1 onto a GitHub Actions job. A CI-only command chain is how "it passed on my machine" becomes a permanent state of affairs.

```
ci:doctor   → node bin/ci/doctor.mjs
ci:php      → lint:php && analyse:php && test:php:unit
ci:php:wp   → test:php:integration           (needs wp-env)
ci:e2e      → test:e2e
ci:package  → release:package && release:verify
ci:verify   → bash bin/ci/verify.sh          (every current lane, serially)
```

The frontend, i18n, and compiled-asset lanes land with the first source files
that need them. Until then they are future gates, not commands the repository
pretends to run.

Adding a lane means adding it to **both** the workflow and `bin/ci/verify.sh`. Adding it to only one is how the two drift.

The E2E job installs Playwright's pinned Chromium build, starts wp-env, and
runs the same `pnpm ci:e2e` command as local verification. Failed runs retain
the trace, screenshot, video, and WordPress debug log; skipped specs make the
lane fail rather than quietly reducing coverage.

`ci:doctor` runs first everywhere. It semver-checks Node and pnpm against `engines` with no dependencies of its own, so a toolchain mismatch fails in two seconds rather than as a confusing error twenty minutes in.

## Quality gates

| Gate | Standard |
|---|---|
| PHPStan | **Level 8, no baseline, no `ignoreErrors`** |
| PHPCS | WordPress + **the full `WordPress-VIP-Go` standard** + PHPCompatibilityWP |
| ESLint *(when JS lands)* | Flat config, `--max-warnings 0` |
| TypeScript *(when JS lands)* | `strict`, `noUncheckedIndexedAccess`, `checkJs` |
| Stylelint *(when compiled styles land)* | `@wordpress/stylelint-config` |
| File length | Warn > 800, fail > 1000, no allowlist |

**No baseline.** A baseline is a list of known problems you have agreed to stop looking at, and it only grows. Type issues get fixed as they are introduced, while the context is still in someone's head.

`lint:files` bundles the structural gates that are not really lint:

- file length
- GitHub Actions pinned to SHAs, each carrying a version comment
- every `ci:*` lane present in **both** the workflow and `verify.sh`
- **no `get_posts` / `WP_Query` / `get_post_meta` / `$wpdb` outside `inc/Repository/`**
- **no AdSanity identifiers outside `inc/Integration/Adsanity/`**
- no `'permission_callback' => '__return_true'`
- one top z-index token

These enforce the architecture in [architecture.md](architecture.md). Conventions that are only written down erode; conventions that fail the build do not.

### On the VIP standard

The **whole** `WordPress-VIP-Go` standard runs, not a chosen subset. VIP's value is the sniffs nobody thinks to run; picking two categories means only ever meeting the bar you already knew about.

This plugin is not hosted on WordPress VIP, so a small number of rules genuinely do not apply — they name VIP *platform* functions that are undefined elsewhere, and following them would fatal the plugin. Each is excluded individually in `phpcs.xml.dist` with the reason, and **no security or correctness rule is among them**. Today that list is one entry: `wpcom_vip_add_role()`.

Filesystem sniffs are excluded for `tests/php/*` only. A test that proves a hostile upload is refused needs the file to exist on disk first; shipped code is not excluded and does not trip them.

**Do not weaken a gate to get green.** Fix the cause, or change the rule deliberately with an ADR. Lowering PHPStan a level to ship on a Friday is a decision nobody remembers making by Monday.

## Build

The current plugin is server-rendered PHP. Portal CSS is hand-authored
(`assets/portal.css`). The first Interactivity modules also ship hand-authored
from `assets/interactivity/` (dialog, scroll-lock, helpers) — packaging still
creates and verifies the installable ZIP and checksum, and release verification
requires those module files.

Add a small `@wordpress/scripts`/TypeScript pipeline for
`src/interactivity/*.ts` → `dist/interactivity` when the rest of the frontend
lane lands (wizard/upload/autosave). It must emit `.asset.php` dependency
manifests, run strict type checking and lint/tests in both local QA and CI,
move the existing modules into that tree, and place only compiled `dist/`
output in the release ZIP. Do not add a block build until the plugin has an
actual block.

## Packaging

`bin/release/package.sh` rsyncs the repository into a staging directory under a single top-level `laao-advertiser-portal/` folder, applying `PACKAGE_EXCLUDES`, then zips it with a `sha256` sidecar.

It **hard-fails** if `node_modules`, `src`, `tests`, `vendor`, or `bin` reached the staging directory. A stray `src/` or `node_modules/` is historically how a 4 MB plugin becomes a 400 MB one.

`bin/release/verify-package.sh` asserts against the **actual ZIP**, not the staging directory — because the staging directory is what the script that just ran believes it produced, and the ZIP is what a user installs:

- checksum matches the sidecar
- exactly one top-level directory, named for the slug
- `Version:` in the plugin header matches the release version
- no excluded path leaked in
- `inc/class-autoloader.php` is present (`PACKAGE_REQUIRED` — the production autoloader can never be dropped)
- `dist/` assets exist
- **every `languages/*.po` has a compiled `.mo`**

That last check catches a failure that is otherwise invisible: skip `pnpm i18n:compile` and the site just renders English, with the first report arriving weeks later from a user.

## `vendor/` never ships

`composer.json` `require` is `{"php": ">=8.4"}` and nothing else. Composer is dev-only tooling.

The decisive argument is not payload size — **WordPress has no dependency isolation.** Two plugins each shipping `vendor/autoload.php` with different versions of the same package produce a fatal attributed to whichever loaded second, and the site owner has no way to fix it. Shipping nothing means this plugin can never be the one that broke the site.

Core substitutions, named so nobody reaches for a package later:

| Temptation | Core |
|---|---|
| `ramsey/uuid` | `wp_generate_uuid4()` |
| `symfony/http-foundation` | `WP_REST_Request` / `WP_REST_Response` |
| `monolog` | The audit table |
| `guzzle` | `wp_safe_remote_get()` |
| `intervention/image` | `wp_get_image_editor()` |
| `ezyang/htmlpurifier` | `wp_kses()` with an explicit allowlist |
| a state-machine library | `TRANSITIONS`, ~120 greppable lines |

If a runtime dependency ever becomes genuinely unavoidable, [ADR-0011](adr/0011-no-composer-runtime-dependencies.md) requires php-scoper prefixing into `LAAO_Advertiser_Portal\Vendor\` before it ships.

## Releases

Releases are explicit and tag-driven. Update the version in the plugin header,
`LAAO_ADS_VERSION`, and `package.json`, merge a fully green commit, then push a
strict `vMAJOR.MINOR.PATCH` tag for that commit.

`.github/workflows/release.yml` refuses a tag whose version differs from the
plugin header. It packages and verifies the ZIP, creates a build-provenance
attestation, and uploads these exact assets to a private draft:

- `laao-advertiser-portal-{version}.zip`
- `laao-advertiser-portal-{version}.zip.sha256`

`bin/release/publish.sh` downloads both assets again, compares them byte for
byte with the accepted local build, verifies the SHA-256 sidecar and provenance,
and only then publishes the draft. A failed release stays invisible to the
updater. Published release assets are treated as immutable.

The plugin updater accepts only stable strict-semver releases from the exact
`TheAggressive/LAAO-Advertiser-Portal` repository and exact asset names. It does
not fall back to GitHub source archives, and it verifies the sidecar before
WordPress extracts an update.
