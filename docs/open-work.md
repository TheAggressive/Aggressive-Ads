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
4. **Refusal counters.** Site Health now answers "can conversions be recorded,
   and are they" from data the site already has — definitions that accept
   reports, and yesterday's clicks against yesterday's conversions. What it
   still cannot answer is *why* a report was refused.

   `Conversion_Attribution` keeps those reasons apart, because an invalid
   lineage is abuse or a bug and an out-of-window report is usually a window set
   too short. Counting them is the part that is not built, and the obstacle is
   real rather than effort: **a refusal writes nothing**, so a counter means a
   write per refused request on a public unauthenticated endpoint — a cost an
   attacker chooses rather than the site. A persistent object cache would make
   it cheap and most installs do not have one, so the honest options are to
   count only where a cache exists and say "not measured" everywhere else, or
   to sample. Neither has been decided.

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

## A creative added to a running campaign is now reviewable

`Publisher::publish_campaign()` promotes artwork on the transition *into* a
published state and promotes everything on the campaign at that moment. A
creative added afterwards missed it: the campaign had no publish transition
left, `EFFECT_RESUME` only busts the fill cache, and the only per-creative
approval was for *replacements*. The creative stayed unpublished for ever, had
no attachment, and the decision engine refused it with
`eligibility_missing_attachment` — correctly, and with nothing in wp-admin able
to change it.

`Workflow\Creative_Approval` is that missing decision, surfaced on the existing
**Advertiser updates** tab through its own counter and offered on the campaign
detail.

Two things worth keeping:

- **`_aggr_review_state` is not the approved signal and has not been for a long
  time.** Promotion does not touch it; only the replacement path maintains it.
  So a creative that has been serving for weeks still reads `pending`, and
  `has_attachment()` is the honest question. Anything new that needs to ask
  "is this approved" should ask that.
- **Two counters, not one.** Replacements and never-published creatives are both
  work waiting on the same tab, but they are recomputed from different sources.
  Summing them into one meta key would mean approving a replacement wiped the
  other kind's contribution and the queue lost campaigns it had been showing.

What is not built:

1. ~~No reject.~~ Shipped: a reviewer can turn one down with a reason, which is
   required and stored. The creative leaves the queue *and* its assignment is
   retired, so the decision engine stops considering a candidate it must always
   refuse — retirement is terminal, so `Assignment_Projection` cannot revive it.

   ~~The advertiser still cannot read the reason.~~ Shipped: the portal card now
   states which of three things is true — running, waiting for review, or not
   approved — and a turned-down creative carries the reason it was given. Before
   this, a rejected creative rendered identically to an approved one: same card,
   same preview, same Update action, and nothing anywhere saying it would never
   be served.

   The reason is read through `Creative_Approval::rejection_notes()` rather than
   `Creative_Repository::change_notes()`. That meta key carries two different
   decisions — a refused *replacement*, written on the replacement revision, and
   a turned-down creative, written on the creative — and a reader taking the raw
   value is correct today only because `is_active()` happens to filter
   replacement revisions out first. Pairing the reason with the decision belongs
   to the class that owns the decision.
2. ~~No notification.~~ Shipped: `Notification\Creative_Mailer` tells the rest
   of the review team, once, with a link to the tab the campaign is actually
   listed on.

   **Not a `creative` kind on `Request_Mailer`, which is where this entry used
   to say it belonged.** That class decides whether a retry may still deliver by
   asking whether a *campaign request* is still open, and keys its receipt on a
   request revision counter; a creative answers neither question, so sharing it
   meant branching `still_pending()` and `message()` on a kind that takes the
   other path through both.

   The design changed once on contact with `Edit_Window`: a running campaign is
   editable only by `REVIEW_CAMPAIGNS`, so the uploader is always *inside* the
   recipient set. The actor is therefore excluded, and a lone reviewer who
   uploads their own artwork is silence rather than a delivery failure.

   Two of its six tests first passed for the wrong reason, which is recorded in
   [testing-strategy.md](testing-strategy.md).

## `Creative_Repository` is one method away from the file-length gate

Not a defect, and not urgent — but it is the reason a small change had to be
placed carefully rather than obviously, so it is worth someone knowing before
they meet it the same way.

The file is **981 lines** against a 1000-line hard fail. Adding a twenty-line
accessor to it fails `lint:files`, which is the gate working: at that size the
answer is to split by responsibility, not to shave the comment.

Two coherent seams exist, and **both are coupled to `unpublished_for_campaign()`**,
which asks `is_rejected()` and `has_attachment()` in the same loop. Whichever
cluster moves, that method has to move with it or the old class ends up
depending on the new one:

1. **The attachment cluster** — `has_attachment`, `attachment_id`,
   `attachment_url`, `attachment_file`, `set_attachment_id`,
   `mark_attachment_as_creative`, `ids_promoted_with_private_file`,
   `backfill_creative_attachment_marks`, `set_attachment_alt_text`. About 190
   lines, and a genuinely different subject: the Media Library copy of the
   artwork rather than the creative record. 11 files call into it.
2. **The decision cluster** — `change_state`, `change_notes`, `requested_at`,
   `reject_replacement`, `reject_creative`, `is_rejected`, the change locks.
   Smaller, and closer to what recent work has been touching.

Neither has been done, because a repository split is its own change and does not
belong inside a feature. It is written down here so the next person to hit the
gate finds the analysis instead of repeating it.

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
