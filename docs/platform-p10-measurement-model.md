# Platform P10 measurement model

P10 defines the canonical measurement lifecycle for Aggressive Ads, separating
opportunities (`request`), payload selections (`fill` / `no_fill`), delivery
at the client boundary (`served`), attention (`viewable`), engagement (`click`),
and attribution (`conversion`). It establishes explicit structured reasons for
unfilled inventory and provides backward-compatible migration where legacy
`impression` maps seamlessly to `served`.

## Status

- Phase: **P10 — Measurement model**
- Roadmap state: `[x]`
- Last audited: 2026-08-28
- Authoritative environments: PHPUnit Unit + Integration Suites, PHPStan Level 8, PHPCS

## Outcome

Publishers and advertisers can track the granular stages of ad delivery with
distinct lifecycle events. Rather than conflating ad delivery with vague
impressions, the system distinguishes between decisions that yielded an ad
(`fill`), opportunities that went unfilled (`no_fill` with a typed reason),
and client-observed rendering (`served`). Legacy tracking beacons referencing
`impression` continue to be accepted and are normalized to `served`.

## Scope boundary

This phase owns:

- Definition of canonical measurement event types: `request`, `fill`, `no_fill`,
  `served`, `viewable`, `click`, and `conversion`;
- Normalization and legacy alias mapping of `impression` to `served`;
- Structured `No_Fill_Reason` taxonomy mapped to domain exclusion reasons;
- Pure `Measurement_Rules` validating event transitions, lineage, and replay keys;
- `Event_Repository` updates supporting the expanded lifecycle vocabulary while
  preserving atomic replay protection; and
- Tracking beacon ingestion compatibility for both `served` and legacy `impression`.

This phase does not own:

- Client-side IntersectionObserver calculation and continuous viewability thresholds (owned by P11);
- Conversion definitions, tracking pixels, and attribution windows (owned by P12);
- Schema migration for multi-dimensional rollup aggregation (owned by P13); or
- Custom reporting UI / CSV date-comparison views (owned by P14).

## Canonical model and ownership

```
+-------------------------------------------------------------------------------+
|                       Measurement Lifecycle Stages                            |
|                                                                               |
|  [request] ----> Decision Engine                                              |
|                      |                                                        |
|                      +---> [fill]    (Payload returned)                       |
|                      |        |                                               |
|                      |        +---> [served]  (Rendered at client boundary)   |
|                      |                 |        (alias: legacy 'impression')  |
|                      |                 +---> [viewable] (50% 1s threshold)    |
|                      |                 |                                      |
|                      |                 +---> [click]    (Signed hop)          |
|                      |                          |                             |
|                      |                          +---> [conversion]            |
|                      |                                                        |
|                      +---> [no_fill] (With structured No_Fill_Reason)         |
+-------------------------------------------------------------------------------+
```

### Event Entities and Vocabulary

- **`request`**: An evaluation opportunity presented to the decision engine.
- **`fill`**: A candidate assignment won competition and a creative payload was returned.
- **`no_fill`**: No eligible candidate won. Carries a typed `No_Fill_Reason` (e.g. `no_candidates`, `targeting_mismatch`, `frequency_capped`, `pacing_throttled`, `schedule_closed`).
- **`served`**: The creative asset was delivered/rendered into the client DOM.
- **`viewable`**: The rendered ad satisfied IAB viewability requirements (P11).
- **`click`**: A visitor clicked the ad via a cryptographically signed click hop.
- **`conversion`**: A conversion action was attributed to the interaction (P12).

## Invariants

1. **Atomic Replay Protection**: Each `(token_hash, event)` pair can only be recorded once in the append-first event ledger. Replays return false at the database boundary without throwing errors.
2. **Deterministic Migration & Normalization**: The string `impression` is normalized to `served` across all validation and repository layers, while legacy clients sending `impression` remain fully functional.
3. **Structured Reasons for Unfilled Inventory**: Every `no_fill` event must carry an allowed `No_Fill_Reason` code matching domain exclusion rules.
4. **Privacy-Preserving Ingestion**: Raw IP addresses and user identifiers are never stored in the ledger; events store one-way salted digests (`ip_hash`).

## Exit criteria

1. Canonical measurement vocabulary defined in `Aggressive\Ads\Domain\Measurement_Event_Type` and `Aggressive\Ads\Domain\No_Fill_Reason`.
2. Pure `Measurement_Rules` enforces valid event types, aliases, and lifecycle transitions without WordPress dependencies.
3. `Event_Repository` accepts all canonical event types, maps `impression` -> `served`, and enforces token-event uniqueness.
4. `Beacon_Controller` processes `served` beacons alongside legacy `impression` beacons with replay prevention.
5. Unit and integration tests prove event normalization, duplicate rejection, and no-fill reason categorization.
