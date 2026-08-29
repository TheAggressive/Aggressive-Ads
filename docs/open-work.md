# Open work

Deliberately unfinished work, written down so it survives a change of author.

`known-issues.md` records things that are true and will stay true.
`roadmap.md` records which phase builds a planned feature. This file is
narrower: work that is **started, understood, and not done**, with enough
detail that picking it up costs minutes rather than a re-investigation.

Delete an entry when it ships. An entry that has been here through three
releases is either not real or not wanted — say which, in the entry, and then
delete it.

## P12 — conversion tracking, storage landed and nothing writes it

`aggr_conversions` and `aggr_rollups.conversions` exist as of schema 18. Nothing
inserts a row, and that is the intended intermediate state: the code that fills a
table ships with the code that reads it, the same staging version 14 used for the
creative model.

Conversion definitions landed with schema 19: the table, validation, staff REST
routes behind `aggr_manage_settings`, optimistic concurrency and an audit trail.
`definition_id` now points at something.

What is defined and not built, in the order it should be built:

1. **The click-through carrier.** `Click_Hop` must append the signed token to
   the destination URL. It sets `Referrer-Policy: no-referrer`, so the landing
   page has no other way to learn the click — which is correct, and is why the
   carrier has to be explicit rather than incidental.
2. **Browser and server-to-server ingestion**, on their own rate-limit bucket
   rather than the beacon's, with a scoped revocable credential for the second.
   The definition's org must be checked against the token's campaign org, and an
   unknown definition and a foreign one must answer identically.
3. **The rollup projection and reconcile** for the new column.
4. **A staff screen.** The routes exist and nothing in wp-admin calls them yet,
   so a definition can only be created over REST.

Scope, boundaries and exit criteria are in
[platform-p12-conversion-tracking.md](platform-p12-conversion-tracking.md).
**View-through attribution is defined there and deliberately not being built:**
it needs the cross-visit identifier P11 declined to invent, and P27 is its gate.
Click-through needs none.

Two traps found building the storage, recorded so they are not rediscovered:

- **`aggr_events` cannot hold a conversion**, for the reason now written into
  `data-schema.md` and asserted by `ConversionLedgerTest`. If that test is ever
  seen failing, read it before changing it.
- **A REST `permission_callback` answers before the workflow does**, so a
  denied request never reaches the manager and never writes the audit row the
  manager would write. That is intended — an unauthenticated probe that audited
  every attempt would be an unbounded write anybody could drive — but it means a
  denial-audit assertion belongs in a manager test, not a route test.
- **This suite cannot prove a table was created by dropping it first.**
  `WP_UnitTestCase` rewrites `CREATE TABLE` and `DROP TABLE` into their
  `TEMPORARY` forms, so a repository's `drop_table()` drops nothing and
  `SHOW TABLES` cannot see what the suite created. `ConversionSchemaTest`
  records what does work, including why it invokes the migration step directly
  instead of through `maybe_upgrade()` — whose option-based lock survives the
  transaction rollback in the object cache and silently disables a later test's
  upgrade.

## Nothing else is open

Every other entry that was here has shipped or been closed. That is the intended
resting state, not a sign the file is unused — an entry is added the moment work
is started and understood but not finished, and deleted the moment it ships.

The last one closed was P2, the creative model. Its design, decisions and the
defects found building it are in
[platform-p2-creative-model.md](platform-p2-creative-model.md); which phase built
what is in [platform-implementation-progress.md](platform-implementation-progress.md).

P2's one unmet exit criterion is not P2's: "one eligible **approved** assignment
per required combination" is a *delivery* threshold, and the contract gives
delivery to P3. `Workflow\Coverage_Service` already defines the states it will be
expressed over, so P3 adds a stricter threshold rather than a second meaning of
eligible. It is tracked as P3 scope, not as work left behind here.

Before that it was the cold-start flake in the reviewer-queue browser test, which
stopped reproducing. What was learned eliminating three of its four candidate
causes moved to [known-issues.md](known-issues.md), along with the instruction to
pull the Playwright trace rather than guess if it returns. Open a new entry here
when it does.
