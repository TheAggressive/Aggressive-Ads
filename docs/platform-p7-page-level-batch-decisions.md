# Platform P7 page-level batch decisions

P7 introduces coordinated multi-slot decisions for an entire web page via
`POST /aggr/v1/decisions`. It allows multiple ad slots on a single page to be
evaluated together, enabling roadblocks (companion ads from the same campaign),
competitive separation (preventing competing advertisers from serving together),
and creative deduplication on the same page view. Single-slot `GET /aggr/v1/fill/{slot}`
remains fully supported for backward compatibility.

## Status

- Phase: **P7 — Page-level batch decisions**
- Roadmap state: `[x]`
- Last audited: 2026-08-28
- Authoritative environments: the Docker CI lanes. `pnpm ci:verify` decides
  disagreements, not a local native run.

## Outcome

1. **Batch Decisions Endpoint**: `POST /aggr/v1/decisions` accepts a list of slot slugs (e.g. `['header', 'sidebar', 'footer']`) and returns an atomic map of slot results in a single round-trip.
2. **Roadblocks**: If a campaign is flagged for roadblock delivery (`roadblock: true`), it is served across all compatible requested slots on the page simultaneously or yields if incomplete.
3. **Competitive Separation**: Prevents conflicting advertisers or categories from serving on the same page view simultaneously.
4. **Creative Deduplication**: Prevents the identical creative asset from rendering in multiple slots on the same page when alternative eligible candidates exist.

## Scope boundary

**This phase owns:**

- **Page Decision Coordinator (`Page_Decision_Coordinator`)**: Pure domain service coordinating decisions across multiple slots;
- **Roadblock, Competitive Separation, and Deduplication Rules (`Page_Coordination_Rules`)**;
- **Batch REST Controller (`Decisions_Controller`)**: Exposing `POST /aggr/v1/decisions`;
- **Page-level Exclusion Taxonomy**: `PAGE_COMPETITIVE_SEPARATION`, `PAGE_ROADBLOCK_INCOMPLETE`, `PAGE_DUPLICATE_ASSET`; and
- **Batch Fill Service integration** in `Fill_Service::for_slots()`.

**This phase does not own:**

- **Targeting Predicate Syntax (P8)**; and
- **Frequency Capping Windows (P9)**.

## Canonical model and invariants

### Request & Response

- **Request**: `POST /aggr/v1/decisions`
  ```json
  {
    "slots": ["header-banner", "sidebar-rect", "inline-leaderboard"]
  }
  ```
- **Response**:
  ```json
  {
    "decisions": {
      "header-banner": { "slot": "header-banner", "size": "728x90", "creative": { ... }, "house": null, "beacon": "..." },
      "sidebar-rect": { "slot": "sidebar-rect", "size": "300x250", "creative": { ... }, "house": null, "beacon": "..." },
      "inline-leaderboard": { "slot": "inline-leaderboard", "size": "728x90", "creative": null, "house": { ... }, "beacon": "..." }
    }
  }
  ```

### Invariants

1. **Deterministic Resolution**: Given the same slot order, candidates, and random seed, batch decisions are strictly reproducible.
2. **Roadblock Atomicity**: A roadblock must satisfy all required placement slots on the page or yield to other candidates if only partial inventory is available.
3. **Competitive Separation**: When an advertiser wins slot 1, subsequent slots on the same page exclude candidates marked with competing organization/category rules with `PAGE_COMPETITIVE_SEPARATION`.
4. **Origin & Module Security**: `POST /aggr/v1/decisions` enforces the same origin validation and module toggle (`MODULE_NATIVE_DELIVERY`) as `GET /aggr/v1/fill/{slot}`.
5. **Bounded Slot Limits**: Requests are bounded to a maximum of 20 slots per batch request to prevent CPU/memory exhaustion.

## Exit criteria and evidence

1. `POST /aggr/v1/decisions` is registered and functional under `Rest_Service_Registrar`.
2. Unit tests verify roadblock coordination, competitive separation, and creative deduplication.
3. REST tests verify permission gates, input validation, and single-roundtrip batch responses.
4. CI verification (`pnpm ci:verify`) and all static analysis gates pass cleanly.

## How the coordination settings reach the page

Roadblocks, competitive separation and category exclusivity are all configured
inside `delivery_settings` — `roadblock`, `category`, `exclusive_category`,
`competing_orgs`. That column is not returned by `candidates_for_placement()`,
so until `Decision_Engine::enrich()` began carrying it, **none of these three
rules could fire however they were configured**.

Asset deduplication is the exception, and the reason the gap was easy to miss:
it reads `asset_id`, which the candidate query has always returned, so one page
rule worked while the other three did not.

The unit tests did not show it. Each built a candidate row by hand with
`delivery_settings` already on it — the shape the production query does not
produce. `PageCoordinationInputsTest` goes through `Fill_Service::for_slots()`
instead, so a settings column that stops reaching the row fails.

### Determinism is asserted where it can be exact

`coordinate()` is compared against `Weighted_Selection::choose()` for the same
seed rather than by calling it twice and checking the answers match. Two calls
agreeing proves nothing when the seed is ignored: the winner still matches
whenever chance lands the same way, which for a small candidate set is most of
the time. A first version of that test did exactly this and passed with the seed
deliberately discarded.
