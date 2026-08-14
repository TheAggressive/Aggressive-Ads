# ADR-0028 — Staff package catalogue is the only writer; campaigns keep snapshots

**Status:** Accepted — 2026-08-12

## Context

Advertisers already select an active, complete package. That selection copies
package id, placements, integer-cent price, and currency onto the campaign.
There was no staff surface to create or edit the catalogue, so packages were
seeded or written as post meta in development. Duplicate `_aggr_is_default`
flags were contained by a first-match read, not by a write rule.

`aggr_manage_packages` already existed on administrators and was listed as a
submenu capability in ADR-0025, waiting for this screen.

## Decision

**Advertising → Packages** (`aggr-packages`) is the only writer. It is gated
by `aggr_manage_packages`. Generic `aggr_package` editing stays `show_ui =>
false`.

Writes go through `Package_Manager` → `Package_Repository::save()`, with
read-back verification and an audit row. There is no delete control: a package
is deactivated. Campaigns that already selected it keep their snapshot.

Assigning the catalogue default **clears every other default flag** in the
same save. Only an active package may be the default. An active package must
include at least one active placement with a valid size, a duration or the
custom-duration flag, a non-negative integer-cent price, and an ISO 4217
currency. Incomplete rows may be stored inactive so staff can finish them
without offering them in `GET /packages`.

Price remains integer cents in the form. The Billing module does not gate this
screen: catalogue price is not checkout.

## Consequences

- Changing a package's price the next morning does not rewrite live or draft
  campaigns that already selected it.
- Reviewers still do not see Packages. Advertisers still never reach wp-admin.
- Seeded catalogues remain valid; the screen is the supported way to change
  them after install.

## Alternatives rejected

**Delete.** Orphans `_aggr_package_id` on campaigns and invites a "who is
using this?" query on the write path. Deactivate is reversible and leaves
history intact.

**REST writes.** The staff mapping and settings screens are HTML forms with
per-object nonces. Packages are configuration, not a portal API.

**A provider/billing dropdown on this screen.** Catalogue price is stored
whether or not anyone is charged. Pretending this is checkout contradicts
ADR-0023.
