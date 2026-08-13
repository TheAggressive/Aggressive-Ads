# ADR-0026 — We own serving; AdSanity remains an adapter until cutover

**Status:** Accepted — 2026-08-12; dual-write and adapter superseded by [0031](0031-native-is-the-only-publisher.md) — 2026-08-13; one-winner fill amended by [0032](0032-equal-rotation-counts-follow-the-fill.md) — 2026-08-13; module kill-switch amended by [0033](0033-native-delivery-is-not-a-staff-module.md) — 2026-08-13

Supersedes the serving-disinterest in [0006](0006-adsanity-is-downstream-publish-target.md)
("ad delivery — a solved problem we have no interest in owning"). Does **not**
supersede 0006's decision that AdSanity is a downstream publish target, not
the system of record.

Amends [0023](0023-settings-and-module-flags.md): Inventory is the placement
catalogue, not an AdSanity-only screen.

## Context

The portal already owns campaigns, creatives, review, and the clock that
moves approved → scheduled → live → complete. The public site still asks
AdSanity which creative to show. That split is why a paused campaign can
ride out a CDN TTL, and why "we billed for a campaign nobody ever saw"
is an AdSanity date-meta failure rather than a fact in our state machine.

The suite direction is a product that can **serve** ads. Phase D is the
LAAO-site cutover. This phase builds the serving path so cutover is a
template swap, not an invention.

## Decision

**The live set is ours.** A campaign in `aggr_live` with an active creative
and an active placement is eligible for fill. There is no native `ads` CPT
and no second projection to keep in sync. `Ad_Provider_Interface` stays
bound to the AdSanity publisher until Phase D stops dual-write.

**Native fill is always on** ([ADR-0033](0033-native-delivery-is-not-a-staff-module.md)).
The block, shortcode, PHP helper, fill route, beacon, and click hop stay
registered. House policy and fill TTL remain Delivery settings; they do not
turn serving off.

**Inventory stays registered when native delivery is on**, even if the
AdSanity adapter is off. Adapter-off hides the ad-group mapping fields, not
the catalogue. House creative, size, and slug live on the placement.

House creative is placement meta (attachment id, click URL, alt), not a
sixth post type. Size is the existing `_aggr_size`. The public slot id is
the placement's `post_name`.

Until [0032](0032-equal-rotation-counts-follow-the-fill.md), one placement
returned at most one paid creative: the live campaign with the lowest post id.
Equal rotation among live campaigns is now the fill rule. Weighted rotation
is still a later decision.

Delivery settings in this phase: fill cache TTL, house policy
(`when_empty` | `never`). A provider dropdown that does not switch the
publisher is not shown. Tracking settings: event retention days.

## Consequences

- Fill reads campaign status. Pause, complete, and reject do not need a
  native unpublish write — they need a cache delete (ADR-0027).
- AdSanity publish/unpublish effects still run, so the current public site
  does not go dark while native fill is built.
- Native delivery cannot be turned off from Settings
  ([ADR-0033](0033-native-delivery-is-not-a-staff-module.md)). Pause and
  deactivate still take ads down per campaign and per slot.

## Alternatives rejected

**Replace `Ad_Provider_Interface` with a native publisher now.** Would stop
writing AdSanity on approval and black out the LAAO site before Phase D.

**A native ads CPT mirroring AdSanity.** A second copy of the live set to
drift from `aggr_live`.

**Hiding Inventory when the AdSanity adapter is off.** House ads and slugs
have nowhere to live, so native fill cannot be configured without the
adapter we are trying to leave.
