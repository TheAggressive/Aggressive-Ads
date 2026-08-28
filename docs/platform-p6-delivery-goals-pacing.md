# Platform P6 delivery goals and pacing

P6 introduces goal-driven delivery, daily and lifetime volume caps, and pacing
distribution algorithms (`EVEN` vs `ASAP`) to the decision engine. It prevents
over-delivery on capped campaigns and provides smooth delivery across campaign
lifecycles without issuing heavy `COUNT(*)` queries on the event ledger during fill.

## Status

- Phase: **P6 — Delivery goals and pacing**
- Roadmap state: `[x]`
- Last audited: 2026-08-28
- Authoritative environments: the Docker CI lanes. `pnpm ci:verify` decides
  disagreements, not a local native run.

## Outcome

Publishers and advertisers can configure:
1. **Goal Types and Amounts**: Impression, click, conversion, spend, or share of voice goals.
2. **Delivery Caps**: Daily and lifetime volume caps that strictly exclude candidates once reached.
3. **Pacing Modes**:
   - `ASAP` (As Soon As Possible): Delivers continuously until daily/lifetime caps or goals are satisfied.
   - `EVEN` (Smooth/Even Pacing): Throttles delivery probabilistically based on elapsed campaign schedule duration versus consumed quota, preventing early-day or early-flight budget exhaustion.

Serving paths evaluate pacing in constant time without executing `COUNT(*)` over event tables.

## Scope boundary

**This phase owns:**

- **Pacing stage (`Pacing_Stage`)**: Integrates into `Decision_Pipeline` at the pacing seam;
- **Pacing domain rules (`Pacing_Rules`)**: Pure domain evaluation of goal progress, lifetime/daily caps, and `EVEN`/`ASAP` schedule velocity;
- **Pacing exclusion taxonomy**: `PACING_DAILY_CAP_REACHED`, `PACING_LIFETIME_CAP_REACHED`, `PACING_BEHIND_PACE`, `PACING_THROTTLED`, `PACING_STAGE_ERROR`, and `PACING_UNAVAILABLE`;
- **In-memory and bounded counter abstractions**: Pure testable contract avoiding SQL aggregation during fill decisions; and
- **Comprehensive unit test suites**: Proving cap enforcement, even distribution curves, and zero side-effects on other pipeline stages.

**This phase does not own:**

- **Event ledger ingestion (P10/P13)** — recording raw beacons to MySQL;
- **Targeting rule engine (P8)**; and
- **Frequency capping (P9)**.

## Canonical model and invariants

Pacing reads goal and delivery settings from candidate line-item and assignment row projections:

| Field | Purpose | Type | Default |
|---|---|---|---|
| `goal_type` | Goal metric ('none', 'impressions', 'clicks', 'conversions', 'spend', 'share_of_voice') | `string` | `'none'` |
| `goal_amount` | Total target delivery units | `int` | `0` (unbounded) |
| `daily_cap` | Maximum delivery units per 24-hour UTC day | `int` | `0` (unbounded) |
| `lifetime_cap` | Maximum delivery units over entire flight | `int` | `0` (unbounded) |
| `pacing_mode` | Velocity distribution ('even', 'asap') | `string` | `'even'` |
| `delivered_lifetime` | Total delivered units so far | `int` | `0` |
| `delivered_today` | Delivered units in the current UTC day | `int` | `0` |

### Invariants

1. **Strict Cap Enforcement**: When `delivered_lifetime >= lifetime_cap` (where `lifetime_cap > 0`), the candidate is excluded with `Exclusion_Reason::PACING_LIFETIME_CAP_REACHED`.
2. **Strict Daily Cap Enforcement**: When `delivered_today >= daily_cap` (where `daily_cap > 0`), the candidate is excluded with `Exclusion_Reason::PACING_DAILY_CAP_REACHED`.
3. **Smooth `EVEN` Pacing**: For `pacing_mode = 'even'`, expected delivery at time $t \in [start, end]$ is calculated as:
   $$\text{Expected Ratio} = \frac{t - start}{end - start}$$
   If actual delivery ratio substantially exceeds expected ratio, candidate is throttled with `Exclusion_Reason::PACING_THROTTLED`.
4. **ASAP Delivery**: For `pacing_mode = 'asap'`, delivery is unrestricted up to the caps.
5. **Zero Ledger Query Overhead**: No `COUNT(*)` or transactional table locks are permitted during candidate evaluation.
6. **Pure Domain Execution**: `Pacing_Rules` and `Pacing_Stage` are pure PHP with zero WordPress or database dependencies.

## Exit criteria and evidence

1. `Pacing_Stage` is active in `Decision_Pipeline::standard()`.
2. Daily and lifetime caps accurately exclude candidates when exceeded.
3. `EVEN` pacing throttles candidates that are ahead of schedule over flight duration.
4. `ASAP` pacing passes candidates until caps/goals are reached.
5. All tests pass with 100% pure domain decoupling, PHPCS, PHPStan Level 8, and unit test suites.
