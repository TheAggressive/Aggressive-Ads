# LAAO Advertiser Portal — documentation

The portal owns the advertising campaign workflow. AdSanity owns ad delivery. The LAAO theme owns site presentation. Those boundaries hold throughout this codebase, and most of what follows exists to keep them from eroding.

## Reading order

New contributors should read these in order. Each assumes the previous.

1. [architecture.md](architecture.md) — the layers, and the rules that keep them apart
2. [domain-model.md](domain-model.md) — the five entities, their storage, their invariants
3. [campaign-workflow.md](campaign-workflow.md) — the state machine every status write goes through
4. [roles-and-capabilities.md](roles-and-capabilities.md) — who may do what, and why ownership is org-scoped
5. [adsanity-integration.md](adsanity-integration.md) — what AdSanity actually does, verified against its source

Then, as needed:

| Doc | Covers |
|---|---|
| [data-schema.md](data-schema.md) | Audit table DDL, option inventory, migration contract |
| [portal-routing-and-ui.md](portal-routing-and-ui.md) | URL grammar, auth gate, template hierarchy, theme independence |
| [interactivity-stores.md](interactivity-stores.md) | Interactivity API conventions and the script-module i18n gap |
| [rest-api.md](rest-api.md) | Route table, auth model, the 404-not-403 rule |
| [notifications.md](notifications.md) | Capability-driven recipients, private fan-out, deduplication and failure policy |
| [threat-model.md](threat-model.md) | Attack surfaces, mitigations, and the test proving each |
| [testing-strategy.md](testing-strategy.md) | Five suites, two PHPUnit configs, and why |
| [build-and-release.md](build-and-release.md) | CI lanes, packaging policy, semantic-release |
| [accessibility.md](accessibility.md) | WCAG target, dialog contract, creative alt text |
| [i18n.md](i18n.md) | Text domain, POT drift gate, the plugin-vs-theme `.mo` naming trap |
| [known-issues.md](known-issues.md) | Live list of things that are true and annoying |
| [roadmap.md](roadmap.md) | Phases 2–11 |

## Architecture Decision Records

[`adr/`](adr/) holds the decisions that would otherwise be re-litigated every few months. Each records Status, Context, Decision, Consequences, and Alternatives rejected.

**The contract: a PR that changes a decision recorded in an ADR must supersede that ADR in the same PR.** Add a new ADR marked `Supersedes NNNN`, and edit the old one's status to `Superseded by NNNN`. An ADR is never edited in place to say something different from what was decided — the point of the record is that you can see what was believed at the time, and why it changed.

| # | Decision |
|---|---|
| [0001](adr/0001-standalone-plugin-zero-theme-dependency.md) | Standalone plugin, zero theme dependency, proven by e2e |
| [0002](adr/0002-private-cpts-behind-repositories.md) | Private CPTs behind repositories, not custom tables |
| [0003](adr/0003-audit-log-in-custom-table.md) | Audit log in a custom indexed table |
| [0004](adr/0004-server-rendered-plus-interactivity-api.md) | Server-rendered templates + Interactivity API, no SPA |
| [0005](adr/0005-portal-route-via-rewrite-rule.md) | Portal route via rewrite rule + `template_include` |
| [0006](adr/0006-adsanity-is-downstream-publish-target.md) | AdSanity is a downstream publish target, not the system of record |
| [0007](adr/0007-placement-mapping-is-explicit-data.md) | Placement→ad-group mapping is explicit data, keyed on term ID, fails closed |
| [0008](adr/0008-explicit-transition-table.md) | An explicit transition table owns every status write |
| [0009](adr/0009-org-scoped-map-meta-cap.md) | Custom roles + org-scoped `map_meta_cap` |
| [0010](adr/0010-two-stage-creative-storage.md) | Two-stage creative storage |
| [0011](adr/0011-no-composer-runtime-dependencies.md) | No Composer runtime dependencies; `vendor/` never ships |
| [0012](adr/0012-own-autoloader-in-production.md) | The plugin's own autoloader is the production autoloader |
| [0013](adr/0013-phpunit-9-with-wp-test-suite.md) | PHPUnit 9.6 + the WP core test suite, two bootstraps |
| [0014](adr/0014-version-driven-idempotent-installer.md) | Version-driven idempotent installer, not activation-hook-only |
| [0015](adr/0015-adsanity-contract-stub-for-ci.md) | AdSanity contract stub for CI, plus a drift test |
| [0016](adr/0016-utc-unix-integer-times.md) | All portal times are UTC Unix integers |
| [0017](adr/0017-self-contained-design-tokens.md) | Self-contained `--laao-ads-*` tokens with literal fallbacks |

## Conventions

Comments explain *why*, not *what*. A comment restating the line below it is noise; a comment recording the incident that made the line necessary is the most valuable thing in the file.

The same applies here. If a doc tells you a rule, it should tell you what goes wrong when the rule is broken.
