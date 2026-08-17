# Build and release

## CI parity

**Green locally means green in CI.** The `ci:*` scripts in `package.json` are the source of truth; each maps 1:1 onto a GitHub Actions job. A CI-only command chain is how "it passed on my machine" becomes a permanent state of affairs.

```
ci:doctor    → node bin/ci/doctor.mjs
composer:verify → strict manifest/lock validation + dependency dry run
ci:build     → pnpm build
ci:frontend  → lint:js && typecheck && lint:css && format:check && test:js
ci:php       → lint:php && analyse:php && test:php:unit
ci:coverage  → unit coverage collection + quantitative regression floor
ci:php:wp    → test:php:integration && test:php:multisite  (needs wp-env)
ci:e2e       → test:e2e  (consumes the build artifact)
ci:package   → release:package && release:verify  (consumes the build artifact)
ci:verify    → bash bin/ci/verify.sh          (every current lane, serially)
```

The i18n lane lands with POT / `.mo` tooling. Until then it is a future gate,
not a command the repository pretends to run.

**The lane list is not maintained twice.** `bin/ci/verify.sh` runs whatever
`bin/ci/lanes.mjs` reads out of `ci.yml`: verification jobs in dependency order,
their `run: pnpm …` steps in file order, each command run once. Adding a step to
a verification job is the only edit — the local rehearsal picks it up because it
is the same source. `pnpm qa:lanes` prints what a local run will do.

Exactly three commands are declared local-only, each with its reason beside it in
`lanes.mjs`: the two `pnpm install --frozen-lockfile` steps, because a laptop is
not a bare runner, and `test:e2e:install`, which apt-gets browser libraries
through sudo on every run and is substituted by `test:e2e:browsers` — the same
pinned browsers without the root prompt. Publishing jobs are excluded by name:
they hold write tokens and cut releases, and none of them decides whether the
change is sound.

`check-ci-parity.sh` now asks whether the derivation *reaches* every `ci:*`
script rather than whether somebody remembered to copy it into `verify.sh`. Both
directions fail: a script no job runs, and a job step the parser cannot see.

### Why local can pass and CI still fail

The lanes are identical — `check-ci-parity.sh` enforces that. The *inputs* are
not, and there are exactly three ways they differ. Two are already closed:

**Uncommitted work.** `pnpm run qa` reads the working tree; CI checks out the
commit. A file created but never `git add`ed is read by every lane locally and
absent remotely, so the same suite passes here and fails there over a file that
does not appear in the diff — because the problem is what the diff omits.
`bin/ci/check-worktree.sh` runs before the lanes and refuses a dirty tree.
`AGGR_QA_ALLOW_DIRTY=1` runs them anyway for work in progress.

**A stale `dist/`.** Gitignored, so CI always builds it. `pnpm build` begins
`rm -rf dist`, so `qa` cannot pass against yesterday's bundle. Running
`pnpm test:e2e` on its own can, and that is a real trap: the browser then tests
whatever was built last, not what the source says now.

**Interpreter versions.** `ci:doctor` compares the host against the workflow's
own `NODE_VERSION` and `PHP_VERSION`, so the pins stay declared in one place. A
Node series mismatch fails, because nothing else constrains the version the
bundler and scripts run on. A PHP series mismatch only warns: `phpstan.neon`
pins `phpVersion`, `phpcs.xml.dist` pins `testVersion`, and `composer.json` pins
`platform.php`, so every analyser already behaves as the floor regardless of
host. The unit suite is the one thing that genuinely executes on the local
interpreter.

**Database state.** wp-env's database persists locally and is new on every CI
run. `tests/e2e/reset.php` clears the fixtures it knows by slug and cannot clear
a row left by some earlier failed run — which is why an e2e fixture must reuse a
slug that file already deletes. `pnpm qa:fresh` destroys the environment and
starts over before running the lanes, which is the closest local equivalent to
what CI does every time.

The build job uploads one `dist/` artifact which the E2E and package jobs both
download. This makes the browser-tested assets the packaged assets instead of
allowing each job to compile a different tree. The E2E job installs Playwright's
pinned Chromium and WebKit builds, starts wp-env, and runs the same
`pnpm ci:e2e` command as local verification. Failed runs retain
the trace, screenshot, video, and WordPress debug log; skipped specs make the
lane fail rather than quietly reducing coverage.

`ci:doctor` runs first everywhere. It semver-checks Node and pnpm against `engines` with no dependencies of its own, so a toolchain mismatch fails in two seconds rather than as a confusing error twenty minutes in.

## Quality gates

| Gate | Standard |
|---|---|
| PHPStan | **Level 8, no baseline, no `ignoreErrors`** |
| PHPCS | WordPress + **the full `WordPress-VIP-Go` standard** + PHPCompatibilityWP |
| ESLint | Flat config, `--max-warnings 0` |
| TypeScript | `strict`, `noUncheckedIndexedAccess` |
| Stylelint | `@wordpress/stylelint-config` |
| File length | Warn > 800, fail > 1000, no allowlist |
| Unit coverage | **At least 8% of executable `inc/` statements** |

**No baseline.** A baseline is a list of known problems you have agreed to stop looking at, and it only grows. Type issues get fixed as they are introduced, while the context is still in someone's head.

The coverage floor is intentionally a regression guard, not a claim that 8% is
enough coverage. The database, REST, authorization, lifecycle, and multisite
behavior lives in the WordPress suites and cannot be measured by the isolated
unit runner. New behavior still needs the appropriate focused test; the floor
prevents the measurable unit-tested surface from silently shrinking.

`lint:files` bundles the structural gates that are not really lint:

- file length
- GitHub Actions pinned to SHAs, each carrying a version comment
- every `ci:*` lane present in **both** the workflow and `verify.sh`
- **no `get_posts` / `WP_Query` / `get_post_meta` / `$wpdb` outside `inc/Repository/`**
- **no AdSanity identifiers in `inc/` or `templates/`**
- no `'permission_callback' => '__return_true'`
- one top z-index token

These enforce the architecture in [architecture.md](architecture.md). Conventions that are only written down erode; conventions that fail the build do not.

### On the VIP standard

The **whole** `WordPress-VIP-Go` standard runs, not a chosen subset. VIP's value is the sniffs nobody thinks to run; picking two categories means only ever meeting the bar you already knew about.

This plugin is not hosted on WordPress VIP, so a small number of rules genuinely do not apply — they name VIP *platform* functions that are undefined elsewhere, and following them would fatal the plugin. Each is excluded individually in `phpcs.xml.dist` with the reason, and **no security or correctness rule is among them**. Today that list is one entry: `wpcom_vip_add_role()`.

Filesystem sniffs are excluded for `tests/php/*` only. A test that proves a hostile upload is refused needs the file to exist on disk first; shipped code is not excluded and does not trip them.

**Do not weaken a gate to get green.** Fix the cause, or change the rule deliberately with an ADR. Lowering PHPStan a level to ship on a Friday is a decision nobody remembers making by Monday.

## Build

Authoring lives under `src/`. Webpack (via `@wordpress/scripts`) compiles to
`dist/`, which is what PHP enqueues and what the release ZIP ships — same shape
as Aggressive Apparel's theme build, with this plugin's output directory kept as
`dist/` (packaging already required it).

```
src/interactivity/*.ts     →  dist/interactivity/*.js (+ .asset.php)
src/styles/*.css           →  dist/styles/*.css     (+ .asset.php)
src/blocks/placement/      →  dist/blocks/placement/ (block.json, editor, view module)
assets/icon.svg            →  shipped as-is (not compiled)
```

```bash
pnpm build            # clean dist/, then modules + assets + blocks
pnpm start            # watch all three lanes
pnpm typecheck        # tsc --noEmit (strict + noUncheckedIndexedAccess)
pnpm lint:js          # ESLint on src/
pnpm lint:css         # Stylelint on all authored CSS, including block CSS
pnpm test:js          # Jest via wp-scripts (pure helpers only)
```

Three webpack configs:

| Config | Role |
|---|---|
| `webpack.modules.config.mjs` | ES modules for `wp_register_script_module` / import maps |
| `webpack.assets.config.mjs` | Styles (and future classic scripts under `src/scripts/`) |
| `build:blocks` (`@wordpress/scripts` defaults) | `src/blocks` → `dist/blocks`, including `viewScriptModule` |

`@aggr/*` and `@wordpress/interactivity` stay bare-specifier externals so
WordPress resolves them at runtime. Entry discovery for modules and assets is
`bin/lib/build-manifest.mjs`. Underscore-prefixed CSS partials
(`src/styles/**/_*.css`) and anything `@import`ed by `portal.css` are not
standalone entries.

`inc/Assets/class-assets.php` reads `.asset.php` manifests for cache-busting
versions and merges listed dependencies with the known `@aggr/*` graph.
Missing `dist/` files no-op rather than 404 — run `pnpm build` before loading
the portal locally.

The placement block is authored under `src/blocks/placement/` the same way the
LAAO theme authors `src/blocks/`. PHP registers `dist/blocks/placement` and
supplies the dynamic `render_callback`. Alignment, spacing, background, and
border come from core block `supports` / `theme.json`, not a parallel CSS
island. The view script fills after paint; it does not count.

## Packaging

`bin/release/package.sh` rsyncs the repository into a staging directory under a
single top-level `aggressive-ads/` folder, applies the package exclusions,
stamps the requested version into the staged plugin header and `AGGR_VERSION`,
normalizes archive metadata, then writes a ZIP with a SHA-256 sidecar. The
checkout is never rewritten. Pull-request and local rehearsals use the
synchronized checked-in version; trusted release runs always supply Semantic
Release's planned version.

`ci:package` builds the archive twice and requires identical digests. This
detects timestamp, traversal-order, or generated-file nondeterminism before a
release reaches GitHub.

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

If a runtime dependency ever becomes genuinely unavoidable, it ships
php-scoper-prefixed into `Aggressive\Ads\Vendor\` before it ships.

## Releases

Releases are calculated automatically from Conventional Commits merged to
`master`. `semantic-release` performs a dry run in an isolated planning job;
`feat` creates a minor release, `fix` creates a patch release, and an explicit
breaking change creates a major release. Documentation, CI, chores, tests, and
dependency maintenance do not publish.

Git tags and published GitHub Releases are the production version source of
truth. The checked-in `package.json`, plugin header, block manifest,
`AGGR_VERSION`, README, and test bootstraps all carry that same strict-semver
version.
`bin/ci/check-version-contract.mjs` fails CI if those declarations drift.

When Semantic Release plans a newer version from a product commit, the trusted
master pipeline first opens a version-only `chore(release)` pull request. Because
GitHub deliberately suppresses recursive workflow events created with
`GITHUB_TOKEN`, the release helper explicitly dispatches CI, CodeQL, and workflow
security against that PR's exact head commit and registers squash auto-merge.
There is no ruleset bypass. After the protected version PR merges, the next
master pipeline confirms the checked-in version equals the plan and publishes
from that synchronized commit.

That suppression applies to the merge as well as the branch. Auto-merge pushes
its merge commit on behalf of whichever credential registered it, so registering
it with `GITHUB_TOKEN` lands the synchronized commit on master without emitting a
push event — and the publishing run never starts. Configure an
`AGGR_RELEASE_TOKEN` secret (a fine-grained PAT or GitHub App token with
`contents: write` and `pull requests: write`) and the helper registers auto-merge
with it, so the merge push starts the run that publishes. Without that secret the
helper still opens and auto-merges the PR, but prints the manual step it leaves
behind:

```bash
gh workflow run ci.yml --ref master
```

`workflow_dispatch` on `master` is a trusted release trigger for exactly that
reason: `release-plan`, `version-pr`, and `release` accept it alongside `push`.
Dispatching on any other ref — including the branch dispatches this helper makes
against the version PR head — runs the quality lanes only.

This automation requires the repository setting **Allow GitHub Actions to
create and approve pull requests**. GitHub combines PR creation and review
approval in one setting; this workflow uses only PR creation and auto-merge and
never submits an approving review. Keep the repository's default workflow
permission read-only: only the isolated `version-pr` job receives the scoped
`actions: write`, `contents: write`, and `pull-requests: write` permissions.

The `package` job receives the planned version only on a trusted master push.
After every quality lane succeeds and the version contract is synchronized,
`semantic-release` creates the tag and a private draft containing these exact
accepted assets:

- `aggressive-ads-{version}.zip`
- `aggressive-ads-{version}.zip.sha256`

The publishing job attests the ZIP. `bin/release/publish.sh` then reconciles the
Semantic Release draft, downloads both assets again, compares them byte for
byte with the accepted CI build, verifies the SHA-256 sidecar and provenance,
and only then publishes the draft. A failed release stays invisible to the
updater. Published release assets are treated as immutable.

The artifact invariant is:

> The ZIP accepted by CI is the same ZIP checksummed, attested, remotely
> verified, and published.

The release job uses the `production` GitHub environment. Its deployment branch
policy permits only `master`, matching the Aggressive Apparel repository.

To rehearse release stamping without creating a tag or release:

```bash
pnpm ci:build
AGGR_RELEASE_VERSION=1.2.3 pnpm ci:package
```

## Local Git hooks

The hooks mirror the Aggressive theme's development cycle:

- `pre-commit` runs deterministic WordPress formatting plus ESLint/Stylelint
  autofixes, then rejects whitespace errors.
- `commit-msg` enforces Conventional Commits so release history stays
  machine-readable.
- `pre-push` runs `pnpm qa:fast`: toolchain and lock validation, repository
  contracts, frontend checks, build, PHP quality/tests, and unit coverage.
- `pnpm qa` is the full release rehearsal, including Docker-backed WordPress,
  Playwright browser/system-dependency provisioning, browser tests, and the
  packaging lane.

Hooks are installed by `pnpm install` through the `prepare` script. `--no-verify`
is an emergency escape hatch, not the normal development path; CI remains the
authoritative enforcement boundary.

## Branch protection

The active `release-branches` ruleset (GitHub ruleset ID `20884246`) is mirrored
in `.github/rulesets/release-branches.json`. It requires pull requests, signed
squash commits, resolved review threads, the stable `CI Summary` check, CodeQL,
Actionlint, and Zizmor. Committing the JSON does not change GitHub settings, so
apply policy changes under **Settings → Rules → Rulesets** and keep the file
synchronized with the live ruleset. When recreating the repository, import the
file after the required checks have completed successfully once.

The plugin updater accepts only stable strict-semver releases from the exact
`TheAggressive/Aggressive-Ads` repository and exact asset names. It does
not fall back to GitHub source archives, and it verifies the sidecar before
WordPress extracts an update.

The Dependabot auto-merge workflow runs in a privileged `workflow_run` context
but never checks out pull-request code. It re-verifies bot authorship, refuses
major or unrecognized updates, refreshes stale branches, and registers squash
auto-merge only after every reported check is green. Branch rules remain the
final independent enforcement layer.
