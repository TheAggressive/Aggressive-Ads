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

**Click-through conversions work end to end.** A publisher defines a conversion,
the click hop carries the signed token to the advertiser's page, that page reports
the outcome, and it lands in the ledger and the rollup. Definitions, the carrier,
browser ingestion, the projection and its reconcile have all shipped.

What is defined and not built:

1. **Server-to-server ingestion.** The definition already carries `allow_s2s`,
   and nothing reads it. It needs a scoped, revocable organization credential —
   its own issue/revoke surface — and it is the one place a reporter may state
   value and currency, bounded by the definition. Until it exists, `allow_s2s`
   is a checkbox that does nothing, which is worth fixing or removing.
2. **A staff screen.** The definition routes exist and nothing in wp-admin calls
   them, so a definition can only be created over REST today.
3. **Reporting surfaces.** The `conversions` column is populated and no screen
   reads it. That is P14's, not a gap here.
4. **Operator counters.** `Conversion_Attribution` keeps refusal reasons apart —
   invalid lineage from out-of-window — exactly as the measurement contract
   requires, and nothing yet counts them into Site Health.

**View-through attribution remains defined and deliberately unbuilt.** It needs
the cross-visit identifier P11 declined to invent, and P27 is its gate.
Click-through needs none.

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
