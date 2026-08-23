# Build and release

## CI parity

**Green locally means green in CI.** The `ci:*` scripts in `package.json` are the source of truth; each maps 1:1 onto a GitHub Actions job. A CI-only command chain is how "it passed on my machine" becomes a permanent state of affairs.

```
ci:doctor    → node bin/ci/doctor.mjs
composer:verify → strict manifest/lock validation + dependency dry run
ci:build     → pnpm build
ci:frontend  → lint:js && typecheck && lint:css && format:check && lint:shell && test:tools && test:js
ci:php       → lint:php && analyse:php && test:php:unit
ci:coverage  → one integration run + unit coverage + quantitative regression floor
ci:php:wp    → test:php:multisite
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

**The gate the derivation cannot reach.** `lanes.mjs` reads `ci.yml`, so nothing
it produces covers `workflow-security.yml` — where Actionlint and Zizmor live,
both required checks. A workflow edit therefore passed `pnpm qa` and could only
fail on GitHub, which is the exact divergence the derivation exists to prevent,
in the one corner it cannot see. Two real defects reached CI that way: `secrets`
used in a step `if:`, where it is not an available context, and a GitHub App
token inheriting blanket installation permissions.

`bin/ci/lint-workflows.sh` closes it, and `verify.sh` runs it first — it takes
seconds, and a broken workflow invalidates everything after it. It reads the
pinned versions and checksums **out of `workflow-security.yml`** rather than
repeating them, for the same reason the lanes are derived: a second copy of a
pinned version is how a local check silently stops standing in for the gate it
mirrors. Binaries are cached in the gitignored `build/tools/`, and a download
that fails its checksum is deleted rather than cached.

Zizmor's online audits need a credential. The script borrows `gh auth token`
when one is available and says so when it is not, so a narrower local run is
never presented as the same check.

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

**Database state.** MySQL stores its data on tmpfs, so the database is disposable
and never overlaps a developer's site. `tests/e2e/reset.php` still clears its
fixtures between browser specs; `pnpm qa:fresh` removes and recreates the entire
Compose project before rehearsing every lane.

**Infrastructure flakes.** A fourth difference is not about inputs at all: CI
pulls digest-pinned images from the network while a laptop usually has warm
Docker layers. Environment startup is retried because image pulls are
idempotent and network-bound.

The browser install is the other one, and it is worth knowing where its time
goes: the browsers themselves are cached and restore in seconds, while
`--with-deps` installs 181 packages from apt on every run. That is real work,
not a no-op — WebKit needs far more system libraries than Chromium, so dropping
`--with-deps` would trade the time for a browser that cannot launch. Its step
bound is set to catch a mirror that has stalled, not to police normal variance;
the first attempt at that bound was set from an assumption rather than a
measurement and killed a healthy run.

Pull requests install Chromium only and skip the `webkit-dialog` project;
`master` and any publishing run install both browsers and exercise Safari. The
packages that made that step unreliable were WebKit's — GStreamer, `libavfilter`
and the rest of the multimedia stack — and every Chromium project needs a
fraction of them.

The cost is stated rather than hidden: a WebKit-only regression in the shared
dialog is caught at merge instead of in review. Still before release, but after
approval. WebKit is opt-*out* (`AGGR_E2E_SKIP_WEBKIT`), set only by the
pull-request lane, so a local run and a master run both keep it — the reduced
run is the exception, and it has to be asked for.

`bin/ci/retry.sh` wraps such a command with exponential backoff, and `env:start`
uses it. **Never combine it with `timeout`.** `timeout` kills the command it is
given, not the process group beneath it: wrapping `playwright install
--with-deps` killed playwright while the `apt-get` it had spawned as root kept
running and kept `/var/lib/apt/lists/lock`, so both remaining attempts died in
seconds with "Could not get lock". A retry that orphans a lock holder does not
merely fail to help — it guarantees failure. It lives in the pnpm script rather than the workflow step on purpose:
`lanes.mjs` matches `run: pnpm <command>`, so wrapping the workflow line would
drop the step out of the derived local lanes, and putting it in the script gives
local runs the same resilience.

Retries are deliberately narrow. A retry around a command that fails for real
reasons turns a fast failure into a slow one and hides flakiness that deserves
fixing, so it belongs only on network-bound, idempotent steps. Each retry emits
a workflow warning, so a run that only passed on the second attempt still says
so.

The build job uploads one `dist/` artifact which the coverage, E2E, and package
jobs download. This makes the integration-tested, browser-tested, and packaged
assets identical instead of allowing each job to compile a different tree. The
E2E job installs Playwright's
pinned Chromium and WebKit builds, starts the Compose stack, and runs the same
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
| PHP coverage | **At least 69.75% of executable `inc/` statements across unit + integration** |

**No baseline.** A baseline is a list of known problems you have agreed to stop looking at, and it only grows. Type issues get fixed as they are introduced, while the context is still in someone's head.

The coverage floor is a regression guard set just below the measured 69.86%
PCOV baseline, not a substitute for a focused test. The same tests report
70.25% under Xdebug because that driver marks 53 `global` declarations as hit;
PCOV does not. The checker unions statements from the isolated unit report and
the single-site WordPress report, normalizing their checkout paths and counting
a statement hit by either suite once. Multisite remains a separate behavioral
gate rather than part of this metric.

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

The denylist also names two files rather than directories, `.phpunit.result.cache` and `.phpunit.cache`, and the reason is worth keeping: **packaging rsyncs the working tree, not `git ls-files`, so being gitignored does not keep a file out of the archive.** `.phpunit.result.cache` shipped that way — 109 KB of PHPUnit's result cache — and it also made "reproducible archive" untrue, because its contents depend on which tests that machine last ran. Anything a local run drops in the repository root is a candidate; the check after staging is what catches the next one.

`bin/release/verify-package.sh` asserts against the **actual ZIP**, not the staging directory — because the staging directory is what the script that just ran believes it produced, and the ZIP is what a user installs:

- checksum matches the sidecar
- exactly one top-level directory, named for the slug
- `Version:` in the plugin header matches the release version
- no excluded path leaked in
- `inc/class-autoloader.php` is present (`PACKAGE_REQUIRED` — the production autoloader can never be dropped)
- `dist/` assets exist
- **every committed `languages/*.po` has its compiled `.mo` in the archive**

That last check catches a failure that is otherwise invisible: skip `pnpm i18n:compile` and the site just renders English, with the first report arriving weeks later from a user.

It reads the **committed** catalogs rather than the archive's, and that direction matters. The archive does not ship `.po` files — WordPress never reads one, and four locales of source catalogue is half a megabyte in every install, growing with each language. A check that iterated the archive would then match nothing and pass over a release containing no translations at all. Anchoring on the repository also catches what the old form could not: a locale whose `.mo` never made it into the package, where both files are absent from the archive and there is nothing left to notice. `languages/` is the one directory that ships selectively — compiled `.mo` and `.json` go, the `.po` sources and the directory's own README stay behind, and the `.pot` ships because that is the file a translator starts from.

## `vendor/` never ships

`composer.json` `require` is `{"php": ">=8.4"}` and nothing else. Composer is dev-only tooling. There are two Composer projects — the plugin's, and `tests/wp/` holding the PHPUnit 9.6 the WordPress suites need — and neither ships: `tests/` is on the denylist above, which covers the second one twice over.

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

Git tags and published GitHub Releases are the version source of truth, and
`package.sh` stamps the planned version into the staged tree at package time
without mutating the checkout.

What the checkout declares is split, matching the Aggressive Apparel theme:

| Declaration | Reads |
|---|---|
| `package.json` | `0.0.0-development` |
| Plugin header, `AGGR_VERSION`, block manifest, README, test bootstraps | The last released version |

`0.0.0-development` is semantic-release's marker for a project whose version
lives in its tags. Everything WordPress reads carries a real number instead, so
a development install shows something sensible in the plugins list and the
updater compares against a real version rather than a placeholder that sorts
below every release.

Those numbers go stale between releases, and that is accepted rather than
solved — the theme's `style.css` currently trails its published release by two
minors. Nothing reads them at release time, so staleness costs a slightly old
number on a development site and nothing else.

`bin/ci/check-version-contract.mjs` enforces the split: the manifest must be the
placeholder, and every other declaration must be strict semver and agree with
the rest. Drift between them breaks nothing at release time, which is exactly
why it needs a gate — it would otherwise sit there being quietly untrue.

The version a build stamps comes from the **tag**, not from any of those
declarations. `package.sh` resolves it in order: an explicit argument, then
`AGGR_RELEASE_VERSION` (which CI always supplies), then
`git describe --tags --abbrev=0`, and only then the plugin header — which is
reachable just for a build from an extracted source archive with no repository
to ask.

That order is the point. The header is a copy of the tag maintained by hand, and
it was two releases stale within a day of the release process changing. With the
header as the fallback, `bash bin/release/package.sh` with no argument produced
an archive named and labelled `1.1.1` while containing `1.2.1` code, and nothing
anywhere said so.

`pnpm version:sync` writes the latest tag into the five declarations. It is
cosmetic — nothing built is wrong when they are behind — and exists so that
catching them up is one command rather than five files and a gate telling you
which one you missed.

### Why not release-please

Its release pull request is the same shape as the sync described below, so the
question comes up roughly whenever somebody meets this machinery. The answer is
fixed by a constraint rather than a preference: **release-please's generated
commits are unverified**, and `master` requires signed commits with no bypass
actor, so its pull request could never merge here.

- [release-please-action#1124](https://github.com/googleapis/release-please-action/issues/1124)
  — open since 2025-07-06 with no comments.
- The `signoff` option that did land is the `Signed-off-by:` DCO trailer, a line
  of text. It is easy to mistake for a solution and does nothing for
  `required_signatures`.
- Changesets commits through git the same way, so it has the same problem.

Adopting either means dropping signature enforcement or adding a bypass actor.
Revisit only if release-please gains API-created commits.

### Keeping the repository honest

WordPress reads the plugin header as the authoritative version and `AGGR_VERSION`
is a cache key, so a checkout that disagrees with the release is functionally
wrong, not merely untidy. Two halves keep them together:

**Delivery.** A `version-sync` job runs after `release` and opens a pull request
writing the published version into the declarations, regenerating the POT
alongside them — `Project-Id-Version` embeds the version, and the drift check
normalizes that header away before comparing, so nothing else would ever notice
the catalog being left behind.

It opens a pull request rather than pushing. `master` requires signed commits,
reviewed pull requests and passing checks, and restoring a bypass to commit a
version header would trade a real security property for a convenience. The
commit is created through the API (`sign-commits`) so GitHub signs it, and the
release credential is what lets the pull request start its own checks.

**Enforcement.** `CI Summary` requires the `version-sync` job to have succeeded
whenever a release was planned, so a sync that fails to open fails loudly on the
run somebody is already watching.

There was briefly a second guard — `check-version-sync`, failing `lint:files`
while the checked-in version differed from the newest tag. It was removed as
redundant and, once auto-merge landed, actively harmful: between a release
publishing and the sync merging, it could only produce false failures on
unrelated pull requests. A gate whose whole window is the couple of minutes
before the thing it guards resolves itself is not enforcement, it is noise.

### The self-updater is off on a checkout

A development install is *always* behind the newest release, because the
checked-in version is only bumped by hand — so it is always offered an update.
Installing that update would delete the checkout: `Plugin_Upgrader` clears the
destination directory and unpacks the release ZIP over it, and the ZIP is built
from an allowlist that contains no `.git`, `src`, `bin` or `tests`. Uncommitted
work and history go with it.

`Plugin_Updates::is_enabled()` therefore returns false when `.git` is present in
the plugin root, and `init()` registers nothing at all rather than having each
callback return early — a registered `upgrader_pre_download` could still verify
a package and hand it back for a directory that must never be overwritten.

Override with the `aggr_enable_plugin_updates` filter, which receives whether
the root looked like a checkout. The Aggressive Apparel theme carries the same
guard for the same reason.

**Publishing is asked for, never a side effect of merging.** Merging runs the
quality pipeline and stops. Everything merged since the last tag ships together
when somebody runs:

```bash
gh workflow run "CI/CD Pipeline" --ref master -f publish=true
```

Running it when nothing is pending is harmless: planning reports no release and
the run stops.

Two reasons, and the second is not hypothetical. Every release is an update
event on a live site, and four shipped here in a single afternoon — a cadence
nobody chose. And a `push` run that was cancelled mid-flight took `Release` down
with it, so v1.2.1 never published and nothing said why. A push that cannot
publish cannot interrupt a release.

When publishing is requested, the pipeline packages, tags and publishes on that
run. Nothing is written back to the repository during it, so there is no second
pass and no credential beyond the run's own `GITHUB_TOKEN`.

### Why the version is not committed

This was built the other way first, and the reversal is worth recording because
the original design looked more correct and was not.

Synchronizing the version into `master` meant a bot opening a pull request
against a branch that requires signed commits, reviewed pull requests, and
passing checks. GitHub will not let its own token do that unattended: it holds
workflow runs on bot-authored pull requests at `action_required` until a human
approves them, and it suppresses the push event when their merge lands. So a
release stalled twice, and both stalls looked exactly like a pull request that
was still running. v1.1.0 sat blocked for two hours with every check green.

Each fix worked and each added a moving part — an API-created commit so GitHub
would sign it, a separate credential so the pull request was not bot-authored, a
GitHub App so that credential did not expire, guards to make the remaining
manual steps loud. All of it existed to get a version number onto a protected
branch.

The version does not need to be there. `package.sh` already stamped the staged
tree, so the published artifact was always correct; the commit only made the
repository agree with the tag. Removing it removes the pull request, the
credential, the App, the approval gate, the suppressed push event, and the
guards written for them, and `master` keeps every protection because nothing but
a person's pull request ever lands on it.

The tag is the version. A checkout is not a release and now says so.

### What this costs

`0.0.0-development` is what the repository reads, everywhere, forever.
`bin/ci/check-version-contract.mjs` fails the build if any declaration says
otherwise — including a tree where every file was bumped together, which agrees
with itself and is still not a release.

Two consequences worth knowing:

- **A development install reports `0.0.0-development`.** The updater compares
  against the *installed* header, which is the stamped version for anything
  installed from a release, so this only affects a checkout — where it correctly
  reads as older than every published build.
- **README ships.** It is stamped in the staged tree alongside the plugin header,
  the `AGGR_VERSION` constant and the block manifest, and `package.sh` verifies
  all four applied before archiving. It is the one stamped file that is
  documentation rather than code, which makes it the easiest to forget.

The same approach runs in the Aggressive Apparel theme, where `style.css` in the
repository trails the published release by design.

The `package` job receives the planned version only on a trusted master push.
After every quality lane succeeds, `semantic-release` creates the tag and a
private draft containing these exact accepted assets:

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
- `pre-push` runs the Docker-free `pnpm qa:fast`: toolchain and lock validation,
  repository contracts, frontend checks, build, and PHP quality/unit tests.
  ShellCheck runs from a checksum-pinned binary fetched into `.cache/ci/` by
  `bin/ci/install-shellcheck.sh`; the digest-pinned container image in
  `bin/check-shell.sh` now covers only platforms with no pinned build. Bump the
  two versions together.
- `pnpm qa:local` adds the WordPress suites on a local MySQL (`test:php:native`)
  and the real browser workflows against the Studio site that serves this
  checkout. Neither claims CI parity: the native runner uses the host's MySQL and
  PHP rather than the pinned 8.4 pair, and says so at the end of every run. That site must opt in first — `.aggr-e2e-site` in its
  root, or `AGGR_STUDIO_E2E_ALLOW=1` — because the suite resets the `admin` and
  `advertiser` passwords there and does not put them back. MySQL integration and
  coverage remain CI concerns.
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
