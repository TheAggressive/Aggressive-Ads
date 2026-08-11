# ADR-0006 — AdSanity is a downstream publish target, not the system of record

**Status:** Accepted — 2026-08-08; amended 2026-08-10

## Context

AdSanity already stores ads, groups, sizes, click URLs, and start and end dates. It is tempting to treat its `ads` post type as the campaign record and have the portal be a friendly form in front of it.

But AdSanity models *an ad*, and the business object is *a campaign*: one organization, several placements, several creatives, a review history, a rejection reason, a revision count, an audit trail, and a lifecycle in which most states exist **before** any ad should be visible to the public. There is no place in AdSanity's schema for a campaign awaiting review, and no way to express "these four ads are one thing a customer bought."

AdSanity is also a licensed third-party plugin on a weekly update cadence, whose meta keys are undocumented implementation detail read out of its source.

## Decision

The portal owns the domain. AdSanity is a **publish target reached through an adapter**, and it holds no state the portal cannot rebuild.

All AdSanity-specific behaviour lives in `inc/Integration/Adsanity/`. The strings `'ads'`, `'ad-group'`, `ADSANITY_EOL`, and every AdSanity meta key (`_url`, `_size`, `_start_date`, `_end_date`, …) appear nowhere else in `inc/`. `bin/ci/check-repository-boundary.sh` fails the build on a violation.

Campaign orchestration speaks to `Ad_Provider_Interface`: publish/reconcile, unpublish, suppress, resume, and the transition-effect map. The concrete AdSanity publisher is registered behind that contract. Provider status is deliberately absent: the portal's state machine is authoritative and never derives campaign state from AdSanity.

**AdSanity being inactive is a supported state, not an error state.** Placements exist, campaigns are created, creative is uploaded, submission works, staff review and reject. Only *approval* fails, cleanly, naming the unmapped placement, with no status change and no partial publish. See [ADR-0007](0007-placement-mapping-is-explicit-data.md).

## Consequences

- The blast radius of an AdSanity change is one directory. Without the boundary, every AdSanity release is a full-repository audit.
- The portal's own statuses are authoritative. `lap_live` is a fact about our state machine, never a query against AdSanity — which matters because AdSanity's REST filter checks only `_end_date` and would report a future-dated ad as present.
- Publication is a side effect of a transition, so it happens at step 5 of `apply()`, before the status write. A failed publish leaves the campaign in `lap_review` with an error, not marked live with nothing behind it. See [ADR-0008](0008-explicit-transition-table.md).
- A newly created provider object starts as a draft and its ID is immediately checkpointed on both the creative and campaign. Required fields are then written and read back exactly before the object becomes published. A later failure therefore leaves a non-rendering object that retry reconciles rather than duplicates; a one-sided stale pointer is not trusted to rewrite an existing ad.
- Two hard-won facts about the target, both verified in its source and recorded in [adsanity-integration.md](../adsanity-integration.md): **there is no cron** — scheduling is a read-time `meta_query`, so an ad missing either date key is invisible everywhere rather than merely expired; and **`AdSanity_Ads_CPT::save_post()` returns immediately for programmatic writes** because it requires `$_POST['ads_nonce']`, so there is no sanitization safety net at all. The publisher therefore re-reads every key it wrote and asserts it back. That read-back is the only validation in the pipeline.
- A second ad provider is possible later behind the same interface. Not planned, and not a reason this decision was made.

## Alternatives rejected

**AdSanity as the system of record.** No representation for a campaign, for review state, or for the grouping of several ads into one purchase. The review workflow would have to be encoded in ad post meta on posts that must not be published yet.

**Writing AdSanity meta directly from campaign code.** Faster, and it makes the next AdSanity update a repository-wide grep. The CI boundary check exists specifically because this shortcut is the one someone reaches for at speed.

**Routing writes through `Adsanity\Meta_Data`.** Its hooks drive nothing we need, and core `update_post_meta()` bypasses the wrapper anyway. Depending on an undocumented internal class to store data is worse than depending on WordPress. Revisit only if an add-on ever starts relying on those hooks.

**Forking AdSanity.** Loses licensed updates, and puts ad *delivery* — a solved problem we have no interest in owning — inside our maintenance surface.
