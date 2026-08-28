# Platform P4 exact scheduling

P4 introduces serve-time exact scheduling and dayparting evaluation to the
decision engine. It decouples serving eligibility from cron reconciliation: an ad
scheduled to end at 10:30 UTC stops serving at 10:30:00 UTC, regardless of when
the background lifecycle reconciliation sweeps.

## Status

- Phase: **P4 — Exact scheduling**
- Roadmap state: `[x]`
- Last audited: 2026-08-28
- Authoritative environments: the Docker CI lanes. `pnpm ci:verify` decides
  disagreements, not a local native run.

## Outcome

Ad delivery enforces sub-hour precision, custom timezones, and daypart
schedules (day-of-week and hour-of-day restrictions) at serve time.

Publishers and advertisers gain deterministic control over when creative runs.
Evaluation happens inside the pure domain pipeline on every fill without
requiring real-time database mutations or cron synchronization.

## Scope boundary

**This phase owns:**

- **Exact timestamp evaluation**: strict UTC start and end second enforcement
  on candidate assignment rows (`start_at_ts`, `end_at_ts`);
- **Timezone-aware dayparting**: evaluating active hours and days of week
  against candidate schedule configurations in the declared timezone (or UTC);
- **Schedule decision stage (`Schedule_Stage`)**: a dedicated, separable
  pipeline stage in `inc/Domain/` emitting closed exclusion reasons;
- **Schedule exclusion taxonomy**: stable machine codes
  (`schedule_not_started`, `schedule_expired`, `schedule_daypart_excluded`,
  `schedule_invalid_timezone`); and
- **Trace observability**: explaining scheduling exclusions in the staff trace.

**This phase does not own:**

- **Pacing and delivery goals (P6)** — even distribution of impressions across
  the active window;
- **Priority tiers and share of voice (P5)**;
- **Targeting predicates (P8)** — non-temporal context matching (geo, device,
  taxonomy);
- **Lifecycle state transitions** — `Workflow\Campaign_Clock` continues to
  reconcile post and line-item lifecycle statuses (`approved` → `scheduled` →
  `live` → `completed`) for admin and reporting views; and
- **Event ledger and rollup tables**.

## Entry criteria

- P3 is complete `[x]`, and `Decision_Pipeline` provides the stage evaluation
  contract.
- Candidate assignment rows provide UTC `start_at_ts` and `end_at_ts`.
- The complete regression baseline is green before implementation starts.

## Canonical model and ownership

P4 introduces no durable database tables. Scheduling rules are evaluated in-memory
during pipeline execution from assignment rows and line-item delivery settings:

| Concept | Purpose |
|---|---|
| `Schedule_Rules` | Pure domain helper for time bounds, timezone translation, and daypart matching. |
| `Schedule_Stage` | `Decision_Stage` that evaluates time windows and dayparts against `Decision_Context::$now`. |
| `Exclusion_Reason` | Closed schedule exclusion codes. |

## Invariants

1. **Clock immutability**: The evaluation instant `$now` is passed via
   `Decision_Context` from the request; stages never call `time()` or system
   clock functions directly, ensuring decisions are 100% deterministic and
   replayable with traces.
2. **UTC authority**: All stored boundary timestamps (`start_at_ts`, `end_at_ts`)
   are UTC seconds.
3. **Timezone safety**: Invalid timezones fall back safely or exclude cleanly
   with `schedule_invalid_timezone` rather than throwing uncaught runtime
   exceptions.
4. **Sub-hour precision**: A window ending at timestamp $T$ excludes candidates
   at $now \ge T$.

## Workflows and API

- `Decision_Pipeline::decide()` executes `Schedule_Stage` in sequence.
- Staff trace endpoint `GET /aggr/v1/placements/{id}/decision` reports exact
  schedule exclusions when candidates are outside their active schedule.

## Exit criteria

1. Candidates before `start_at_ts` are excluded with `schedule_not_started`.
2. Candidates at or after `end_at_ts` are excluded with `schedule_expired`.
3. Candidates with daypart restrictions are evaluated in their configured timezone
   and excluded with `schedule_daypart_excluded` when outside active hours or days.
4. Open/unset bounds (`0`) correctly pass through without exclusion.
5. All evaluation is pure domain logic in `inc/Domain/` with zero WordPress
   dependencies.
6. Execution is covered by exhaustive unit tests in `tests/php/Unit/Domain/`.

## Exit evidence and decision

### 1. Timestamp boundary precision
- Verified in `ScheduleEvaluationTest::test_candidate_before_start_time_is_excluded_not_started()` and `ScheduleEvaluationTest::test_candidate_at_or_after_end_time_is_excluded_expired()`.
- Exact sub-second boundary condition ($now \ge end\_at\_ts$) verified to exclude expired candidates.

### 2. Timezone & daypart evaluation
- Verified in `ScheduleEvaluationTest::test_daypart_matching_active_day_and_hours_passes()`, `ScheduleEvaluationTest::test_daypart_outside_active_day_is_excluded()`, `ScheduleEvaluationTest::test_daypart_outside_active_hour_is_excluded()`, `ScheduleEvaluationTest::test_daypart_overnight_span()`, and `ScheduleEvaluationTest::test_timezone_translation_applies_correctly()`.
- Invalid timezone fallback verified in `ScheduleEvaluationTest::test_invalid_timezone_is_rejected()`.

### 3. Pipeline integration
- Verified in `DecisionPipelineTest::test_schedule_expired_candidate_is_excluded_in_pipeline()`, proving `Schedule_Stage` integrates in `Decision_Pipeline::standard()` and correctly populates stage name and exclusion reason in the trace.

### Decision
Phase P4 is complete `[x]`. All scheduling rules and pipeline stages are tested and verified.
