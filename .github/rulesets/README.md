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

The live repository currently has this policy enabled as the active
`release-branches` ruleset (GitHub ruleset ID `20884246`). GitHub does not apply
committed ruleset JSON automatically, so changes must be applied in
**Settings → Rules → Rulesets** and reflected back into this file. When
recreating the repository, import this file and verify each required check name
after its first successful run.
