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

`aggr_complete` and `aggr_cancelled` have no outgoing edges. A completed campaign is duplicated into a new draft, never reopened — renew and duplicate are the same copy operation, not a transition. See [ADR-0029](adr/0029-campaign-copy-is-not-a-transition.md).

Nothing re-enters `aggr_draft` except a staff reopen from `aggr_rejected`. An advertiser who wants to change a submitted campaign either withdraws it (only while unclaimed) or waits for changes to be requested.

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

**Notifications cannot roll back a business fact.** Step 10 is last and its failure is swallowed and logged. A submitted campaign stays submitted when the mail server is down. Recipient resolution, per-user receipts, and retry behavior are specified in [notifications.md](notifications.md). See [ADR-0008](adr/0008-explicit-transition-table.md).

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
or third-party publisher check. See [ADR-0031](adr/0031-native-is-the-only-publisher.md).

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
