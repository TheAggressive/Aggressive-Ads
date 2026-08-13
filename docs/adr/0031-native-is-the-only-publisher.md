# ADR-0031 — Native delivery is the only publisher; Inventory is the placement catalogue

**Status:** Accepted — 2026-08-13; module kill-switch amended by [0033](0033-native-delivery-is-not-a-staff-module.md)

Supersedes [0006](0006-adsanity-is-downstream-publish-target.md) (AdSanity as
publish adapter), [0007](0007-placement-mapping-is-explicit-data.md)
(placement→ad-group mapping), and [0015](0015-adsanity-contract-stub-for-ci.md).
Amends [0026](0026-native-delivery.md): there is no dual-write and no adapter
module. Amends [0023](0023-settings-and-module-flags.md): `adsanity_adapter` is
removed; Inventory is always registered; `native_delivery` is always on
(amended by [0033](0033-native-delivery-is-not-a-staff-module.md)).

Does **not** supersede 0006's decision that this plugin owns the campaign
domain, nor 0026's decision that the live set is `aggr_live` rather than a
second ads CPT.

## Context

Approval dual-wrote AdSanity `ads` posts so the LAAO theme's group blocks would
keep rendering. Native fill already serves from `aggr_live`. Two publishers
meant a paused campaign could still show in AdSanity until a date-meta rewrite
landed, and Inventory was a mapping table onto someone else's taxonomy instead
of a place to create slots.

Staff need to add placements. Size cannot be identity: header and break are
both 728×90. AdSanity's size map was a third-party option plus a filter.

## Decision

**There is no downstream ad CPT.** `Ad_Provider_Interface` is implemented by
`Integration\Native\Publisher`. Publish, unpublish, pause, and resume bust the
fill cache and return success. Fill reads campaign status. Creative replacement
swaps our records; the provider does not rewrite a foreign post.

**Approval no longer resolves ad groups.** `GUARD_MAPPINGS_RESOLVE` is removed.
`GUARD_PUBLISHED` is removed from the clock: an approved campaign is already in
our live set once time says so. The validator still runs at approval.

**Advertising → Inventory is the placement catalogue.** Staff create and edit
placements (name, public slug, size, active, house creative). There is no
delete: deactivate, same as packages (ADR-0028). Generic `aggr_placement`
editing stays `show_ui => false`.

**Size is `{width}x{height}` with ASCII `x`.** Staff pick a common IAB size or
enter custom width and height. Two placements may share a size. The catalogue
of common sizes lives in `Domain\Ad_Sizes`. Custom sizes use
`Campaign_Rules::parse_size()` plus the upload pixel cap. Creative upload still
requires an exact pixel match.

**`native_delivery` is always on.** It is not a Modules checkbox and cannot
be saved off ([ADR-0033](0033-native-delivery-is-not-a-staff-module.md)). The
AdSanity adapter module is gone.

Orphan `_aggr_adgroup_term_id` meta is not read. It is not migrated away.

## Consequences

- The LAAO theme must embed `aggr/placement` (or the PHP helper / shortcode),
  not AdSanity group blocks. That theme change is outside this plugin; without
  it the public site shows empty reserved slots until the templates swap.
- CI no longer ships an AdSanity contract stub. AdSanity identifiers are
  forbidden everywhere in `inc/` and `templates/`.
- `aggr_publish` still gates approval. The constant name stays; it never meant
  "write AdSanity" at the capability string.

## Alternatives rejected

**Keep dual-write until the theme is swapped.** The user asked for this plugin
to be the source of truth. Dual-write is how a pause fails to take the ad down.

**Size as the placement identity.** Header and break are the same size and
different slots. That is why ADR-0007 keyed mapping on term ID, and why the
catalogue keys on the placement post.

**Free-text size labels ("Leaderboard").** Upload and fill need pixels. Labels
belong on the common-size dropdown, not in storage.

**A native `ads` CPT.** A second copy of the live set, which is the defect
0026 already rejected.
