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
   - `hour`: 1-hour rolling or clock-hour window (3,600s);
   - `day`: 24-hour rolling or calendar-day window (86,400s); and
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

1. **Expiring Keys**: All frequency counts are stored with strict TTL expiration matching the window length.
2. **Zero Fingerprinting**: Keys are hashed combinations of `(blog_id, level, entity_id, ephemeral_viewer_id)`.
3. **Fail-Open on Storage Errors**: If external cache / Redis is unavailable, the pipeline allows delivery rather than starving publishers of ads.
4. **Deterministic Evaluation**: Given the same frequency count and candidate limits, evaluation is 100% deterministic.

## Exit criteria and evidence

1. `Frequency_Rules`, `Frequency_Stage`, and `Frequency_Store` implement pure domain contracts and integrate into `Decision_Pipeline::standard()`.
2. Unit tests verify campaign, line-item, and creative level limits across session, hour, day, and custom windows.
3. Static analysis (PHPStan Level 8) and coding standards (PHPCS) pass with 0 errors.
