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
2. ~~A staff screen.~~ Shipped: Advertising → Conversions, behind
   `aggr_manage_settings`, creating and archiving definitions through the same
   REST routes. It shows the reporting key a page needs; it does not yet show a
   snippet to paste, because no client snippet exists to paste.
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

## Assignment status was never projected, and delivery never worked

Fixed, but the shape is worth keeping. `candidates_for_placement()` selects on
`status = 'live'` and reads `attachment_id` off the assignment row, because a
fill must be one indexed read rather than a join back to the campaign and the
creative. That denormalization is only correct while something refreshes it, and
nothing did: `Assignment_Rules::status_for_campaign()` existed, was correct, and
had exactly one production caller — the one-time P2 backfill. Every campaign that
went live afterwards kept its assignments at `draft`, matched no candidate, and
served nothing.

`Assignment_Projection` now runs on `aggr_campaign_transitioned`, and migration
20 repairs rows that froze earlier.

**Every test in the suite was green throughout**, because each one wrote
`'status' => Assignment_Rules::LIVE` into its own fixture — twelve PHP tests and
the browser fixture alike. `tests/e2e/seed-live-ad.php` was the worst of them: it
created the campaign already `aggr_live` and `$wpdb->insert`ed the assignment
already `live` with its attachment already set, so the one test that watches a
real ad in a real browser was only ever testing the renderer. It now starts the
campaign one legal edge short of live and drives the real transition, and it
throws if the fixture does not end up serving.

Two things still open from it:

1. **An individually paused assignment is resumed when its campaign resumes.**
   Terminal states are protected; `paused` is not, because nothing distinguishes
   "paused with its campaign" from "paused on its own". Protecting it would strand
   assignments paused by a campaign pause, which is worse. It needs an ownership
   flag on the row, not a cleverer rule.
2. **Nothing asserts that a fixture's status came from production code.** The
   rule that would have caught this — a delivery test may not write
   `Assignment_Rules::LIVE` itself — is a guard `bin/ci/` could enforce, in the
   spirit of the other structural checks.

## The ad slot collapses when unsold, and there is no way to keep the space

`Assignment_Projection` made delivery work; this is the behaviour a publisher
notices next. A slot whose first fill returns no creative and no house removes
itself, wrapper and all, rather than leaving a bordered rectangle of nothing on
the page.

Two decisions worth keeping:

- **Only the first fill collapses.** A rotation that comes back empty leaves the
  previous ad up. A slot vanishing out from under a reader mid-page is a far
  worse shift than one that happens before they have started.
- **The server still renders the slot.** It could not decide otherwise without
  querying candidates per slot per page render, and a cached page would then
  bake in "no ads" until the cache expired — the same class of problem as a
  token in cached HTML. The decision stays at fill time, where it is per
  request.

What is not built:

1. **No way to reserve the space deliberately.** Collapsing is unconditional.
   A fixed-layout page that wants the box held open — or a publisher who would
   rather show a house ad than a gap — has no option to say so. A
   `collapseWhenEmpty` attribute defaulting to true is the obvious shape; nobody
   has asked for it yet.
2. **Without JavaScript the box stays.** The server cannot know whether an ad
   exists at render time, so a no-JS visitor sees the reserved slot and, if a
   house creative is configured, the noscript house inside it. Only a
   render-time decision could fix that, and see above for why there is not one.

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
