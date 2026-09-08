# Open work

Deliberately unfinished work, written down so it survives a change of author.

`known-issues.md` records things that are true and will stay true.
`roadmap.md` records which phase builds a planned feature. This file is
narrower: work that is **started, understood, and not done**, with enough
detail that picking it up costs minutes rather than a re-investigation.

Delete an entry when it ships. An entry that has been here through three
releases is either not real or not wanted — say which, in the entry, and then
delete it.

**Say what would change the answer, not just the answer.** That staleness rule
checks whether an entry is still *wanted*; nothing checks whether its reasoning
still *holds*, and the second is what actually goes wrong. An entry that records
a conclusion reads as settled, and nobody re-examines a settled thing — so a
wrong conclusion is protected by having been written down carefully.

It happened here. "Without JavaScript the box stays" said only a render-time
decision could fix it and there could not be one. That was wrong: it conflated
*will a paid ad fill this slot* (needs a per-request candidate query, genuinely
unavailable) with *will a visitor without JavaScript see anything* (needs only
placement configuration, and the server had already computed it). The entry was
five days old, not five months — duration was never the problem. Nothing in it
invited anyone to check the premise.

So an entry that defers on a judgement has to name the condition that judgement
rests on:

- Not "only a render-time decision could fix that, and there is not one" but
  "...while the decision needs a per-request candidate query. If any part of the
  question turns out to be answerable from placement configuration, revisit."
- "The obvious next seam **if the file grows again**" is already the right shape:
  a trigger somebody can observe.
- "Nobody has asked for it yet" is too — the trigger is somebody asking.

Two of the three entries this rule was written from were already correct. The
one that was not is the one that stated a verdict instead of a condition.

## Page coordination has no caller, so it never fires

**What.** Roadblocks, competitive separation and category exclusivity are
evaluated only in `Page_Decision_Coordinator`, which is reached only through
`Decision_Engine::decide_page()`, which is reached only through
`Fill_Service::for_slots()`, which is reached only through
`POST /aggr/v1/decisions`. Nothing in `src/` calls that route: `fill.js` fetches
the per-slot `data-aggr-fill` URL, one request per slot. So for a real visitor
none of the three page rules run, and two campaigns from competing advertisers
can share a page that was configured to keep them apart.

**Why it looks fine.** Every one of those rules is tested, and the tests pass —
`PageCoordinationInputsTest` goes through `for_slots()` directly, which is the
right call for what it is testing and also the reason the missing caller is
invisible from the suite. The route exists, is registered, is rate-limited and
answers correctly. Only nobody asks it anything.

**What stops it mattering today.** `roadblock` and `exclusive_category` are read
from `delivery_settings` and there is no writer: no admin control, no portal
field, and nothing in the REST schema names either key. The line-items route
accepts `delivery_settings` as free-form, so staff *could* set them through the
API and get silent non-enforcement, but nothing invites them to.

**What would change the answer.** This defers on one premise: *that no
configured page rule can reach delivery*. **Revisit the moment either key gets a
writer** — an admin control, a portal field, or a named REST schema entry —
because at that point a publisher can configure a rule that silently does
nothing, which is the failure this codebase treats as worse than an error. The
fix at that point is a decision about the client, not the server: the server
half works, and the question is whether a page with several slots should make
one batch request instead of one request per slot. That is a delivery change
with page-cache and rotation consequences, not a small one, which is the honest
reason it is not being done pre-emptively.

**Cost of leaving it.** Three implemented features that do not run, and a route
whose docblock says it is what "a page actually uses" when no page does.

Found while wiring the viewport and page context through that route, which had
been accepting neither.

## Nothing else is open

Every other entry that was here has shipped and been deleted, which is this
file's intended resting state rather than a sign it is unused. An entry is added the
moment work is started and understood but not finished, and removed the moment
it ships — including the reasoning, once that reasoning has a permanent home.

**Deleting an entry is not deleting what it knew.**

The contextual-targeting entry was removed when the archive case shipped, and it
is worth recording that it came out the way the rule at the top of this file
intends. It deferred on a named premise — *that page context has to be keyed to
a post id* — rather than on a verdict, so revisiting it cost reading two
functions instead of a re-investigation. The premise turned out to be the only
blocker: `get_queried_object_id()` already returned the term, and only the
`is_singular()` guard beside it kept archives out. What it knew is now in
[platform-p8-targeting-rule-engine.md](platform-p8-targeting-rule-engine.md),
beside the fact table a maintainer actually reads.

Four earlier entries were removed together after P15's first slice, and each
left its durable half behind first:

- The delivery denormalization — `candidates_for_placement()` returns the
  *assignment's* columns, so a stage reading a key nothing puts there is the
  defect to look for — is in `CLAUDE.md` and
  [delivery-performance.md](delivery-performance.md).
- **`_aggr_review_state` is not the approved signal**; `has_attachment()` is the
  honest question. Now in [domain-model.md](domain-model.md), beside the field
  itself, where somebody reading the schema will meet it.
- A meta key belongs with the record rather than with one of its readers, which
  is what kept `Creative_Repository`'s constants in place across two splits. Now
  in [architecture.md](architecture.md).
- Every test lesson those entries carried — a fixture that supplied its own
  answer, a verification collapsed to `true` that changed no test, a guard that
  reported success over code it had stopped reading — is in
  [testing-strategy.md](testing-strategy.md), incident by incident.

What the slot does when unsold, with and without JavaScript, and the three
per-slot settings are in [runbook.md](runbook.md), because that is a question an
operator asks rather than a decision a maintainer revisits.

**A phase in progress belongs in its own phase document, not here.** This file
is for work that is started and *not covered by one*, and duplicating a slice
list into it is how the two disagree. P15 was tracked that way through four
slices and closed in
[platform-p15-inventory-management.md](platform-p15-inventory-management.md),
including the two things it deliberately did not do — the paragraph that used to
sit here saying P15 was in flight outlived the phase by exactly one merge, which
is the failure mode this file has to watch for in itself.
