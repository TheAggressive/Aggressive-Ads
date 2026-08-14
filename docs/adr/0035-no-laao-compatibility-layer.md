# ADR-0035 — No LAAO compatibility layer

**Status:** Accepted — 2026-08-13

**Supersedes:** [0022](0022-aggressive-ads-identity.md)

## Context

0022 named the product Aggressive Ads and the prefix `aggr_`. It also required
a versioned rewrite of existing `laao_ads_*` / `lap_*` rows and one-release
aliases (REST namespace, capabilities, hooks, a basename shim) so a LAAO-prefixed
install would keep working.

This plugin has not shipped under that identity. There are no production tenants
holding `laao_ads_*` post types. The maps, shim, dual-fired hooks, capability
mirroring, REST alias, and private-directory rename were leftover from a rename
that never had customers. Keeping them means every new contributor has to learn
a second vocabulary that nothing in the database uses.

## Decision

**Product identity is unchanged from 0022:** Aggressive Ads, `aggr_`, namespace
`Aggressive\Ads`, REST `aggr/v1`, CSS `--aggr-*`, default UI name “Advertising”.

**There is no upgrade path from LAAO identifiers.** Fresh installs write `aggr_`
names. The installer does not read `laao_ads_*` options, rewrite post types, or
rename `laao-ads-private`. Uninstall does not look for the old tables or roles.

Deleted, not aliased:

- `laao-advertiser-portal.php` basename shim
- `Identity_Maps`, `Identity_Rewrite`, `Identity_Migration`
- `Hook_Aliases`, `Capability_Alias`
- REST namespace `laao-advertiser-portal/v1`
- `Private_Storage::promote_legacy_directory()`

Callers use `apply_filters` / `do_action` on the current hook names. `inc/` must
not grow `laao_ads_`, `lap_draft`, `LAAO_Advertiser_Portal`, or
`laao-advertiser-portal` strings. `IdentityHygieneTest` is the gate, with an
empty allowlist.

Schema version 3 (the rewrite step) is removed from the walker. Sites already at
version 5 are unaffected. A site still on version 2 jumps to 4, then 5.

## Consequences

- A database that still holds `laao_ads_campaign` rows will not be rewritten.
  That is acceptable because no such production database exists.
- `phpcs.xml.dist` no longer allowlists `laao_ads` as a prefix.
- Historical ADRs keep the names they were written with. They are a record, not
  a second runtime.

## Alternatives rejected

**Keep the rewrite “just in case”.** Dead compatibility is how a second identity
leaks back into new code. The hygiene test only works if the allowlist is empty.

**Keep the shim, drop the SQL.** WordPress would still list two plugin files and
new clones would keep a LAAO basename in `active_plugins`. One plugin file.
