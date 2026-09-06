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

## Nothing is open

Every entry that was here has shipped and been deleted, which is this file's
intended resting state rather than a sign it is unused. An entry is added the
moment work is started and understood but not finished, and removed the moment
it ships — including the reasoning, once that reasoning has a permanent home.

**Deleting an entry is not deleting what it knew.** Four entries were removed
together after P15's first slice, and each left its durable half behind first:

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

Work in flight for P15 is tracked in
[platform-p15-inventory-management.md](platform-p15-inventory-management.md),
which carries its own slice list. A phase in progress belongs there rather than
here: this file is for work that is started and *not covered by a phase
document*, and duplicating a slice list into it is how the two disagree.
