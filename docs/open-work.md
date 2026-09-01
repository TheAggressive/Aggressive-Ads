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

1. ~~Server-to-server ingestion.~~ Shipped. `allow_s2s` now has a reader:
   `POST /aggr/v1/conversions/server`, authenticated by a scoped, revocable
   organization credential issued and revoked through
   `/aggr/v1/conversion-credentials` behind `aggr_manage_settings`.

   **A second route rather than a mode of the browser one**, and that separation
   is the security property rather than tidiness. This route accepts a value and
   a currency; `/conversions` has no such parameter at all, and
   `Conversion_Recorder::record()` has no argument one could arrive through. "An
   anonymous browser may never state what its outcome was worth" is therefore a
   fact about the URL space instead of a conditional somebody widens later.

   Two decisions worth keeping:

   - **Value and currency are stated together or not at all**, and a currency
     that disagrees with the definition is refused rather than converted. This
     plugin holds no exchange rate, and two currencies under one definition make
     every total it produces a meaningless sum.
   - **Organization 0 is not a wildcard for a credential.** An org-0 definition
     accepts a conversion from any campaign because the visitor reporting it is
     anonymous; a credential never is, so one with that scope could report
     against every advertiser on the site.

   ~~What is still not built: a staff screen.~~ Shipped, on the Conversions
   screen the definitions already use: issue, list and revoke, behind
   `aggr_manage_settings` and through the same three routes. Nothing was added
   to the write path — `Conversion_Credential_Manager` remains the only place a
   credential is decided.

   Three decisions worth keeping:

   - **The `allow_s2s` toggle shipped with it, and had to.** The flag was
     stored, validated and exposed over REST from the first day and no control
     ever set it, so every definition a person could create refused every server
     report. A credentials screen without it issues secrets that authenticate
     and are then refused — the shape `ConversionAttributionTest` already
     records for the flag itself.
   - **The list is composed on the server**, not in the browser: the scope's
     name, the two timestamps in the site's timezone, and `live`. A browser
     rendering its own locale would disagree with the audit log beside it, which
     is the one comparison this list exists to support during an incident, and a
     screen deciding for itself what a revocation timestamp means would be a
     second rule to keep in agreement with the one that refuses the report.
   - **The secret is rendered once and never refetched.** The create response
     carries the id and the plaintext; the row a person then reads comes from
     the index route, which has no secret to give. "We cannot show you the token
     again" stays a fact about the code rather than a promise the interface
     makes.

   - **Both lists are DataViews**, through the shared `aggr-dataviews` bundle
     the organization roster already ships — `Shared_Assets::register()`, and
     the stylesheet enqueued by the screen itself because a script dependency
     does not bring one. Sorting is the reason rather than consistency: a
     credential list is read during an incident, and "what is live", "what has
     never been used" and "what did we cut off" are sorts and filters over
     columns. Both date columns sort on the stored timestamp and render the
     server's formatted string, so July does not sort after August. The
     definitions table converted with it — one screen must not have two list
     idioms — and its archive button became a row action.

   Only active organizations are offered, because the manager refuses an
   inactive one, and organization 0 cannot be typed at all — the screen offers a
   list rather than a number for that reason.
2. ~~A staff screen.~~ Shipped: Advertising → Conversions, behind
   `aggr_manage_settings`, creating and archiving definitions through the same
   REST routes. It shows the reporting key a page needs; it does not yet show a
   snippet to paste, because no client snippet exists to paste.
3. **Reporting surfaces.** The `conversions` column is populated and no screen
   reads it. That is P14's, not a gap here.
4. ~~Refusal counters.~~ Shipped. Site Health already answered "can conversions
   be recorded, and are they" from data the site had; it now answers *why not*,
   in words, from `Workflow\Conversion_Metrics`.

   **The premise this entry rested on did not survive being checked.** It said a
   counter means a write per refused request on a public unauthenticated
   endpoint — a cost an attacker chooses rather than the site — and offered two
   compromises: count only where a persistent object cache exists, or sample.
   Both were rejected, and the reasons are worth keeping because they are the
   reasons somebody would re-propose them.

   - **The endpoint rate-limits before attribution.** A refusal that reaches the
     counter has already passed a per-client bound of 60 an hour and already
     paid for a token parse and an indexed definition read. The volume is chosen
     by the limiter, not by the caller.
   - **Sampling optimises for the volume the limiter already prevents.** The
     question being asked is "why is this integration recording nothing", which
     is usually answered by a count of three. A sample shows zero for a real
     problem.
   - **"Only with an object cache" is absent exactly where debugging is
     hardest**, since most installs do not have one, and adds a branch whose
     untaken half nobody tests.

   What made it safe rather than merely affordable was **buffering the count and
   writing it once on `shutdown`**. `ConversionRecorderTest` asserts that an
   unknown definition costs exactly one indexed read and nothing else, and the
   first version of this — writing inline — failed it. The test was right: the
   cheapest refusal is the one an attacker repeats, so it is the one that must
   stay cheap. Refusing now costs no query at all on the path the client waits
   on, and a request refusing five things writes once.

   Two properties to preserve if this is touched:

   - **The counts inform the description and never the status.** They are
     approximate — one option, read-modify-write, so concurrent requests lose
     updates — and a status is what makes somebody act. Good-or-recommended
     stays derived from the ledger and the rollup, which are exact.
   - **`ACCEPTED` is not counted.** The ledger is the exact record of what was
     accepted, and an approximate second number beside it is the beginning of
     two answers to one question.

   The option is bounded by `Conversion_Attribution::reasons()` on both write
   and read, so no caller can grow it by inventing a code and no reader can
   print a reason the interface has no label for.

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

1. ~~An individually paused assignment is resumed when its campaign resumes.~~
   Shipped. `aggr_creative_assignments.operator_paused` is the ownership flag
   this entry said it needed, added at schema 22.

   **The flag was necessary and a cleverer rule was not available**, which is
   why this sat open rather than being guessed at. Both kinds of pause leave the
   identical row, so protecting every `paused` assignment would have stranded the
   ones the campaign paused — the worse failure of the two.

   Three decisions worth keeping:

   - **Set on the way in and cleared on the way out.** Resuming an assignment by
     hand gives it back to its campaign. A flag that survived the resume would
     pin the row — live for ever, ignoring a campaign since paused.
   - **A terminal projection still wins.** An operator's pause says "not now",
     not "never mind what happens to the campaign", and a row left `paused` under
     a cancelled campaign is a candidate the engine keeps considering for a
     campaign that has ended.
   - **The flag is written on the same statement as the status**, because it is
     the record of who set that status. A row that said `paused` without it would
     be resumed by the next transition, which is the whole defect.

   Migration 22 defaults every existing row to 0, and that is the true answer
   rather than a convenient one: before the column there was no way to pause one
   assignment and have it stay paused, so no historical row can have been in that
   state. Unlike `viewables`, where zero would have meant "nothing was seen"
   instead of "nobody was counting".
2. ~~Nothing asserts that a fixture's status came from production code.~~
   Shipped as `bin/ci/check-delivery-fixtures.mjs`, in `lint:files`.

   **Narrower than the rule this entry used to propose**, and deliberately. "A
   delivery test may not write `Assignment_Rules::LIVE` itself" sounds right and
   is wrong: all seven tests that exercise delivery write it, and most of them
   are entitled to. A test of the decision pipeline is supposed to hand it a
   candidate row — that is what `DecisionPolicyInputsTest` exists to keep
   honest. Enforcing the broad rule would have meant rewriting five test
   fixtures to drive real campaign transitions, making them slower and coupling
   them to a projection they are not about.

   Only two files claim that *something else* set the status:
   `AssignmentProjectionTest`, which drives a real transition and asserts the
   row that comes out, and `tests/e2e/seed-live-ad.php`, which throws if the
   fixture does not end up serving. Both already use the constant only to
   assert. The guard keeps it that way, so the next person debugging a flake in
   one of them cannot quiet it by supplying the answer.

   Two things it had to get right, both found by running it: a docblock quoting
   the forbidden line verbatim is prose and not a violation, and a renamed
   protected file must fail rather than pass over nothing.

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

## `Creative_Repository` split, and what is left of it

Done, and recorded because the *reasoning* is what the next person needs, not
the fact.

The file hit the 1000-line hard fail for the second time. The replacement
lifecycle moved out — `change_state`, `requested_at`, `pending_replacement_id`,
`replacements_for_campaign`, `reject_replacement`, `activate_replacement` and
the two advisory locks — taking it from **981 to 799**, under the 800-line
warning as well as the hard fail.

**It moved into `Creative_Revision_Repository` rather than a new class**, which
was not the plan this entry originally proposed. That class was itself split out
of `Creative_Repository` the first time the limit was hit, it owns "how does this
creative relate to the ones before it", and it already wrote half of the
replacement lifecycle: `create_pending_text_revision()` sets the change state
that `change_state()` reads. The two halves of one workflow were split across two
files, which is worse than either arrangement, so a third class would have made
it three.

Three things worth knowing:

- **`change_notes()` stayed behind.** `META_CHANGE_NOTES` carries two decisions —
  a refused replacement and a turned-down creative — and only the first moved.
  A shared reader belongs with the record, not with one of its readers.
- **`replacement_target_id()` stayed** because `is_active()` reads it, so the
  moved code calls back through the injected `Creative_Repository`. The
  dependency runs one way: review state depends on the creative record, never the
  reverse.
- **`CreativeRepositoryLockTest` was renamed** to `CreativeRevisionLockTest`. A
  test file named for the class it no longer exercises is how the next person
  looks in the wrong place.

The move also turned up an untested branch it was carrying: `activate_replacement()`
verifies its own metadata writes and rolls them back when they did not land, and
collapsing that verification to `true` changed no test. Every existing test took
the path where the writes succeed. It now has one that injects a swallowed write
through `update_post_metadata` and asserts the live ad is still serving — because
the alternative is a campaign whose current creative is archived and whose
replacement is not running, which is an advertiser paying for a blank slot.

**The attachment cluster has not moved**, and does not need to yet:
`has_attachment`, `attachment_id`, `attachment_url`, `attachment_file`,
`set_attachment_id`, `mark_attachment_as_creative`,
`ids_promoted_with_private_file`, `backfill_creative_attachment_marks` and
`set_attachment_alt_text` — about 190 lines about the Media Library copy of the
artwork rather than the creative record, called from 11 files. It is the obvious
next seam if the file grows again.

## Nothing else is open

Every other entry that was here has shipped or been closed. That is the intended
resting state, not a sign the file is unused — an entry is added the moment work
is started and understood but not finished, and deleted the moment it ships.

The last one closed was the cold-container browser flake, which turned out not
to be about cold containers: `wp-login.php` steals focus 200ms after load, a
`fill()` in flight when that lands loses its value, and an empty `required`
password makes the browser refuse to submit at all. Diagnosed from the trace's
screencast frames and fixed in `tests/e2e/admin-login.ts`; the durable half is
in [known-issues.md](known-issues.md).

The last one closed was P2, the creative model. Its design, decisions and the
defects found building it are in
[platform-p2-creative-model.md](platform-p2-creative-model.md); which phase built
what is in [platform-implementation-progress.md](platform-implementation-progress.md).

P2's one unmet exit criterion is not P2's: "one eligible **approved** assignment
per required combination" is a *delivery* threshold, and the contract gives
delivery to P3. `Workflow\Coverage_Service` already defines the states it will be
expressed over, so P3 adds a stricter threshold rather than a second meaning of
eligible. It is tracked as P3 scope, not as work left behind here.
