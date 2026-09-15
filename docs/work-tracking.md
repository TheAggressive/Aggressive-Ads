# Work tracking

Aggressive Ads uses **GitHub Issues for actionable work** and `docs/` for the
system knowledge that must remain after an implementation is complete.

The distinction is deliberate:

- **Issues answer:** what still needs to become done?
- **Pull requests answer:** what changed to make one actionable unit done?
- **Docs answer:** what is the system, why is it designed this way, what must
  remain true, and what is the high-level dependency sequence?

The roadmap-tracking migration is #259. Competitive product expansion is tracked
under #258.

## What belongs where

Put work in an Issue when it can become **done**: a feature slice, migration,
security gap, test gap, performance qualification, documentation correction or
phase closeout.

Keep material in docs when it remains useful after the implementation issue is
closed: architecture, invariants, threat boundaries, domain semantics, API
contracts, migration reasoning, failure semantics, operational recovery,
measured evidence and durable product direction.

Do not convert historical explanation into backlog. Before creating an issue
from a document, verify the statement against current `master`.

## Hierarchy

### Phase umbrella

Every incomplete platform phase has one umbrella issue. It owns:

- outcome;
- dependency and entry criteria;
- durable invariants inherited from the phase/group contract;
- links to implementation issues as they are scoped; and
- closeout criteria.

The umbrella is **not** closed by the first implementation PR. It closes only
when the phase is functional end to end, migrated, tested, documented and its
exit evidence is recorded.

### Action issue

Concrete work should be small enough for a focused PR whenever practical. An
action issue contains the specific scope, required evidence and dependencies.

A PR that completes it uses `Fixes #123` or `Closes #123`. If a PR only advances
an issue, it references the issue without a closing keyword.

Do not split work merely to create more tickets. Split when the pieces can be
implemented, reviewed or rolled back independently.

### Closeout

A phase closes only after its implementation issues are complete and the phase
contract has real exit evidence. Closeout verifies migrations, security,
authorization, accessibility, browser behavior, performance/scale evidence,
operations and documentation as applicable.

## Current phase issues

| Phase | Tracking issue | Notes |
|---|---:|---|
| P17 Creative experience | #260 | 3/4 slices built; remaining action is #261. |
| P18 Rich creative types | #262 | Define the detailed phase before implementation. |
| P19 Billing domain | #263 | Authoritative commercial model for later workflow/revenue work. |
| P20 Publisher workflow | #264 | Blocked on P19 for renewals/make-goods with commercial meaning. |
| P21 Organization RBAC | #265 | Replaces the one-org-per-user invariant. |
| P22 Public API/service accounts | #266 | Blocked on P21 authorization semantics. |
| P23 Webhooks | #267 | Builds on P21/P22 identities and scopes. |
| P24 Provider registry | #268 | Identifier-dependent behavior is gated by P27. |
| P25 Programmatic foundations | #269 | Builds through P24; does not build a DSP/SSP. |
| P26 Supply chain/ads.txt | #270 | Publisher-controlled, diffable and reversible. |
| P27 Privacy and consent | #271 | Logical gate; do not defer because of its phase number. |
| P28 Traffic quality | #272 | Explainable classification, not irreversible authority. |
| P29 Scalability abstractions | #273 | Only for measured bottlenecks. |
| P30 Event-ingestion scale | #274 | Builds on P29 durability seams. |
| P31 Observability | #275 | Observes real implemented paths. |
| P32 Performance/capacity gates | #276 | Turns representative measurements into release contracts. |
| P33 Accessibility | #277 | Cross-cutting throughout every UI phase; final audit later. |
| P34 Intelligence | #278 | Late, bounded suggestion layer with no direct authority. |

## Competitive product issues

#258 is the umbrella for product capabilities that strengthen the direct-sales
publisher platform without duplicating P17–P34:

- #279 scheduled white-label advertiser reports;
- #280 advertiser inventory storefront and booking;
- #281 reusable campaign templates / sales-product presets;
- #282 proposal, quote and insertion-order workflow;
- #283 publisher sales and revenue dashboard;
- #284 deterministic renewal and sales intelligence;
- #285 CRM integration boundary;
- #286 cross-channel campaign and line-item model;
- #287 newsletter advertising; and
- #288 sponsored-content/native-sponsorship workflow.

## Recommended execution order

Issue numbers are identifiers, not priority. The current dependency order is:

1. Finish P17: #261, then close #260.
2. Near-term competitive value with foundations already present: #279 and #280.
3. P18 rich creatives: #262.
4. P19 billing/commercial domain: #263.
5. Commercial product layer: #281 and #282.
6. P20 publisher workflow: #264.
7. Sales operations: #283 and #284.
8. P21 RBAC: #265.
9. P22 API/service accounts: #266.
10. P23 webhooks: #267.
11. CRM boundary: #285.
12. P27 privacy/consent: #271 before new identifier-dependent provider work.
13. Cross-channel model: #286.
14. Newsletter and sponsored-content channels: #287 and #288.
15. Provider/programmatic/supply chain: #268 → #269 → #270.
16. Traffic quality: #272.
17. Scale/ingestion/observability/performance: #273 → #274 → #275 → #276.
18. P33 accessibility (#277) remains an active gate throughout all UI work.
19. P34 intelligence (#278) begins only after the data/privacy/audit model is
    mature enough to constrain and evaluate suggestions.

Independent work may run in parallel when it does not share an unresolved
schema, workflow or contract dependency. A named dependency is not bypassed to
keep a developer busy.

## Phase-definition rule

The group contracts are not implementation tickets. Before starting a phase that
does not already have a detailed definition, write its definition from
`platform-phase-definition-template.md` and review the migration, invariants,
failure/recovery behavior, observability and executable exit evidence first.
Only then create the PR-sized implementation issues that the definition makes
concrete.

This avoids two failure modes at once: giant issues called “build billing,” and
a speculative pile of child tickets whose contracts change before anyone starts
them.

## Documentation updates in implementation PRs

A PR updates durable docs when it changes a durable truth. It does **not** keep a
second checklist in docs merely to mirror GitHub Issues.

At phase closeout:

1. record measured/verified exit evidence in the phase document;
2. mark the phase complete in `platform-implementation-progress.md`;
3. update architecture/runbook/API/security docs whose truth changed; and
4. close the phase umbrella issue.

The closed Issues and PRs are the implementation history. The docs should not
reproduce that history line by line.