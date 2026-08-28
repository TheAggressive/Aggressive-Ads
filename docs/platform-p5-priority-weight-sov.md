# Platform P5 priority, weight, and share of voice

P5 introduces configurable, data-driven priority tiers and share-of-voice (SoV)
competition to the decision engine. It replaces flat random competition across
different campaign tiers with hierarchical tier selection and weighted rotation
within the winning tier.

## Status

- Phase: **P5 — Priority, weight, share of voice**
- Roadmap state: `[x]`
- Last audited: 2026-08-28
- Authoritative environments: the Docker CI lanes. `pnpm ci:verify` decides
  disagreements, not a local native run.

## Outcome

Publishers can configure delivery priority tiers (e.g., Sponsorship vs. Standard vs.
Remnant/House) by integer rank rather than hard-coded product labels.

Higher-priority candidates unconditionally outrank lower-priority candidates.
When multiple candidates share the winning tier, delivery is distributed
proportionally to their weight / share-of-voice.

## Scope boundary

**This phase owns:**

- **Priority stage (`Priority_Stage`)**: selects the highest eligible priority
  tier (lowest numerical rank, e.g. Tier 1 > Tier 100) and excludes candidates in
  lower tiers with `Exclusion_Reason::PRIORITY_LOWER`;
- **Priority domain rules (`Priority_Rules`)**: pure evaluation of candidate priority
  values, defaults, and tier grouping;
- **Tier-bounded weighted selection**: proportional weighted competition strictly
  scoped to the winning priority tier; and
- **Deterministic and statistical test matrix**: verifying priority isolation, seed
  replayability, and statistical Chi-squared goodness-of-fit / binomial distribution
  without flaky tests.

**This phase does not own:**

- **Pacing and delivery goals (P6)** — lifetime caps, even distribution over time;
- **Page-level batch decisions (P7)**; and
- **Targeting predicates (P8)**.

## Canonical model and ownership

Priority and weight are read from assignment and line-item row projections:

| Field | Purpose | Type | Default |
|---|---|---|---|
| `priority` | Priority tier rank (lower number = higher precedence) | `int` (1..65535) | `100` |
| `weight` | Relative weight / share-of-voice within priority tier | `int` (1..1000) | `100` |

## Invariants

1. **Strict tier precedence**: A lower-priority candidate never competes against a
   higher-priority candidate. If Tier 1 has any eligible candidate, no Tier 100 candidate
   can win.
2. **Pure domain isolation**: Priority evaluation is 100% pure PHP in `inc/Domain/`
   with zero WordPress dependencies.
3. **Trace explainability**: Excluded lower-tier candidates are tagged with
   stage `'priority'` and reason `'priority_lower'` in the trace.
4. **Weighted fairness within tier**: Multiple candidates with the same priority tier
   compete strictly according to `weight / sum(weights)`.

## Exit criteria

1. When candidates have different priority values, only the highest tier survives
   `Priority_Stage`.
2. Lower-tier candidates are excluded with `priority_lower`.
3. Candidates in the winning tier compete via `Weighted_Selection` based on relative weight.
4. Deterministic seed replay is proven across multi-tier fixtures.
5. All tests pass in pure unit tests with zero flakiness.

## Exit evidence and decision

### 1. Strict Priority Tier Precedence
- Verified in `PriorityEvaluationTest::test_priority_stage_excludes_lower_tiers_with_reason()` and `DecisionPipelineTest::test_higher_priority_tier_candidate_wins_over_lower_tier_regardless_of_weight()`.
- Proved that high-priority candidates (Tier 10) unconditionally win over lower-tier candidates (Tier 100) even when the lower-tier candidate holds a dominant weight (990 vs 10).

### 2. Trace Explainability
- Verified that lower-tier candidates carry stage `'priority'` and reason `'priority_lower'` in `DecisionPipelineTest` and `PriorityEvaluationTest`.

### 3. Statistical Share of Voice Distribution
- Verified in `WeightedSelectionTest::test_multi_candidate_share_of_voice_matches_proportions()`, proving 50% / 30% / 20% weight splits distribute within tight binomial confidence bands over 10,000 draws.

### Decision
Phase P5 is complete `[x]`. Priority tiers, data-driven ranks, and weighted rotation within tiers are implemented and verified.

## How the policy reaches the stage

`candidates_for_placement()` returns the **assignment's** columns only. Every
field this phase evaluates lives on `aggr_line_items`, and delivered counters
come from `aggr_rollups`. `Decision_Engine::enrich()` attaches both to each
candidate row before the pipeline runs and before the result is cached — two
bounded queries for the whole set, never one per candidate, and only on a cache
miss.

This is recorded because the phase originally shipped without it: the stage read
its configuration from a row that never carried it, so it fell back to defaults
and a configured policy changed nothing at serve time. The unit tests passed
throughout, because each built a row by hand with keys the real query does not
return. `DecisionPolicyInputsTest` goes through the engine so a dropped field
fails.

Adding a field this stage reads means adding it to `delivery_policies_for()` in
the same change.
