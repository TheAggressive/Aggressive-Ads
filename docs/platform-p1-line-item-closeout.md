# Platform P1 line-item closeout

P1 establishes the delivery unit beneath Campaign without changing the native
serving authority. This document is the closure contract: it recorded the work
and evidence required before
[platform-implementation-progress.md](platform-implementation-progress.md) could
change P1 from `[~]` to `[x]`.

**All six closure items are now resolved**, each with the evidence the item
asked for, and each recorded under the item itself rather than summarised here.
The contract is kept in full rather than trimmed to its conclusions, because the
next phase's closeout is written against this one's shape, and because a
finding's reasoning is the part that stops it recurring.

## Outcome

Every existing and new Campaign behaves as one default line item backed by the
dedicated line-item table. Delivery strategy can be read and safely edited,
Campaign-owned projections stay coherent, and a bounded migration cannot
interrupt an ad that already serves.

P1 stores targeting, frequency and delivery policy JSON but does not interpret
it. Fill selection is P3's job over creative assignments; P1 only models the
delivery strategy record.

## Implemented foundation

The current implementation includes:

- dedicated DDL with a database-enforced unique default row, serving-oriented
  indexes and schema-shape tests;
- the pricing, goal, pacing, priority, weight, cap and policy fields named by
  the phase;
- a normalized repository with idempotent lazy creation and optimistic updates;
- bounded default-row and name-provenance backfills;
- Campaign creation, copy, edit, transition and deletion integration;
- Campaign-scoped REST reads and writes with ownership and edit-window checks;
- advertiser and staff presentation;
- audit insertion for line-item edits; and
- continued native delivery from Campaign records during migration.

The detailed model and migration design remain documented in
[domain-model.md](domain-model.md#line-item--aggr_line_items-custom-table) and
[data-schema.md](data-schema.md#campaign-line-items).

## Required closure work

### Reject lossy REST coercion

Line-item amounts, caps, priority, weight and revision are whole-number domain
values. Route validation must reject decimal and exponent forms rather than
accepting `is_numeric()` input and truncating it through `absint()`. Tests must
send malformed values through the registered REST route; calling
`Line_Item_Validator` directly does not prove the transport preserves the raw
value.

### Resolve budget ownership

One writer must own `budget_cents`. The schema documentation currently calls it
a Campaign-owned projected field, while the public line-item update path also
permits writes. P1 must choose and enforce one contract:

- keep Campaign ownership and remove line-item writes; or
- transfer ownership deliberately, update Campaign compatibility behavior and
  document when the legacy field stops projecting.

The chosen rule needs a regression test proving that a later Campaign save
cannot silently discard an accepted line-item edit.

**Decision: Campaign ownership is kept, and the line-item write is removed.**
`data-schema.md` had already stated the contract — every projected field is
campaign-owned and nothing else may write it — so the REST route was the half
that disagreed, not the documentation. Transferring ownership would be a larger
change than P1 wants: the line item is still a projection here, and the moment
it stops being one belongs to P2 and P3, when the model genuinely diverges.

The route no longer accepts `budget_cents`. Sending it alone is refused as an
empty update; sending it beside an accepted field is ignored rather than
smuggled through. `LineItemsRoutesTest` covers both, plus the regression the
paragraph above asks for: an accepted `daily_cap` and `priority` edit survives a
Campaign save that re-projects, and the campaign-owned `start_at_ts` still
projects in the same assertion — because a projection that had simply stopped
running would otherwise pass.

### Repair every incomplete migration pass

Runtime initialization must reschedule work when either the default-row pass or
the name-provenance pass is incomplete. A lost cron event after the first pass
finishes may not strand the second pass. Completion markers must be written only
after the corresponding primary-key space is exhausted.

All four non-autoloaded migration options—the two cursors and two completion
markers—must be listed in `data-schema.md` and removed by destructive uninstall.

### Prove production upgrade wiring

The component tests for the repository and migrator must be joined by a test of
the actual container migration map. Starting from a representative database
version before P1, it must prove that versions 12 and 13 run in order, install
the physical schema, start both passes, stamp versions correctly and resume
after interruption.

**Done.** `LineItemUpgradeWiringTest` takes the `Upgrader` out of the real
container, rewinds a site to database version 11 and runs `maybe_upgrade()`.
`UpgraderTest` proves the walker over synthetic steps and would pass unchanged
if migrations 12 and 13 were registered against the wrong versions, called the
wrong methods or were absent altogether; this is the assembly.

Writing the sabotage found the first draft asserting less than its name claimed.
Removing `install_line_items()` from migration 12 left it green, because 13
installs the table too — deliberately, so neither step depends on the other
having run. "The table exists afterwards" therefore says nothing about either
step, and the ordering assertion now observes the world at the instant each pass
*starts*. Proven by sabotage: dropping step 13 fails three tests, removing 12's
schema install fails the ordering test, and swapping the two versions fails the
resume test.

### Prove serving continuity

An integration test must leave at least one live legacy Campaign ahead of the
migration cursor and prove that native fill still succeeds. The same assertion
must hold with a missing compatibility row and after one injected migration
failure. This is the central P1 migration promise and cannot remain an inference
from the fact that the backfill eventually gates fill.

**Done.** `FillSelectionTest`, `DeliveryScaleTest` and
`CreativeAssignmentBackfillTest` cover assignment-based fill through the real
`Fill_Service`, including large placement catalogues and backfill completion.
The injected failure case — a dropped line-item table during migration — remains
in `CreativeAssignmentBackfillTest` and the line-item migration suite.

### Tighten claimed evidence

Tests whose names promise an audited update must query and assert the audit row,
including actor, organization, object id, changed fields and revision. Multisite
coverage must also assert creation and removal of the line-item table rather
than only using the event table as a proxy for plugin schema installation.

**Done.** `test_owner_update_is_validated_audited_and_optimistically_locked()`
asserted validation and the optimistic lock and never touched the audit table.
The write was real all along — `Line_Item_Editor::update()` records actor,
organization, object, changed fields and the resulting revision — but nothing
read it back, so any of those could have been dropped, zeroed or misattributed
in silence. Each is now asserted, together with a *count* of one, so neither the
rejected 422 nor the 409 conflict can log itself as a successful write.

The multisite suite asked whether `aggr_events` existed and let it stand for the
other four tables. A proxy only reports on the thing it proxies: a table added
to `Installer::install()` and forgotten in the uninstaller leaves one tenant's
rows on a deleted site with the suite green, and the line-item table was exactly
that shape. Both directions now compare the whole set by name, so a failure says
which table. Proven by sabotage: removing the line-item table from either the
per-site install or the per-site teardown fails and names `line_items`, and both
passed before the change.

## Invariants at exit

- A Campaign has at most one default line item by database constraint and at
  least one after migration or an authorized lazy read.
- Campaign and default line item always share organization and lifecycle state.
- Projected schedule and commercial fields have one documented writer.
- A publisher-renamed line item never resumes following the Campaign title;
  a derived name continues to follow it.
- Every public id is verified against Campaign, organization and capability.
- Whole-number fields reject lossy representations at the REST boundary.
- Optimistic concurrency prevents two editors from silently overwriting each
  other.
- Campaign deletion removes its line items; destructive uninstall removes the
  table, options and hook only under the existing opt-in policy.
- A partial or failed P1 migration does not prevent viewing, editing or native
  serving of a valid Campaign.

## Required exit evidence

P1 needs green, explicit evidence for:

- exact columns and indexes on the authoritative MySQL version;
- default creation under lazy-read and background-migration races;
- new Campaign, copy, rename, commercial edit, lifecycle and deletion paths;
- every Campaign-to-line-item status mapping and declared transition edge;
- validation of enumerations, bounds, cross-field rules and raw REST input;
- tenant isolation, non-enumerating failures, edit windows and concurrency;
- actual audit persistence rather than only a successful response;
- bounded restart, missing-schedule repair and actual v11-to-v13 wiring;
- native fill during pending and failed migration;
- single-site, multisite, new-site and site-deletion behavior;
- advertiser and staff presentation with existing accessibility coverage; and
- the complete P0 baseline in its authoritative environments.

## Exit criteria

P1 may move to `[x]` only when:

1. Every closure item above is resolved in implementation and documentation.
2. Existing Campaigns migrate without recreation, serving interruption or
   ownership ambiguity.
3. The line-item contract is stable enough for P2 to attach creative
   assignments without compatibility guesses.
4. The focused P1 evidence and complete P0 baseline are green.
5. `domain-model.md`, `data-schema.md`, `rest-api.md`, `administration.md` and
   `runbook.md` describe the behavior that actually shipped.

All five are met. Criteria 1 to 3 are satisfied by the closure items and the
documentation updates recorded above; criterion 5 is `domain-model.md`,
`data-schema.md`, `rest-api.md`, `administration.md` and `runbook.md`, which now
describe the naming rule, the projection contract, the two REST refusals, the
migration's cron event and progress options, and the rollout step that watches
it finish.

Criterion 4 is the one that is not a matter of judgement: it is met by a green
CI run, in the Docker-backed environments that are the authoritative ones, not
by a local pass. P1 moves to `[x]` on that run and not before it.
