# Open work

Deliberately unfinished work, written down so it survives a change of author.

`known-issues.md` records things that are true and will stay true.
`roadmap.md` records which phase builds a planned feature. This file is
narrower: work that is **started, understood, and not done**, with enough
detail that picking it up costs minutes rather than a re-investigation.

Delete an entry when it ships. An entry that has been here through three
releases is either not real or not wanted — say which, in the entry, and then
delete it.

## 1. The reviewer-queue e2e test is flaky on a cold container

`tests/e2e/review.spec.ts:15` waits for the `Campaign review` heading with
Playwright's default 10s expect timeout. On the v1.1.1 release run it took
10.8s and failed; a re-run of the same commit passed, and tests 7 and 8 in the
same file — which use the same screens — passed in both runs.

It is the first test in that file to mount the admin React bundle after a
`wp-login.php` round trip, on a container that has just started. The others
arrive warm.

**Why it matters more than an ordinary flake:** `Package` and `Release` depend
on `e2e`, so this test failing blocks a release until somebody re-runs the job.
It cost the first attempt at v1.1.1.

**Do not fix it with a retry.** `bin/ci/retry.sh` is deliberately scoped to
network-bound setup steps, because a retry around a test turns a fast red into
a slow red and hides exactly the flakiness worth fixing. The cold-start
assumption is the defect.

Worth measuring before choosing: whether the delay is bundle compile/parse, the
REST round trip behind the queue, or the login redirect. A longer timeout on
that one assertion is the cheap answer; warming the screen once in a fixture is
the honest one.

### Measured so far, so nobody repeats it

Against a WordPress Studio site (native PHP, SQLite) — which models the CI
container only loosely, and that caveat is the reason this entry is still open.

* **The REST round trip is not it.** The assertion that fails waits 16 ms warm.
  The screen's bootstrap arrives in a server-rendered `data-aggr-review`
  attribute, so React mounts synchronously and the `<h1>` does not wait on a
  fetch. Nothing plausible turns 16 ms into 10.8 s.
* **The server render is not it.** Stopping and restarting the site to get a
  genuinely cold PHP process: 0.74 s for the first review-screen response
  against 0.65 s warm. Cold start costs about 90 ms there, not seconds.
* **The login redirect is not a race, though it looks like one.** The test
  clicks `#wp-submit` and calls `page.goto()` without awaiting the navigation,
  which reads like a bug. It is not: Playwright serialises navigations on a
  page. Injecting a 4 s delay into the login POST still lands on the review
  screen with the heading visible. Do not "fix" this.

That leaves first compile and parse of the review admin bundle, and whatever
Apache and MySQL do cold that a native-PHP SQLite site cannot reproduce.

**The next occurrence is already diagnosable — do not guess again.** The e2e job
uploads `playwright-report/`, `.playwright-results/` and `test-results/` on
failure with seven-day retention, and `trace: 'retain-on-failure'` is set, so
the trace carries per-step timings for the run that actually failed. Pull it
before changing anything.
