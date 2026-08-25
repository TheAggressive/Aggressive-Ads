# Pull request automation

The goal is one sentence: **low-risk pull requests handle themselves, and
high-risk ones say plainly that they need a person.**

Nothing here weakens a gate. Automation never merges anything itself — it
registers GitHub's *native* auto-merge and lets the `release-branches` ruleset
make the final decision, so every required check, the strict up-to-date policy,
signed commits and resolved review threads still apply exactly as before.

## What happens to your pull request

| Kind | What you do |
|---|---|
| Dependabot patch / minor / grouped | Nothing. Labelled, checked, updated if behind, merged, branch deleted. |
| Machine version sync after a release | Nothing. Unchanged from before. |
| Your routine PR | Add the **`automerge`** label. Then nothing. |
| Your PR without that label | It waits. Opt-in is deliberate — not every PR should merge itself. |
| Anything high risk | It waits, and is labelled `risk:high` + `needs-attention`, **even with `automerge`**. |

Reading the list: a PR carrying `needs-attention` is one automation looked at
and declined. A PR with no policy labels at all has not been classified yet.
The two are different states and are meant to look different.

## Opting in

Add the `automerge` label. That is the whole interface.

It is not enough on its own, by design. The label says *what* to do; a
server-side author check says whether that account may ask, so being able to
label a pull request is not the same as being able to merge one. The permitted
accounts come from the `AGGR_AUTOMERGE_ACTORS` repository variable and default
to the repository owner.

## What makes a pull request high risk

Everything that can change what the *other* gates do, plus the code that decides
who may do what:

- `.github/workflows/**`, `.github/actions/**`, `.github/rulesets/**`,
  `.github/CODEOWNERS`, `.github/dependabot.yml`
- `.releaserc.json`, `bin/release/**`
- `bin/ci/**` and `bin/check-*` — the enforcement scripts
- static-analysis and test configuration (`phpcs.xml.dist`, `phpstan.neon`,
  `phpunit*.xml*`, `playwright.config.*`, ESLint, Stylelint)
- `inc/Security/**`, `inc/REST/**`, `inc/Install/**`, `inc/Storage/**`,
  `uninstall.php`
- `aggressive-ads.php` and `.nvmrc` — the runtime floor
- `composer.json` / `package.json` **when a person edits them**, because those
  are version contracts in a person's hands and routine churn in Dependabot's
- any title marked `!` — semantic-release cuts a major from it, and that reaches
  every installed site

`bin/ci/**` is on that list for a reason this repository learned the hard way:
three separate guards were found silently reading nothing, and a guard cannot
catch its own blinding. A small, plausible edit to one is exactly the change
that must not merge itself.

A pull request whose changed files **cannot be read** is high risk too. An empty
file list is not "changed nothing", it is "we do not know", and the whole policy
fails closed.

## Titles

`squash_merge_commit_title` is `PR_TITLE`, so the pull request title becomes the
squash subject, and semantic-release reads that subject to decide whether
anything ships and at what version. A title it cannot parse is a release that
silently does not happen with every lane green.

So `PR Title` is a required check. Conventional Commit form, scopes supported:

```
fix(cart): prevent duplicate updates
feat: add campaign renewals
feat!: drop PHP 8.3          # breaking; cuts a major
```

Types: `feat`, `fix`, `perf`, `refactor`, `docs`, `test`, `build`, `ci`,
`chore`, `style`, `revert`.

Dependabot's generated titles are already this shape — `chore(deps): bump the
actions group with 3 updates` — because `.github/dependabot.yml` sets
`commit-message.prefix: chore` and `include: scope`. No special case exists for
them, and none should be added; if that ever stops parsing, every dependency
pull request stops auto-merging and the check says why.

## Where the logic lives

`bin/ci/pr-policy-rules.mjs` is pure and has no idea GitHub exists. It answers
two questions — what is this pull request, and may automation act on it — and
`bin/ci/pr-policy-rules.test.mjs` states every branch of both.

`bin/ci/pr-policy.mjs` does only what a test cannot: read the pull request from
the API, apply labels, and ask GitHub to update the branch or register
auto-merge. `bin/ci/check-pr-title.mjs` shares the same parser as the policy, so
the required check and the merge decision cannot disagree about what a valid
title is.

`.github/workflows/pr-policy.yml` has two jobs, split by whether they need
write access. `PR Title` runs on `pull_request` with a read-only token. `PR
Policy` runs on `workflow_run`, where the token can write and **no pull-request
code is ever checked out or executed**.

`pull_request_target` is not used. It would run a write-capable token in the
base branch's context with the pull request's code in the workspace, which is
the pattern that turns a fork pull request into repository write access.

Every fact the policy judges is read from the API, never from the event payload.
Labels, branch names and titles are things a person sets — they can withhold
permission and can never grant it.

### Replacing dependabot-auto-merge.yml

That workflow did this job for Dependabot alone in ninety lines of untested
shell. It is gone, and every property it had is kept and now asserted: the
author is verified against the API, majors are refused, a `BEHIND` branch is
updated and then left for fresh checks to decide, `DIRTY` is refused, an empty
check list is refused rather than read as "nothing failed", and the merge is
registered with GitHub rather than performed.

Keeping both would have meant two workflows racing to register auto-merge on the
same pull request, with the shell copy the one nothing tested.

## Dependency policy, and what was deliberately left alone

`.github/dependabot.yml` ignores `version-update:semver-major`. That was checked
against current GitHub documentation rather than trusted, because the comments
asserting it were load-bearing:

- **`ignore` with `version-update:semver-major` does not suppress security
  updates.** It filters version updates only. The value that *would* suppress
  them is the separate `security-update:semver-major`, which this repository
  does not use.
- **`open-pull-requests-limit` does not apply to security updates**, so the
  limit of 3 cannot hold one back.
- **`cooldown` is version-updates-only**, so it cannot delay one either.

The configuration is correct and was not changed. What *was* added is the
handling for the case it deliberately preserves: a security update may cross a
major, so `pr-policy.mjs` detects a major from Dependabot's own title, labels it
`dependency-major`, and refuses to merge it. It arrives, and it waits for you.

## The two ruleset rules, now live

Applied to the live ruleset on 2026-08-25 and verified by re-reading it from the
API. GitHub does not read `release-branches.json` automatically, so if you ever
recreate the repository these must be applied by hand again — see
[.github/rulesets/README.md](../.github/rulesets/README.md).

**1. `PR Title` as a required status check.** Without it the title check runs
and reports but does not block, so an unparseable title can still merge.

**2. A native `code_scanning` rule.** The ruleset also requires the status check
named `Analyze (JavaScript/TypeScript)`, which only proves the scan *ran* —
`github/codeql-action/analyze` does not fail its job on new alerts. The native
rule requires code scanning to have results for both the commit and the target
ref, and blocks on alert severity:

```json
{
  "type": "code_scanning",
  "parameters": {
    "code_scanning_tools": [
      { "tool": "CodeQL",
        "alerts_threshold": "errors",
        "security_alerts_threshold": "high_or_higher" }
    ]
  }
}
```

This is strictly stronger than the job-name check and survives a rename, so
both are kept: the status check proves the workflow ran, the rule proves what it
found.

Nothing else about the ruleset changed. Enforcement is still active with zero
bypass actors, `strict_required_status_checks_policy` is still true, and squash
is still the only permitted merge method.

## Turning it off

Remove the `automerge` label, and nothing merges itself except Dependabot and
the version sync. Delete `.github/workflows/pr-policy.yml` and the repository
behaves as it did before, minus the Dependabot auto-merge that workflow
replaced.
