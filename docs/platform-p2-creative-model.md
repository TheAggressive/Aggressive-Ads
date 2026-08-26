# Platform P2 creative model

P2 replaces the campaign-level assumption that one placement has one creative
with a delivery model that can represent many creatives beneath a line item.
It is the contract P3 will consume, so P2 is complete only when the model,
migration, workflows and read path are stable enough that the decision engine
does not have to reinterpret or repair them.

This document defines the work and exit criteria. It does not claim that P2 has
started or that any item below is implemented.

## Entry criteria

P1 must be complete before P2 implementation begins. In particular, every
existing and new Campaign must have exactly one trustworthy default line item,
the migration must resume after interruption, and ownership of every projected
field must be unambiguous. P2 must preserve the green P0 regression baseline.

## Scope boundary

P2 owns the creative asset, its immutable revisions, and the assignment that
makes a revision available to a line item and placement. It includes status,
weight and delivery dates because those facts must exist before decisioning can
select an eligible creative.

P2 does not implement:

- the P3 decision engine or weighted selection algorithm;
- P4 exact schedule evaluation on the serving hot path;
- P17 experiments, A/B analysis, performance comparison or device preview;
- P18 HTML5, third-party or video handlers; or
- P10 and later measurement semantics.

Native serving remains campaign-based until P3 performs one explicit, tested
cutover. P2 must keep current ads serving while its migration is incomplete.

## Canonical model

The design must distinguish three concepts rather than overloading the current
Creative post:

1. **Creative asset** — the tenant-owned logical artwork and durable identity.
2. **Creative revision** — an immutable snapshot of reviewed bytes and their
   metadata. Approval applies to the exact revision that was reviewed.
3. **Creative assignment** — the relationship that makes one approved revision
   available to a line item and placement, with its own weight, status and
   delivery window.

Before schema work begins, the design must decide and document which record owns
the click URL, alternative text and other mutable delivery metadata. Reusing an
asset must never make an edit for one assignment silently alter another live
assignment. Approved bytes, detected MIME type, dimensions, checksum and file
size are revision facts and may not be edited in place.

### Decision: everything reviewed belongs to the revision

**The revision owns the bytes, the click URL and the alternative text. The
assignment owns only delivery scheduling — weight, window and status. The asset
owns identity and nothing renderable.**

The forcing question is what a publisher is agreeing to when they approve. They
are not approving an image; they are approving *an ad* — this artwork, pointing
at this destination, described this way. A model that lets any of those three
change after approval without re-review is a model where approval means less
than the publisher thinks it does. Repointing a destination URL after approval
is the classic bait-and-switch, and it is the single most valuable thing an
advertiser could do to a publisher on this system.

So the three candidate owners resolve like this:

- **Asset-owned** fails the contract's own constraint outright. One click URL
  shared across assignments means editing it for one live ad silently changes
  another.
- **Assignment-owned** passes that constraint and fails the more important one.
  Per-assignment metadata is mutable by definition, so the reviewed destination
  and the served destination come apart, and the approval no longer describes
  what is being delivered.
- **Revision-owned** satisfies both, and it does so without a rule anyone has to
  remember. Revisions are immutable, so "editing" a click URL is creating a new
  revision. Another assignment still points at the old one, so nothing leaks
  across assignments — not because a copy was made, but because there was never
  anything shared and mutable to leak.

Immutability is what makes reuse safe. That is worth stating plainly, because
the intuitive fix for cross-assignment leakage is to copy the metadata onto each
assignment, and that is the option that quietly breaks review.

**This is continuous with what already ships, not a new restriction.** Today a
click-URL change on a live ad goes through `Creative_Change_Manager::request()`,
which stages a replacement and sends it back for review. P2 renames that
mechanism rather than inventing one: a replacement becomes a revision, and the
`_aggr_replaces_creative_id` / `_aggr_replaced_by_creative_id` chain becomes the
revision history it was already approximating.

**The cost, and what P2 does about it.** A typo in alternative text requires a
new revision and another review, even though no byte changed. Left alone that is
heavy enough that people route around it, so P2 answers it directly rather than
deferring: see *Text-only revisions* below. The answer shrinks the ceremony and
does not touch the guarantee.

### Text-only revisions

A revision whose bytes are byte-for-byte identical to its predecessor is
classified `text_only`. It is still an immutable revision, still requires the
same capability to approve, and still cannot serve until approved — the only
thing that changes is what the reviewer is shown: a diff of the click URL and
alternative text, rather than the full creative-review screen. The live ad keeps
serving the last approved revision throughout.

**The classification is derived, never supplied.** It is computed server-side by
comparing the new revision's SHA-256 against its predecessor's. This is the
whole security property, and it is worth stating as a rule rather than leaving
it to the implementation: a client-supplied "this is only a text change" flag
would let somebody swap the artwork and claim the one-click path. Nothing the
caller sends may influence which review lane a revision lands in.

Two consequences follow, and both are requirements rather than notes:

- A revision identical to its predecessor in **both** bytes and text is a no-op
  and must be refused, not fast-tracked. Otherwise the queue fills with
  revisions that say nothing.
- `text_only` is a property of a revision *relative to a specific predecessor*,
  so it must be recorded on the revision at creation and never recomputed later.
  A predecessor that is superseded or deleted must not be able to change how an
  already-reviewed revision was classified.

This is a review lane, not an exemption. If the distinction ever blurs — if
`text_only` starts meaning "needs less authorization" rather than "shows a
smaller diff" — it has become the thing this section exists to avoid.

**What the assignment owns, and why those are safe.** Weight, start and end
timestamps, and status are scheduling facts, not claims about the ad. A
publisher approving a creative is not approving the hours it runs — the parent
line item and campaign already bound that window, and the invariants below keep
an assignment from expanding it. Changing them cannot misrepresent what is
served, only when.

The assignment model must carry at least:

- line-item, campaign, organization, creative revision and placement ids;
- status;
- positive integer weight;
- UTC start and end timestamps, with zero semantics stated explicitly;
- optimistic-concurrency revision;
- created and updated timestamps; and
- any compatibility key required to make migration races idempotent.

Dedicated custom tables should hold relationships or revision history that are
read on the serving path. The final DDL, indexes and repository boundaries must
be recorded in [data-schema.md](data-schema.md).

## Invariants

The implementation must enforce all of these at the application boundary:

- Campaign, line item, creative, revision and placement belong to the same
  organization and WordPress site.
- The line item belongs to the Campaign named by the operation; clients cannot
  choose or change organization ownership.
- Only a successfully reviewed and approved revision may be delivery-eligible.
- A revision assigned to a placement has server-verified dimensions compatible
  with that placement.
- Required coverage means at least one eligible approved assignment for every
  required line-item and placement combination.
- A second valid creative never makes a Campaign invalid merely because it is
  second.
- Weight is a positive whole number. P2 stores it; P3 defines how it competes.
- Dates form a valid window and cannot expand the authoritative parent delivery
  window without an explicitly documented rule.
- Status changes follow one declared transition table rather than call-site
  conditionals.
- An approved revision is immutable. Replacement creates another revision and
  preserves the record of what was previously approved and served.
- Rejection, supersession, pause, expiry and deletion have distinct meanings;
  none is represented by silently removing history.

Any database foreign keys that are deliberately omitted for WordPress
compatibility must have equivalent write validation and deletion tests.

## Migration contract

Every current Creative must remain recognizable without an advertiser or
publisher recreating it. The migration must:

- attach each legacy Creative to its Campaign's P1 default line item;
- create the revision and assignment records needed to represent its current
  reviewed state and placement;
- preserve Creative ids, private encrypted files, promoted attachment ids,
  review state, click behavior, alternative text, checksums and audit history;
- preserve existing impression and click attribution until later event-schema
  phases deliberately change it;
- use a database-enforced compatibility key so lazy reads and background work
  cannot create duplicate assignments;
- run in bounded primary-key batches with non-autoloaded cursors;
- advance a cursor only after the destination records exist, or after the
  source was concurrently deleted;
- be idempotent, restartable and able to repair a missing cron schedule;
- expose completion and failure state to operational diagnostics;
- behave correctly on every site in a network; and
- leave native serving operational before, during and after the backfill.

Reads needed by advertiser and staff screens may lazily materialize missing
compatibility records, using the same idempotent repository operation as the
backfill. A partial migration may produce a supported mixture of legacy and P2
records; it must not produce a campaign that exists but cannot be viewed,
reviewed or served.

The implementation plan must describe rollback. Rolling code back while new P2
records exist must not delete or corrupt the legacy Creative data still needed
by the previous release.

## Validation refactor

Campaign validation must stop counting Creative posts as though a placement can
have exactly one. One centralized coverage service must answer whether each
required line-item and placement combination has an eligible approved
assignment.

The service must explicitly classify at least these cases:

- pending, approved, rejected and superseded revisions;
- draft, ready, live, paused, completed and cancelled assignments, or the final
  vocabulary selected for them;
- future, current and expired assignment windows;
- wrong organization, Campaign, line item or placement;
- incompatible dimensions or unavailable private/promoted files; and
- a concurrently deleted or replaced record.

P2 may evaluate these rules for validation and presentation. P3 will reuse the
same definitions in its separable eligibility stage rather than create a second
meaning of "eligible."

## Workflows and API

Authorized workflows must cover creation of a new asset or revision, assignment
and unassignment, weight and date changes, pause/resume, approval, rejection,
supersession and deletion where deletion is legally permitted.

Every public operation must:

- use Campaign- or line-item-scoped routes and independently verify every id;
- enforce WordPress capability, organization ownership and edit-window rules;
- return the same non-enumerating response for missing and foreign-tenant
  objects;
- validate raw input without lossy coercion;
- use optimistic concurrency for mutable records;
- either commit multi-record changes atomically or define and test compensating
  behavior;
- rate-limit writes consistently with the existing portal; and
- write an audit event containing actor, organization, affected ids, changed
  fields, state transition and revision.

Advertiser responses must omit storage paths, encryption metadata, internal
review notes, compatibility keys and other persistence-only fields. Staff-only
data must not leak through shared serializers.

## P3 read contract

P2 must expose a bounded, indexed repository query that can become P3's
creative-candidate source. It must not require postmeta joins or one query per
candidate. Its inputs, normalized output shape, ordering guarantees and
visibility rules must be documented before P3 starts.

The required indexes must be derived from the actual lookup: line item,
placement, status and delivery window, with a stable id for deterministic
pagination or ordering. Query plans and cold/warm query counts must be recorded
against realistic fixtures.

P2 must not quietly switch native serving to this query. That cutover belongs
to P3, where line-item and creative selection can change together and be tested
as one behavior.

## Lifecycle and cleanup

Deletion and state changes must leave no unexplained or tenant-crossing data:

- deleting a Campaign removes or retires its line-item assignments according to
  the documented retention rule;
- deleting a line item cannot orphan assignments;
- deleting a placement handles historical assignments without erasing audit or
  reporting meaning;
- replacing or deleting a Creative does not remove bytes still referenced by
  another assignment or historical revision;
- private-file and promoted-attachment cleanup is reference-aware and
  retry-safe;
- destructive uninstall removes all P2 tables, options, scheduled hooks and
  plugin-owned files only when the existing opt-in policy authorizes it; and
- multisite deletion affects only the selected site's prefix and files.

## Operational quality

Enterprise-ready P2 includes failure visibility rather than relying on database
inspection. Site Health or the later observability surface must be able to
report migration state, cursor, last progress, failed records and a missing
schedule. Recovery must be safe to run more than once.

The implementation must define query budgets and demonstrate them with large
fixtures. Creation and approval may do more work than serving reads, but no
screen or API may issue work proportional to every Creative in the system when
it needs one Campaign. File operations, database writes and cache invalidation
must have explicit failure behavior.

## Required executable evidence

P2 needs focused tests for:

- exact schema columns, indexes and idempotent installation;
- fresh install and the real production migration map from every supported
  pre-P2 database version;
- bounded backfill, interruption, resume, duplicate races and missing-schedule
  repair;
- a native fill that succeeds while migration is pending or partially failed;
- legacy Creative identity, review state, files, attachment and click behavior
  after migration;
- multiple creatives on one placement and one reusable asset assigned to
  several valid combinations;
- rejection of cross-tenant, cross-Campaign and mismatched-parent ids;
- coverage validation across status, date, approval and dimension boundaries;
- optimistic-concurrency and approval/replacement races;
- audit events for every business transition and destructive action;
- reference-aware file and attachment cleanup;
- query budgets with thousand-Campaign and many-creative fixtures;
- single-site and multisite isolation, new-site installation and site deletion;
- REST response shape, authorization and raw-input validation;
- advertiser and staff browser workflows, including keyboard, reflow and axe
  coverage; and
- the complete P0 PHP, WordPress, multisite, browser, accessibility,
  static-analysis, build, workflow and dependency-security baseline.

A test name is not evidence by itself. Tests that promise audit, migration,
authorization or serving continuity must assert the resulting audit row,
production migration wiring, denial, or fill respectively.

## Documentation and operations deliverables

P2 is not complete until the change is reflected in:

- [domain-model.md](domain-model.md), including ownership and lifecycle;
- [data-schema.md](data-schema.md), including every table, index and option;
- [rest-api.md](rest-api.md), including request and response shapes;
- [threat-model.md](threat-model.md), including cross-tenant assignment and
  file-reuse risks;
- [administration.md](administration.md) and [runbook.md](runbook.md), including
  migration monitoring, recovery and rollback; and
- [platform-implementation-progress.md](platform-implementation-progress.md),
  with evidence supporting the final status change.

## Exit criteria

P2 may move from `[ ]` or `[~]` to `[x]` only when all of the following are
true:

1. The asset, immutable revision and assignment ownership model is documented
   and implemented without conflicting writers.
2. Existing Creatives migrate without recreation, identity loss, file loss,
   attribution changes or serving interruption.
3. Campaign validation accepts multiple creatives and requires one eligible
   approved assignment per required line-item and placement combination.
4. Tenant-safe, concurrency-safe and audited workflows cover the supported
   lifecycle end to end.
5. P3 has one documented, indexed and performance-tested candidate read
   contract to consume.
6. Cleanup, multisite behavior, operational recovery and rollback are tested.
7. All required executable evidence and the complete P0 baseline are green in
   their authoritative environments.
8. The domain, schema, API, security, administration and runbook documentation
   describes what actually shipped.

The existence of new tables, classes or interfaces is not sufficient evidence
for completion.
