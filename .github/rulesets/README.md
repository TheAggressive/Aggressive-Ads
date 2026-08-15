# GitHub rulesets

`release-branches.json` mirrors the Aggressive theme's protected release-branch
contract: pull-request-only changes, squash history, signed commits, resolved
review threads, and required CI/security checks.

The matching repository merge settings are squash-only merges, auto-merge on,
automatic head-branch deletion on, merge commits off, and rebase merges off.
The `production` environment permits deployments only from `master`.

GitHub does not apply committed ruleset JSON automatically. Import this file in
**Settings → Rules → Rulesets**, verify each required check name after its first
successful run, and keep the exported live ruleset synchronized with this file.
