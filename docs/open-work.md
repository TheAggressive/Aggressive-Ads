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

The portal and review screens now read assignments, healing lazily through
`Creative_Assignment_Migrator::migrate_one()` for any campaign the backfill has
not reached. A Site Health check reports creatives with no assignment, and
distinguishes a backfill still running from one that finished and left rows
behind.

They read **structure** from the assignment and **values** from the revision.
The denormalized `click_url` / `alt_text` columns are a snapshot whose
correctness rests on the source being immutable — true for serving, not yet true
for editing, because an advertiser editing a draft still updates post meta in
place. That distinction disappears when the write path creates a revision per
edit, and `Assigned_Creatives` says so.

The write path now freezes a creative at **approval**, not at creation. A draft
edits in place; an approved creative is revised, its predecessor preserved with
the text a publisher actually signed off. `Workflow\Revision_Policy::is_frozen()`
is the single authority and every write site asks it.

`Campaign_Change_Manager` used to call `set_click_url()` on an approved, serving
ad when staff approved a destination change — the exact mutation the ownership
decision exists to prevent, arriving through a door marked "approved by staff".
It now revises.

**A defect in the shipped backfill was found here and fixed.** `chain_root()`
walked `_aggr_replaces_creative_id` backward, and `activate_replacement()`
deletes that key the moment a replacement goes live, so on real data every
approved revision looked like its own root and would have been given its own
asset. `Creative_Revision_Repository::predecessor_of()` now reads the durable
forward link instead — the revision chain moved out of `Creative_Repository`
when the file-length guard fired on it.

An advertiser can now correct a destination or description without
re-uploading artwork. `Creative_Change_Manager::request_text_change()` stages a
pending revision carrying the predecessor's bytes, so it classifies as
`text_only` from the two checksums matching, and **the live ad keeps serving
until a reviewer decides** — a typo fix must not let an advertiser take their
own paid placement off the site. Approval and rejection are the existing
replacement flow unchanged, because `Creative_Promoter::promote()` is already a
no-op for a revision that carries an attachment.

The review screen now says **"Artwork unchanged — only the text differs"** on a
`text_only` revision and hides the size comparison, so a reviewer sees the one
line that changed rather than four that did not. The flag is the server-derived
one; the screen never computes it.

`Workflow\Coverage_Service` is the one definition of whether a creative can
run, and campaign validation now reads it instead of creatives. The source
moved; the answers did not — the twenty existing validator tests pass unchanged,
which is what makes the switch verifiable rather than hopeful.

Classification and threshold are separate. `classify()` names the state;
`covers_for_submission()` says which states count as present on a placement, and
is deliberately looser than `usable` — a wrongly sized creative reports "wrong
size", not "no creative", because telling somebody both points them at the wrong
fix. P3 adds a stricter threshold over the same states rather than a second
meaning of eligible.

**A defect shipped in the portal-reads slice was found here and fixed.**
`Assigned_Creatives::heal()` healed only when a campaign had *no* assignments,
justified by "a partial result means the backfill is mid-campaign". That was
wrong: the backfill walks the creative id space globally, so one campaign's
creatives can sit either side of the cursor and stay that way. A campaign would
have shown some of its artwork and not the rest. Healing is now per creative.

**Next:** remove the upload cap (`aggr_creative_already_exists`) so a placement
may hold more than one creative. Validation is already ready for it — the
coverage model treats a placement as covered once, however many creatives cover
it.

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
