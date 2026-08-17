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
