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

`.github/workflows/dependabot-auto-merge.yml` registers squash auto-merge only
for genuine Dependabot minor/patch updates after all PR checks are green. Major,
conflicting, stale, unrecognized, or incompletely checked updates remain open.

## Why only four required checks

The ruleset requires `CI Summary`, `Analyze (JavaScript/TypeScript)`,
`Actionlint` and `Zizmor` — not the ten quality lanes the pipeline actually
runs. That is deliberate, and it is not a gap.

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
check names match this directory's JSON exactly.

When recreating the repository, import the JSON and verify each required check
name after its first successful run — a required check that never reports is
indistinguishable from one that is still running, and blocks every merge.
