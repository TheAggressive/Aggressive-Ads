# Aggressive Ads — documentation

The plugin owns the advertising campaign workflow and is the source of truth
for which creative fills each slot. The host theme owns site presentation and
must embed `aggr/ad-slot` (or the PHP helper / shortcode). Those boundaries
hold throughout this codebase, and most of what follows exists to keep them
from eroding.

## Reading order

New contributors should read these in order. Each assumes the previous.

1. [architecture.md](architecture.md) — the layers, and the rules that keep them apart
2. [domain-model.md](domain-model.md) — the five entities, their storage, their invariants
3. [campaign-workflow.md](campaign-workflow.md) — the state machine every status write goes through
4. [roles-and-capabilities.md](roles-and-capabilities.md) — who may do what, and why ownership is org-scoped

Then, as needed:

| Doc | Covers |
|---|---|
| [data-schema.md](data-schema.md) | Audit table DDL, option inventory, migration contract |
| [portal-routing-and-ui.md](portal-routing-and-ui.md) | URL grammar, auth gate, template hierarchy, theme independence |
| [interactivity-stores.md](interactivity-stores.md) | Interactivity API conventions and the script-module i18n gap |
| [rest-api.md](rest-api.md) | Route table, auth model, the 404-not-403 rule |
| [notifications.md](notifications.md) | Capability-driven recipients, private fan-out, deduplication and failure policy |
| [threat-model.md](threat-model.md) | Attack surfaces, mitigations, and the test proving each |
| [authorization-failure-review.md](authorization-failure-review.md) | Phase 11 authorization and failure-state audit, findings and executable evidence |
| [load-and-soak-testing.md](load-and-soak-testing.md) | Phase 11 HTTP capacity criteria, environment contract and durability evidence |
| [testing-strategy.md](testing-strategy.md) | Five suites, two PHPUnit configs, and why |
| [build-and-release.md](build-and-release.md) | CI lanes, packaging policy, semantic-release |
| [accessibility.md](accessibility.md) | WCAG target, dialog contract, creative alt text |
| [i18n.md](i18n.md) | Text domain, POT drift gate, the plugin-vs-theme `.mo` naming trap |
| [known-issues.md](known-issues.md) | Live list of things that are true and annoying |
| [open-work.md](open-work.md) | Work that is started, understood, and not done |
| [administration.md](administration.md) | Running the plugin: screens, capabilities, scheduled work, Site Health, uninstall |
| [runbook.md](runbook.md) | Deploying to production, verifying each step, and rolling back |
| [roadmap.md](roadmap.md) | Phases 1–11 (what already shipped vs remaining product) |
| [platform-implementation-progress.md](platform-implementation-progress.md) | The platform sequence beyond the roadmap, and what exists today |
| [platform-phase-definition-template.md](platform-phase-definition-template.md) | Required structure for implementation-ready phase definitions and exit evidence |
| [platform-p0-baseline.md](platform-p0-baseline.md) | Platform regression matrix, executed quality baseline and P0 exit evidence |
| [platform-p1-line-item-closeout.md](platform-p1-line-item-closeout.md) | Remaining P1 correctness, migration and evidence required for completion |
| [platform-p2-creative-model.md](platform-p2-creative-model.md) | P2 creative asset, revision, assignment, migration and enterprise exit contract |
| [platform-serving-contract.md](platform-serving-contract.md) | Shared decisioning, privacy, performance and exit contract for P3–P9 |
| [platform-measurement-contract.md](platform-measurement-contract.md) | Shared event, attribution, analytics and reporting contract for P10–P14 |
| [platform-inventory-commerce-contract.md](platform-inventory-commerce-contract.md) | Shared inventory, forecasting, creative-format, billing and workflow contract for P15–P20 |
| [platform-api-privacy-contract.md](platform-api-privacy-contract.md) | Shared RBAC, API, webhook, provider, consent and traffic-quality contract for P21–P28 |
| [platform-scale-assurance-contract.md](platform-scale-assurance-contract.md) | Shared scale, ingestion, observability, performance, accessibility and intelligence contract for P29–P34 |
| [suite-roadmap.md](suite-roadmap.md) | Aggressive Ads suite: identity, admin shell, native delivery, cache, tracking |

## Conventions

Comments explain *why*, not *what*. A comment restating the line below it is noise; a comment recording the incident that made the line necessary is the most valuable thing in the file.

The same applies here. If a doc tells you a rule, it should tell you what goes wrong when the rule is broken.
