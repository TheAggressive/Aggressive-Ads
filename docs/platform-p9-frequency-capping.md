# Platform P9 frequency capping

P9 implements privacy-respecting, expiring frequency capping at the campaign,
line-item, and creative levels across session, hourly, daily, and rolling time
windows. Storage is abstracted behind `Frequency_Store`, enabling fast in-memory
and object-cache implementations without persistent fingerprinting.

## Status

- Phase: **P9 — Frequency capping**
- Roadmap state: `[x]`
- Last audited: 2026-08-28
- Authoritative environments: the Docker CI lanes. `pnpm ci:verify` decides
  disagreements, not a local native run.

## Outcome

1. **Multi-Level Frequency Capping**: Cap delivery at `campaign`, `line_item`, or `creative` scopes.
2. **Flexible Time Windows**:
   - `session`: Current browser / request session;
   - `hour`: fixed clock-hour window (3,600s);
   - `day`: fixed UTC-day window (86,400s); and
   - `custom`: Configurable window in seconds.
3. **Pluggable Expiring Storage**:
   - `Frequency_Store` interface defining `get_count()`, `record_impression()`, and `reset()`.
   - `Array_Frequency_Store` for pure tests and zero-state execution.
   - `Transient_Frequency_Store` for WordPress object-cache / transient expiring storage.
4. **Privacy & No Fingerprinting**:
   - Uses opaque, short-lived, privacy-safe visitor identifiers derived server-side or passed in request facts.
   - Storage keys automatically expire and contain no raw PII or permanent device signatures.
5. **Fault Isolation**:
   - Storage failures fail open (allowing delivery rather than dropping inventory), while candidate rule errors exclude the candidate with `FREQUENCY_STAGE_ERROR` without halting the pipeline.

## Scope boundary

**This phase owns:**

- **Frequency Rules (`Frequency_Rules`)**: Pure domain calculation of frequency limits and window matching;
- **Frequency Pipeline Stage (`Frequency_Stage`)**: Evaluating candidates within `Decision_Pipeline`;
- **Storage Abstraction (`Frequency_Store`, `Array_Frequency_Store`, `Transient_Frequency_Store`)**; and
- **Frequency Exclusion Taxonomy**: `FREQUENCY_CAPPED`, `FREQUENCY_STAGE_ERROR`.

**This phase does not own:**

- **Measurement & Impression Beacon Attribution (P10)**.

## Canonical model and invariants

### Frequency Rule Schema

Frequency capping criteria are stored under `delivery_settings['frequency_capping']` or `frequency_rules`:

```json
{
  "enabled": true,
  "max_impressions": 3,
  "window": "day",
  "window_seconds": 86400,
  "level": "line_item"
}
```

### Invariants

1. **Bucketed keys, not sliding TTLs.** The storage key carries the window it
   belongs to — `intdiv( now, window_seconds )` — so the boundary is absolute.
   The TTL only reclaims storage.

   The first implementation left the bucket out and relied on the TTL alone.
   Every write refreshed it, so the window slid: a visitor who saw an ad at
   least once an hour never expired, and an hourly cap behaved as a lifetime
   cap. `test_a_late_delivery_does_not_extend_the_window` is the regression.

2. **Counting is wired to serving, not deciding.** `Frequency_Rules::record_delivery()`
   is called by `Fill_Service` once an ad is actually returned, so a staff
   decision trace does not spend a visitor's impressions.

   This was the defect that made the whole stage inert on release: nothing
   called `increment()` anywhere in the plugin, so `get_count()` always returned
   zero and no configured cap ever excluded a candidate. Every test passed
   because each arranged its own count. `test_recorded_deliveries_are_what_the_cap_counts`
   is the round trip that now has to hold.

3. **Increments are atomic where they can be.** `wp_cache_incr()` under a
   persistent object cache; read-then-write on the transient fallback, which is
   best-effort by design and documented as such — a lost count serves one extra
   impression, and the phase fails open anyway.

4. **Expiring Keys**: counts are stored with a TTL matching the window length,
   floored at one second so a nonsensical configuration still counts.
2. **Zero Fingerprinting**: Keys are hashed combinations of `(blog_id, level, entity_id, ephemeral_viewer_id)`.
3. **Fail-Open on Storage Errors**: If external cache / Redis is unavailable, the pipeline allows delivery rather than starving publishers of ads.
4. **Deterministic Evaluation**: Given the same frequency count and candidate limits, evaluation is 100% deterministic.

## Exit criteria and evidence

1. `Frequency_Rules`, `Frequency_Stage`, and `Frequency_Store` implement pure domain contracts and integrate into `Decision_Pipeline::standard()`.
2. Unit tests verify campaign, line-item, and creative level limits across session, hour, day, and custom windows.
3. `FrequencyStoreTest` exercises `Transient_Frequency_Store` against real
   WordPress. The unit suite covers only the in-memory store, so without this
   the class that actually runs in production was executed by nothing.
3. Static analysis (PHPStan Level 8) and coding standards (PHPCS) pass with 0 errors.

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
