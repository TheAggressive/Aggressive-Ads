# Known issues

Things that are true, annoying, and worth writing down so nobody rediscovers them. Each says what it is, what it costs, and whether anything can be done.

## Script modules have no translation mechanism below WordPress 7.0

**What.** `wp_set_script_translations()` does not work for the Script Modules API, which the Interactivity API is built on. Below WordPress 7.0 there is no supported way to ship translated strings to an Interactivity store.

**Cost.** Every user-facing string in a store has to be translated in PHP and hydrated through `wp_interactivity_state()`, and TypeScript may never contain a literal a user will read.

**Status — core has closed this, and the plugin cannot use it yet.** WordPress 7.0 added `WP_Script_Modules::set_translations()` and `print_script_module_translations()` (verified against the 7.1 core in `.cache/ci/wordpress`; both carry `@since 7.0.0`). This plugin's floor is **6.7** — `AGGR_MIN_WP` in `aggressive-ads.php`, and `Requires at least` in the header — so on a supported install the API may simply not be there.

**What removing the hydration would take,** in order: raise `AGGR_MIN_WP` to `7.0` and the plugin header with it; call `set_translations()` for each registered module in `Assets\Assets`; produce the module JSON catalogs in `bin/i18n/compile.sh`; then delete the string members from the `wp_interactivity_state()` payloads and let TypeScript hold the literals.

Raising the floor is the whole cost, and it is not a translation decision — it decides which sites can install the plugin at all. Until somebody wants to make that call, the hydration convention stays and is not a workaround for a gap so much as the price of supporting 6.7. See [interactivity-stores.md](interactivity-stores.md).

## wp-login.php steals focus 200ms after load, and an empty required field submits nothing

**What.** `wp-login.php` schedules `wp_attempt_focus()` 200ms after load: it
focuses a field and calls `select()` on it. A Playwright `fill()` still in
flight when that lands can lose its value. The password field carries
`required`, so the browser then refuses to submit the form — **no request is
made at all**. The page never navigates, the network log is empty, and the test
waits out its timeout looking at a login form.

Both halves are worth knowing on their own. The focus steal is reproducible on
demand: fill immediately after the document commits and `document.activeElement`
moves to `#user_login` about 140ms later. And clicking submit with an empty
`required` field produces zero requests, `validity.valueMissing === true` and
the message "Please fill out this field" — verified directly rather than
inferred.

**Cost.** Three red release lanes. It presented as a cold-start flake because a
warm machine fills in about 2ms and never overlaps the timer, while the CI
container's password fill took 79ms.

**Status — fixed**, in `tests/e2e/admin-login.ts`, which is now the only place
the suite signs in to wp-admin. It waits for core's autofocus before filling,
and then asserts the password field actually holds the password before
submitting. The second half is the durable part: prevention that silently stops
working is what cost the three lanes, and the assertion turns any future variant
into a five-second failure that names the empty field.

**The two earlier occurrences were attributed to the wrong thing.** On the
v1.1.1 and v1.4.0 release runs this was read as first compile and parse of an
admin React bundle on a cold container, and three of four candidate causes were
eliminated against a WordPress Studio site. The elimination work was sound and
the conclusion was not: the third occurrence left a trace showing the test never
reached an admin page at all. What made the difference was evidence rather than
reasoning, which is why the artifact-upload fixes below mattered more than they
looked.

**The instrumentation that made the diagnosis possible.** Until 2026-08-23 the
e2e job uploaded nothing on failure, and three causes compounded:
`actions/upload-artifact` excludes hidden files unless told otherwise and
`.playwright-results/` is hidden; `playwright-report/` never existed because no
HTML reporter was configured; and `if-no-files-found` defaults to `warn`, so
losing all of it produced a yellow annotation rather than a failure. All three
are fixed — `include-hidden-files: true`, an `html` reporter, and
`if-no-files-found: error`.

The trace is what answered it, and specifically the screencast frames rather
than the DOM snapshots: snapshots are written per action and stop at the click,
while the frames kept rolling and showed the password box empty and the username
selected 21ms after the click landed. Read those first next time.

**Do not reach for `bin/ci/retry.sh`** if something like this returns: it is
deliberately scoped to network-bound setup steps, because a retry around a test
turns a fast red into a slow red and hides the assumption that is the actual
defect.

## The packaging lane reported files missing that were in the archive (resolved)

**What.** `pnpm ci:package` builds the ZIP twice and compares digests, so that a
release is provably reproducible. On one CI run the second build produced an
archive missing `dist/interactivity/wizard.js` — one 3 KB file, nothing else,
from a `dist/` that had already yielded a complete 364-file archive seconds
earlier in the same job. Re-running the job passed. Eight consecutive local
builds were byte-identical, so it has never reproduced outside that one runner.

**Cost.** A false red on a lane every pull request has to pass, and — much more
important — **the operator is told the wrong thing**. Verification runs before
the digest comparison, so a second archive that differs is reported as
`required file missing from the archive`, which reads as an unbuilt file rather
than as the reproducibility failure it is. Nothing prints the two listings, so
there is no evidence left behind to diagnose from.

**Cause.** The archive was never missing anything. `verify-package.sh` checked
each required path with `echo "${listing}" | grep -qxF "${path}"` under
`set -euo pipefail`. `grep -q` exits on its first match; if `echo` is still
writing, it takes SIGPIPE, and `pipefail` makes the pipeline fail — so the `if`
read a present file as absent. Which path lost the race depended on scheduling,
which is why one re-run passed and eight local rebuilds looked clean.

Everything recorded above fits. The staging check passed because it tests files
directly and pipes nothing; only the archive check used the pipe. The digests
matched because the two archives were identical.

It came back on a pull request that added files to the package: CI reported
`dist/interactivity/scroll-lock.js` missing, a local re-run reported `wizard.js`,
and `unzip -Z1` showed both present exactly once. Against that archive, the piped
check falsely missed 6 to 20 times in 300 runs, and a here-string never did.

**Why it mattered beyond one red lane.** The same pattern guarded the forbidden
paths, where a false miss runs the dangerous way: a file that must not ship is
reported absent and the gate passes. `check-worktree.sh` had it too, and could
report a clean tree with untracked files in it.

**Status.** Resolved. All five occurrences under `bin/` read a here-string now,
and five consecutive `pnpm ci:package` runs passed with one digest.
`bin/ci/check-pipefail-grep.mjs` fails any script that sets `pipefail` and pipes
into `grep -q`, because a race cannot be tested reliably but the pattern can be
refused. Run against the scripts as they were, it names exactly those five lines.

The diagnostics below were built while the cause was unknown. They did not fire
on this bug, because nothing differed, but they still answer the question they
were written for.

`bin/release/compare-archives.sh` runs **before** the second verification and
names the paths that differ, so the failure is reported as the reproducibility
failure it is rather than as a missing required file. It compares digests as
well as listings: an earlier draft compared listings only and answered
"identical" for two archives with the same paths and different bytes, which is a
lie about the one property the lane exists to assert. Its own test caught that.

`verify-package.sh` now prints what the archive *does* hold in a directory whose
required file is missing, with a count. One lost file out of thirteen and a
directory that was never built were the same message before, and they are
completely different problems.

Both were checked against the original incident rather than only against
fixtures: an archive rebuilt without `dist/interactivity/wizard.js` produces

```
Present in the first build and missing from the second:
  aggressive-ads/dist/interactivity/wizard.js
```

and the verifier reports the twelve siblings that survived.
