# ADR-0007 — Placement→ad-group mapping is explicit data, keyed on term ID, and fails closed

**Status:** Accepted — 2026-08-08

## Context

Approval has to answer one question per placement: *which AdSanity `ad-group` term does this ad belong to?* Get it wrong and the ad publishes into the wrong slot on a public website, or into no slot at all.

The live groups on laartsonline.com, read from the LAAO theme's saved block templates:

| Term ID | Name | Size |
|---|---|---|
| 6 | `728x90 Header` | 728×90 |
| 48 | `720x300 Bottom` | 720×300 |
| 62 | `160x600` | 160×600 |
| **19** | `728×90 Break` | 728×90 |
| 34 | `300x250 Responsive` | 300×250 |

**Term 19's name contains U+00D7 MULTIPLICATION SIGN, not the letter `x`.** Every other term uses `x`. Two terms also share a size (6 and 19 are both 728×90), so size alone does not identify a group either.

## Decision

The mapping is **stored data**: `_laao_ads_adgroup_term_id` on each placement, an integer, edited by staff holding `laao_ads_manage_placements`, seeded to `0` on install.

**Keyed on term ID, never on term name and never on size.** Names are editorial text that a staff member can change in the taxonomy admin at any time without knowing anything depends on it.

`Placement_Mapping::resolve_all( int[] $placement_ids )` runs **before any write** during approval, and:

1. resolves each placement's term ID,
2. verifies the term still exists in `ad-group`,
3. returns a `WP_Error` naming every unmapped or dangling placement if any fail.

**Nothing is written when resolution fails.** No attachment promoted, no ad post created, no status change. The reviewer gets an error naming the placement, and fixes the mapping.

## Consequences

- A fresh install cannot approve anything until mappings are set. Correct: the alternative is publishing into a guessed group. The Ad delivery screen surfaces unmapped and dangling placements before a reviewer discovers them mid-approval.
- Each placement has an independent, placement-scoped form. This avoids partial bulk updates and gives every configuration change its own nonce, verification result, and audit event.
- Provider absence, taxonomy-read failure, and an empty group catalogue make the screen read-only without clearing existing mappings.
- The same rule makes AdSanity's absence a clean failure rather than a partial publish — the check that aborts is the same check.
- Deleting an `ad-group` term dangles a mapping. Caught by the existence check at resolve time, reported as an error, never published into a term ID that no longer resolves.
- Advertisers never see AdSanity terminology. `_laao_ads_adgroup_term_id` is on the never-expose list for advertiser-facing REST responses.
- A name-matching implementation would have worked for four of the five placements and failed on the fifth in a way that reads as a typo rather than a bug. That is the specific incident this ADR exists to prevent, and it is why the U+00D7 detail is recorded in [known-issues.md](../known-issues.md) rather than quietly fixed by renaming the term.

## Alternatives rejected

**Match placement name to term name.** Fails on term 19, and silently — the failure surfaces as an ad in the wrong position, discovered by a customer.

**Match on size.** Terms 6 and 19 are both 728×90. A size match cannot distinguish header from break.

**Auto-create the ad-group term when missing.** Turns a configuration error into a silently created empty group that renders nowhere, and puts write access to a third-party taxonomy inside an approval path. Failing closed asks a human one question once.

**A single global map option.** Same data, further from the placement it describes, and not covered by the placement's own capability checks or audit trail.
