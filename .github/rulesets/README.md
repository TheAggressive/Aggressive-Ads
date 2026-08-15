# GitHub rulesets

`release-branches.json` mirrors the Aggressive theme's protected release-branch
contract: pull-request-only changes, squash history, signed commits, resolved
review threads, and required CI/security checks.

The matching repository merge settings are squash-only merges, auto-merge on,
automatic head-branch deletion on, merge commits off, and rebase merges off.
The `production` environment permits deployments only from `master`.

`.github/workflows/dependabot-auto-merge.yml` registers squash auto-merge only
for genuine Dependabot minor/patch updates after all PR checks are green. Major,
conflicting, stale, unrecognized, or incompletely checked updates remain open.

The live repository currently has this policy enabled as the active
`release-branches` ruleset (GitHub ruleset ID `20884246`). GitHub does not apply
committed ruleset JSON automatically, so changes must be applied in
**Settings → Rules → Rulesets** and reflected back into this file. When
recreating the repository, import this file and verify each required check name
after its first successful run.
