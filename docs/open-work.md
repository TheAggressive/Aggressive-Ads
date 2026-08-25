# Open work

Deliberately unfinished work, written down so it survives a change of author.

`known-issues.md` records things that are true and will stay true.
`roadmap.md` records which phase builds a planned feature. This file is
narrower: work that is **started, understood, and not done**, with enough
detail that picking it up costs minutes rather than a re-investigation.

Delete an entry when it ships. An entry that has been here through three
releases is either not real or not wanted — say which, in the entry, and then
delete it.

## P2 — creative model

Started 2026-08-25. The ownership decision the contract requires before schema
work is recorded in
[platform-p2-creative-model.md](platform-p2-creative-model.md#decision-everything-reviewed-belongs-to-the-revision):
the revision owns the bytes, click URL and alternative text; the assignment owns
weight, window and status; the asset owns identity.

Nothing is built yet. The next step is the DDL for the revision and assignment
tables in [data-schema.md](data-schema.md), derived from the P3 read contract's
lookup — line item, placement, status and delivery window — rather than from the
shape of the current Creative post.

## Nothing else is open

Every entry that was here has shipped or been closed. That is the intended
resting state, not a sign the file is unused — an entry is added the moment work
is started and understood but not finished, and deleted the moment it ships.

The last one closed was the cold-start flake in the reviewer-queue browser test,
which stopped reproducing. What was learned eliminating three of its four
candidate causes moved to [known-issues.md](known-issues.md), along with the
instruction to pull the Playwright trace rather than guess if it returns. Open a
new entry here when it does.
