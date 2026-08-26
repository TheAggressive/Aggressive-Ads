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

A second decision followed: a revision whose bytes are unchanged is classified
`text_only` and gets a one-click diff review rather than the full creative
screen. The classification is derived from a SHA-256 comparison server-side and
must never be client-supplied — that is the property that keeps it a review lane
rather than an exemption.

**Built:** the asset and assignment tables (db 14), and the backfill that fills
them (db 15). Nothing on the serving path reads them — native fill still selects
Campaigns and Creative posts — so a half-finished backfill cannot blank an ad
slot.

**Next:** the portal and review screens read assignments instead of creative
post meta, with lazy self-healing through `Creative_Assignment_Migrator::migrate_one()`
for any campaign the backfill has not reached. After that, the coverage service
and many-creatives-per-placement.

The original next step, now done, was the DDL for the revision and assignment
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
