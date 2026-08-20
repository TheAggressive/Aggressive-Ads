# Campaign workflow

## Statuses

Registered with `register_post_status()` as non-public, non-internal, protected, excluded from search.

| Slug | Label | Meaning |
|---|---|---|
| `aggr_draft` | Draft | Advertiser is editing. Not in the staff queue. |
| `aggr_submitted` | Submitted | In the queue, unclaimed. |
| `aggr_review` | In Review | Claimed by a named reviewer. |
| `aggr_changes` | Changes Requested | Back with the advertiser, editable. |
| `aggr_approved` | Approved | Passed review; publication pending. |
| `aggr_scheduled` | Scheduled | In the live set; start date is in the future. |
| `aggr_live` | Live | In the live set; inside the window. |
| `aggr_paused` | Paused | Occupies the live set; fill skips it. |
| `aggr_complete` | Completed | Past end date. **Terminal.** |
| `aggr_cancelled` | Cancelled | Terminated early. **Terminal.** |
| `aggr_rejected` | Rejected | Review refused. |

### Why the slugs are short

`wp_posts.post_status` is `varchar(20)`. `aggr_scheduled` is 14 characters. A longer invented slug (`aggr_changes_requested` is 26) would truncate on write and then never match on read, producing campaigns that exist in no status at all.

## Transitions

`Campaign_State_Machine::TRANSITIONS` is the single source of truth. An edge absent from this table cannot happen.

| From | To | Actor | Capability | Guard |
|---|---|---|---|---|
| `aggr_draft` | `aggr_submitted` | advertiser | `aggr_submit_campaign` + `edit_aggr_campaign` | Validator passes |
| `aggr_draft` | `aggr_cancelled` | advertiser | `delete_aggr_campaign` | — |
| `aggr_submitted` | `aggr_draft` | advertiser | `edit_aggr_campaign` | Unclaimed (`_aggr_reviewed_by` is `0`) |
| `aggr_submitted` | `aggr_review` | staff | `aggr_review_campaigns` | Sets `_aggr_reviewed_by` |
| `aggr_submitted` | `aggr_changes` | staff | `aggr_review_campaigns` | Non-empty `_aggr_review_notes` |
| `aggr_review` | `aggr_submitted` | staff | `aggr_review_campaigns` | Unclaim; clears `_aggr_reviewed_by` |
| `aggr_review` | `aggr_changes` | staff | `aggr_review_campaigns` | Non-empty review notes |
| `aggr_review` | `aggr_rejected` | staff | `aggr_review_campaigns` | Non-empty review notes |
| `aggr_review` | `aggr_approved` | staff | `aggr_review_campaigns` + `aggr_publish` | Validator passes again |
| `aggr_changes` | `aggr_submitted` | advertiser | `aggr_submit_campaign` | Validator passes; increments `_aggr_revision` |
| `aggr_changes` | `aggr_cancelled` | advertiser | `delete_aggr_campaign` | — |
| `aggr_rejected` | `aggr_draft` | staff | `aggr_review_campaigns` | Reopen |
| `aggr_approved` | `aggr_scheduled` | **system** | — | `start_ts > now` |
| `aggr_approved` | `aggr_live` | **system** | — | `start_ts <= now` |
| `aggr_scheduled` | `aggr_live` | **system** | — | `now >= start_ts` |
| `aggr_scheduled` | `aggr_paused` | staff | `aggr_review_campaigns` | — |
| `aggr_scheduled` | `aggr_cancelled` | staff or advertiser | `delete_aggr_campaign` | Busts fill cache |
| `aggr_live` | `aggr_paused` | staff | `aggr_review_campaigns` | — |
| `aggr_live` | `aggr_cancelled` | staff | `aggr_review_campaigns` | Busts fill cache |
| `aggr_live` | `aggr_complete` | **system** | — | `now > end_ts` |
| `aggr_paused` | `aggr_live` | staff | `aggr_review_campaigns` | Busts fill cache |
| `aggr_paused` | `aggr_cancelled` | staff | `aggr_review_campaigns` | — |

`aggr_complete` and `aggr_cancelled` have no outgoing edges. A completed campaign is duplicated into a new draft, never reopened — renew and duplicate are the same copy operation, not a transition. Nothing re-enters `aggr_draft` except a staff reopen from `aggr_rejected`. An advertiser who wants to change a submitted campaign either withdraws it (only while unclaimed) or waits for changes to be requested.

Editing a completed campaign is not reopening it, and the two must not be
confused. Staff may correct the record of a campaign in any status (see below),
but the campaign stays where it is: a corrected `aggr_complete` campaign is
still complete, and running it again is still a copy into a new draft. What an
edit changes is the stored campaign, never its position in the workflow.

## Editing, and the edit window

Capability answers *whether* a user may touch a campaign. The edit window
answers *when*. They fail differently on purpose — a capability failure is a
403 and means the user should not be here, a window failure is a 409 and means
not right now.

| Actor | May edit in |
|---|---|
| advertiser | `aggr_draft`, `aggr_changes` |
| staff (`aggr_review_campaigns`) | every status |

`Workflow\Edit_Window` is the single answer. Six call sites gate editing — the
campaign editor, both creative-manager paths, and the `editable` flag on the
portal row and the REST detail — and they only agree because they all ask it.
A screen that computed the rule itself would eventually offer a button that
409s.

Staff editing outside their own organization is an **on-behalf edit**:

- it is audited as `campaign.edited_on_behalf`, not `campaign.draft_updated`,
  because a timeline is read to answer "who changed this" and an unfamiliar
  name against an ordinary edit event reads the same whether staff fixed a typo
  or an account was misused;
- it busts fill cache when the campaign is serving, so the correction reaches
  the page immediately rather than after a TTL;
- it drops the future-start rule, for the same reason approval does: staff edit
  campaigns that have already started, and moving the date to satisfy the rule
  would rewrite when the campaign actually ran;
- it shows the advertiser's own wizard with a banner naming the organization,
  because otherwise the only clue the edits land on someone else's live
  campaign is an org name that reads as your own.

Membership decides whether an edit is on-behalf, not capability. A reviewer who
genuinely belongs to the organization is editing their own work, and recording
that as on-behalf would make the timeline read as though an outsider reached in.

Widening the window did not widen anyone's reach: `Security\Ownership` still
decides which campaigns a user can address at all, so an advertiser from
another organization is refused in every status.

Editing a campaign that is already serving re-checks creative coverage on
**every** save, not only when a wizard step advances. Coverage used to be
verified only on the way out of Step 4, which was safe while the only editable
statuses were draft and changes — an incomplete draft is expected, and
submission catches it later. Once staff could edit a `live` campaign that
stopped holding: the save busts fill cache, so a placement left without a
creative would reach the page immediately and serve nothing.

The check judges the placements the save *leaves in place*, not the stored
ones. A single request can change `placement_ids` and advance the step
together, and reading the stored value there validates the set the campaign is
moving away from.

### Creating for an advertiser

Staff also create campaigns on an advertiser's behalf, from the review queue.
This is the **only** place in the plugin where organization identity comes from
input rather than from an object that already carries one — everywhere else the
org is read off the campaign being acted on. That makes it the one place the
rule "advertiser input must never be trusted for organization identity" has to
be enforced rather than inherited.

It has its own route — `POST /aggr/v1/campaigns/for-advertiser`, gated on
`aggr_review_campaigns` — rather than an `org_id` parameter on the ordinary
create. Overloading create would leave `org_id` ignored on update and
authoritative on creation, which is the kind of asymmetry nobody remembers at
the call site; `POST /aggr/v1/campaigns` still derives the tenant from the
caller, and that is asserted.

`Campaign_Editor::create_for_org()` checks the capability again anyway. That is
not redundant: the admin screen reaches the editor through the same method, and
a rule enforced at one door only holds until somebody adds another.

Both gates refuse with 403, which makes them hard to tell apart in a test — the
permission callback answers `rest_forbidden` and the editor `aggr_forbidden`,
so the route test asserts the code rather than the status. A status-only
assertion passes with the route's gate downgraded to any advertiser, which is
how that test was first written. It refuses an id that
names no organization, and an id naming a post of the wrong type — a campaign
id and an org id are both post ids, so a transposed parameter is a plausible
mistake, and the result would be a campaign owned by nothing that no org-scoped
query returns and no advertiser can reach.

The draft is audited as `campaign.created_on_behalf` and opens in the
advertiser's own wizard, from which point it is an ordinary on-behalf edit.

**A copy belongs to the organization it was copied from, never the caller's.**
For an advertiser those are the same thing, since they can only read their own
organization's campaigns. For staff they are not: reading a client's campaign
is allowed, so deriving the target from the caller filed the copy, its snapshot
and its private creative bytes under whichever organization the staff member
happened to belong to.

## What every transition does

`Campaign_State_Machine::apply( int $campaign_id, string $to, array $context ): true|WP_Error`

```
1. verify the edge exists in TRANSITIONS
2. verify the actor holds the required capability
3. verify ownership (org-scoped, via map_meta_cap)
4. run the guard
5. run side effects that can fail   ← native fill cache bust happens HERE
6. write post_status
7. write the transition's meta (timestamps, reviewer, revision)
8. write the audit row
9. dispatch the domain event
10. notifications (fire-and-forget; failure never reaches the caller)
```

Three properties this ordering buys:

**It never partially applies.** The native publisher's cache bust runs at step 5, *before* the status write at step 6. If a failable effect returns an error, the campaign is still `aggr_review` and the reviewer sees it — not a campaign marked approved with stale fill.

**It never throws for a merely illegal transition.** An advertiser POSTing `aggr_approved` is an expected event, not an exceptional one. `apply()` returns a `WP_Error`, writes an `outcome=denied` audit row, and the REST layer turns it into a 403. Exceptions are reserved for genuine faults.

**Notifications cannot roll back a business fact.** Step 10 is last and its failure is swallowed and logged. A submitted campaign stays submitted when the mail server is down. Recipient resolution, per-user receipts, and retry behavior are specified in [notifications.md](notifications.md).

## Nothing else may write `post_status`

`Campaign_State_Machine::apply()` is the only writer. To make that enforceable rather than merely stated, a `transition_post_status` listener watches for campaign status changes that did not originate from `apply()` and writes an `outcome=denied` audit row naming what it saw.

This is cheap defence against another plugin's bulk-edit, a WP-CLI script, or a future us reaching for `wp_update_post()` in a hurry. It does not prevent the write — you cannot veto `transition_post_status` — but it means the divergence is visible in the audit log instead of being discovered months later as an inexplicable state.

## System transitions are derived, not scheduled

`aggr_scheduled → aggr_live → aggr_complete` are pure functions of the clock. There is no moment at which something must run for them to be true.

Native fill also reads campaign status at request time. `Campaign_Clock::reconcile()` runs whenever a campaign is read, so any surface that displays a campaign shows the correct state.

An hourly `aggr_reconcile_campaigns` cron event also exists — not because correctness needs it, but because dashboards, counts, and future billing should be right without waiting for someone to open a page. Both paths funnel through `apply()`, so there is exactly one implementation of "this campaign is now live."

## Validation

Draft edits from the server-rendered wizard and REST autosave both pass through
`Campaign_Editor`. Package selection is not a client-side convenience: the
editor re-resolves the posted id, refuses inactive or incomplete packages,
checks every child placement, and persists a commercial/placement snapshot
under the same optimistic revision claim as every other draft edit.

Creative writes similarly converge in `Creative_Manager`. It authorizes the
campaign and placement, validates destination and alternative text, delegates
hostile-byte inspection to `Creative_Uploader`, enforces exact placement
dimensions, and deletes staged bytes if a later check or record creation fails.
Removal is limited to unpublished creative on an advertiser-editable campaign
and deletes the private file before its record.

Scheduled and live campaigns use a separate reviewed-replacement workflow.
An advertiser uploads a replacement against one current creative; the same
MIME, pixel, exact-dimension, URL, alternative-text, tenant, and upload-rate
rules apply. The revision remains private and is excluded from the campaign's
active creative set, so native fill keeps serving the current creative. Only one
pending revision may target a current creative, guarded by a short-lived
atomic metadata lock. The advertiser may withdraw it before a decision.

Staff see pending revisions in the dedicated **Ad updates** queue and compare
the current and proposed destinations, alternative text, dimensions, and
artwork. Rejection requires advertiser-facing feedback and never touches
delivery. Approval requires review capability, promotes the checksum-verified
revision, and busts fill cache. The repository then makes the revision current
and archives its predecessor. Every outcome is campaign-audited.

Leaving destination-and-schedule Step 4 is another `Campaign_Editor` operation,
not a display-only step change. After authorization and optimistic concurrency,
the editor verifies one creative covers every selected placement and applies
`Campaign_Rules::validate_window()` to the candidate dates. A successful write
stores the UTC timestamps and advances `_aggr_wizard_step` to `review` in
the same draft update. REST and the progressive form therefore cannot disagree
about whether Step 4 is complete.

The Step 5 review screen does not invent a second definition of complete.
`Review_Readiness` runs `Campaign_Validator`, converts every problem into a
localized advertiser-facing message and exact wizard edit destination, and
removes the validator's raw context before returning data to the portal or REST
detail response. The screen is a read-only snapshot; it never advances status
and it never substitutes for transition-time validation.

Step 6 is also delivery-only: it does not persist a `submit` resume point.
After an explicit confirmation, its campaign-bound form nonce and the REST
transition route both call `Campaign_State_Machine::apply( ..., aggr_submitted )`
under the same per-user transition rate limit. The machine decides the edge,
reauthorizes the campaign, runs the validator against current stored data, and
only then commits status, submission metadata, audit, domain event, and
notification. A double post sees `aggr_submitted` as its current state, fails the
edge check, and is audited without repeating the successful transition.

The validator runs at every advertiser-triggered submission **and again at approval**. Re-running it is not redundant: a placement can be deactivated, an organization suspended, or a start date can fall into the past while a campaign sits in the queue.

The submission validator requires:

- at least one creative
- every creative `image`-kind, with dimensions matching a placement selected on the campaign
- every creative carrying a valid `http`/`https` click URL
- `start_ts` in the future at local `00:00:00`; `end_ts` at local `23:59:59`
  after `start_ts`, or `0` for open-ended
- the owning organization `active`
- every selected placement `_aggr_is_active`

The approval validator re-runs the same checks. There is no placement-mapping
or third-party publisher check.

## Approval, end to end

```
reviewer clicks Approve
        │
        ├─ capability + ownership check
        ├─ validator re-run
        │
        ├─ for each creative:
        │     sha256 re-verify → sideload → attachment → alt text
        │
        ├─ native publisher busts fill cache
        ├─ status → aggr_approved (clock then moves scheduled/live)
        ├─ audit
        └─ notify the advertiser
```

Native fill reads campaign status. An approved campaign whose window has opened
goes live on the next clock sweep without a downstream ad CPT.

## Changing a campaign that is already running

A campaign that is scheduled, live or paused was approved by staff as a
specific thing. Editing it in place would mean the approval no longer describes
what is being served, so an advertiser cannot: the wizard is gated on
`Post_Statuses::advertiser_editable()`, which is `draft` and `changes` only.

Two workflows exist instead, and both stage a change beside the campaign rather
than on it.

**Creative** — `Workflow\Creative_Change_Manager`. A replacement creative is
uploaded, validated and stored privately while the current one keeps serving.
Approval reconciles it with exact read-back and rollback.

**Campaign fields** — `Workflow\Campaign_Change_Manager`. The proposal is stored
as `_aggr_pending_edits` meta on the campaign; the campaign itself is not
touched until a reviewer approves. Approval writes the values, busts that
campaign's fill cache, and clears the proposal. Rejection requires
advertiser-facing feedback. The advertiser may withdraw their own proposal, and
only one may be pending at a time so a reviewer is never deciding a moving
target.

Field storage is meta rather than a shadow post, unlike a creative replacement,
because a replacement has *bytes* to hold and a post is what owns bytes here. A
handful of scalars does not need one.

### What may be proposed is site policy

`Settings_Schema::edit_keys()` — campaign name, advertiser notes, schedule,
destination URL, placements — each a checkbox under **Advertising → Settings →
Changes to running campaigns**. Every one ships **off**: a running campaign is
one staff already approved, and widening what an advertiser may change
underneath an approval is not a default anybody chose.

The allowlist is a permission boundary, not a UI hint. `Live_Edit_Rules::diff()`
drops any field outside it before validation — silently, because reporting it
would tell an advertiser which fields exist behind a switch the site owner
turned off — and `CampaignChangeTest` asserts a disabled field cannot be
smuggled in by hand-building the POST.

**Placements is not a peer of the others.** Changing it changes the required
creative size, so an approved placement change leaves the campaign unable to
serve until a correctly sized creative is uploaded and reviewed. It is a
re-submission, and both the settings screen and the review screen say so.

### Withdrawing a submission

Distinct from the above, and older: `submitted → draft` is a legal edge for the
advertiser while the `unclaimed` guard passes. The portal exposes it as
**Withdraw and edit**, which returns the campaign to an editable draft at the
first wizard step. Once a reviewer claims the campaign its status is `review`,
and there is no `review → draft` edge at all.
