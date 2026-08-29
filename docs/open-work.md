# Open work

Deliberately unfinished work, written down so it survives a change of author.

`known-issues.md` records things that are true and will stay true.
`roadmap.md` records which phase builds a planned feature. This file is
narrower: work that is **started, understood, and not done**, with enough
detail that picking it up costs minutes rather than a re-investigation.

Delete an entry when it ships. An entry that has been here through three
releases is either not real or not wanted — say which, in the entry, and then
delete it.

## Nothing else is open

Every entry that was here has shipped or been closed. That is the intended
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
