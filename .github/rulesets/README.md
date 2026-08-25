# GitHub rulesets

`release-branches.json` mirrors the Aggressive theme's protected release-branch
contract: pull-request-only changes, squash history, signed commits, resolved
review threads, and required CI/security checks.

The matching repository merge settings are squash-only merges, auto-merge on,
automatic head-branch deletion on, merge commits off, and rebase merges off.
The `production` environment permits deployments only from `master`.

Release versions also reach `master` through this ruleset. The trusted master
pipeline opens a version-only PR, explicitly dispatches every required workflow
against its head commit, and registers squash auto-merge. No release bot or
GitHub Actions integration has a branch-protection bypass.

The repository must enable **Settings → Actions → General → Allow GitHub
Actions to create and approve pull requests** for that PR creation to work.
GitHub bundles creation and approval under one switch; the release workflow
does not approve reviews. Default workflow permissions remain read-only, and
only the isolated version-PR job receives scoped write permissions.

`.github/workflows/pr-policy.yml` classifies every pull request and registers
squash auto-merge for the ones that qualify — Dependabot minor/patch updates,
and human pull requests explicitly labelled `automerge` by a permitted account.
Major, conflicting, stale, unrecognized, incompletely checked and **every
high-risk** pull request remains open. It replaced
`dependabot-auto-merge.yml`, whose properties it keeps and now tests. See
[docs/pull-request-automation.md](../../docs/pull-request-automation.md).

## Why only five required checks

The ruleset requires `CI Summary`, `Analyze (JavaScript/TypeScript)`,
`Actionlint`, `Zizmor` and `PR Title` — not the ten quality lanes the pipeline
actually runs.

`PR Title` is there because `squash_merge_commit_title` is `PR_TITLE`: the title
becomes the squash subject, and semantic-release reads that subject to decide
what ships. An unparseable title is a release that silently does not happen with
every other lane green, which is precisely the class of failure a required check
exists for. It is deliberately cheap — no install, no build, no WordPress. That is deliberate, and it is not a gap.

`CI Summary` is an aggregate. `bin/ci/check-summary.mjs` reads every lane's
result and fails unless each one in `QUALITY_LANES` reported success or was
legitimately skipped, so requiring that single check requires all of them.

The consequence worth knowing before adding a job: **a new CI lane does not gate
merges until it is added to `QUALITY_LANES` in `bin/ci/summary-rules.mjs`.** A
job that runs, fails, and is not in that list leaves `CI Summary` green and the
pull request mergeable. Adding the job to this ruleset instead would be the
wrong lever — it would mean editing GitHub settings by hand for every lane, and
the required-check name would then have to be kept in step with the job's
display name forever.

## Keeping this file and the live ruleset in step

The live repository has this policy enabled as the active `release-branches`
ruleset (GitHub ruleset ID `20884246`). GitHub does not apply committed ruleset
JSON automatically, so changes must be applied in **Settings → Rules →
Rulesets** and reflected back into this file.

To check the two still agree:

```sh
gh api repos/TheAggressive/Aggressive-Ads/rulesets/20884246 \
  --jq '.rules[] | select(.type == "required_status_checks")
        | .parameters.required_status_checks[].context'
```

Verified against the live ruleset on 2026-08-23: the rules and the four required
check names matched this directory's JSON exactly.

**Two rules were added on 2026-08-25 and applied to the live ruleset the same
day**, then verified by re-reading it from the API:

1. `PR Title` as a required status check.
2. A native `code_scanning` rule.

GitHub does not apply committed ruleset JSON automatically, so on a rebuild they
must be applied by hand again along with everything else here.

The second is a security improvement rather than a rename. The existing
`Analyze (JavaScript/TypeScript)` status check proves only that the scan *ran* —
`github/codeql-action/analyze` does not fail its job on new alerts. The native
rule requires code scanning to have results for both the commit and the ref
being updated, and blocks on alert severity (`errors` /
`high_or_higher`). Keep both: the status check proves the workflow ran, the rule
proves what it found. Re-run the verification command above after applying.

When recreating the repository, import the JSON and verify each required check
name after its first successful run — a required check that never reports is
indistinguishable from one that is still running, and blocks every merge.
