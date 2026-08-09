# Campaign workflow

## Statuses

Registered with `register_post_status()` as non-public, non-internal, protected, excluded from search.

| Slug | Label | Meaning |
|---|---|---|
| `lap_draft` | Draft | Advertiser is editing. Not in the staff queue. |
| `lap_submitted` | Submitted | In the queue, unclaimed. |
| `lap_review` | In Review | Claimed by a named reviewer. |
| `lap_changes` | Changes Requested | Back with the advertiser, editable. |
| `lap_approved` | Approved | Passed review; publication pending. |
| `lap_scheduled` | Scheduled | AdSanity ads exist; start date is in the future. |
| `lap_live` | Live | AdSanity ads exist; inside the window. |
| `lap_paused` | Paused | Suppressed in AdSanity. |
| `lap_complete` | Completed | Past end date. **Terminal.** |
| `lap_cancelled` | Cancelled | Terminated early. **Terminal.** |
| `lap_rejected` | Rejected | Review refused. |

### Why `lap_` and not `laao_ads_`

`wp_posts.post_status` is `varchar(20)`. `laao_ads_changes_requested` is 26 characters — it would truncate on write and then never match on read, producing campaigns that exist in no status at all. The `lap_` storage prefix is short enough that every slug fits with room to spare.

This is the only place in the codebase using `lap_`. Everything else uses `laao_ads_`. The inconsistency is deliberate and documented here so nobody "fixes" it.

## Transitions

`Campaign_State_Machine::TRANSITIONS` is the single source of truth. An edge absent from this table cannot happen.

| From | To | Actor | Capability | Guard |
|---|---|---|---|---|
| `lap_draft` | `lap_submitted` | advertiser | `laao_ads_submit_campaign` + `edit_laao_ads_campaign` | Validator passes |
| `lap_draft` | `lap_cancelled` | advertiser | `delete_laao_ads_campaign` | — |
| `lap_submitted` | `lap_draft` | advertiser | `edit_laao_ads_campaign` | Unclaimed (`_laao_ads_reviewed_by` is `0`) |
| `lap_submitted` | `lap_review` | staff | `laao_ads_review_campaigns` | Sets `_laao_ads_reviewed_by` |
| `lap_submitted` | `lap_changes` | staff | `laao_ads_review_campaigns` | Non-empty `_laao_ads_review_notes` |
| `lap_review` | `lap_submitted` | staff | `laao_ads_review_campaigns` | Unclaim; clears `_laao_ads_reviewed_by` |
| `lap_review` | `lap_changes` | staff | `laao_ads_review_campaigns` | Non-empty review notes |
| `lap_review` | `lap_rejected` | staff | `laao_ads_review_campaigns` | Non-empty review notes |
| `lap_review` | `lap_approved` | staff | `laao_ads_review_campaigns` + `laao_ads_publish_to_adsanity` | **All** placement mappings resolve; AdSanity active; validator passes again |
| `lap_changes` | `lap_submitted` | advertiser | `laao_ads_submit_campaign` | Validator passes; increments `_laao_ads_revision` |
| `lap_changes` | `lap_cancelled` | advertiser | `delete_laao_ads_campaign` | — |
| `lap_rejected` | `lap_draft` | staff | `laao_ads_review_campaigns` | Reopen |
| `lap_approved` | `lap_scheduled` | **system** | — | Ads created; `start_ts > now` |
| `lap_approved` | `lap_live` | **system** | — | Ads created; `start_ts <= now` |
| `lap_scheduled` | `lap_live` | **system** | — | `now >= start_ts` |
| `lap_scheduled` | `lap_paused` | staff | `laao_ads_review_campaigns` | — |
| `lap_scheduled` | `lap_cancelled` | staff or advertiser | `delete_laao_ads_campaign` | Unpublishes the AdSanity ads |
| `lap_live` | `lap_paused` | staff | `laao_ads_review_campaigns` | — |
| `lap_live` | `lap_cancelled` | staff | `laao_ads_review_campaigns` | Unpublishes |
| `lap_live` | `lap_complete` | **system** | — | `now > end_ts` |
| `lap_paused` | `lap_live` | staff | `laao_ads_review_campaigns` | Re-publishes |
| `lap_paused` | `lap_cancelled` | staff | `laao_ads_review_campaigns` | — |

`lap_complete` and `lap_cancelled` have no outgoing edges. A completed campaign is duplicated into a new draft, never reopened — Phase 9's "renew campaign" is a copy operation, not a transition.

Nothing re-enters `lap_draft` except a staff reopen from `lap_rejected`. An advertiser who wants to change a submitted campaign either withdraws it (only while unclaimed) or waits for changes to be requested.

## What every transition does

`Campaign_State_Machine::apply( int $campaign_id, string $to, array $context ): true|WP_Error`

```
1. verify the edge exists in TRANSITIONS
2. verify the actor holds the required capability
3. verify ownership (org-scoped, via map_meta_cap)
4. run the guard
5. run side effects that can fail   ← AdSanity publish happens HERE
6. write post_status
7. write the transition's meta (timestamps, reviewer, revision)
8. write the audit row
9. dispatch the domain event
10. notifications (fire-and-forget; failure never reaches the caller)
```

Three properties this ordering buys:

**It never partially applies.** Placement mapping resolution and the AdSanity publish both run at step 5, *before* the status write at step 6. If publication fails, the campaign is still `lap_review` and the reviewer sees an error — not a campaign marked live with no ads behind it.

**It never throws for a merely illegal transition.** An advertiser POSTing `lap_approved` is an expected event, not an exceptional one. `apply()` returns a `WP_Error`, writes an `outcome=denied` audit row, and the REST layer turns it into a 403. Exceptions are reserved for genuine faults.

**Notifications cannot roll back a business fact.** Step 10 is last and its failure is swallowed and logged. A submitted campaign stays submitted when the mail server is down. See [ADR-0008](adr/0008-explicit-transition-table.md).

## Nothing else may write `post_status`

`Campaign_State_Machine::apply()` is the only writer. To make that enforceable rather than merely stated, a `transition_post_status` listener watches for campaign status changes that did not originate from `apply()` and writes an `outcome=denied` audit row naming what it saw.

This is cheap defence against another plugin's bulk-edit, a WP-CLI script, or a future us reaching for `wp_update_post()` in a hurry. It does not prevent the write — you cannot veto `transition_post_status` — but it means the divergence is visible in the audit log instead of being discovered months later as an inexplicable state.

## System transitions are derived, not scheduled

`lap_scheduled → lap_live → lap_complete` are pure functions of the clock. There is no moment at which something must run for them to be true.

This mirrors AdSanity, which has no cron at all and computes active-vs-expired at read time. `Campaign_Clock::reconcile()` runs whenever a campaign is read, so any surface that displays a campaign shows the correct state.

An hourly `laao_ads_reconcile_campaigns` cron event also exists — not because correctness needs it, but because dashboards, counts, and future billing should be right without waiting for someone to open a page. Both paths funnel through `apply()`, so there is exactly one implementation of "this campaign is now live."

## Validation

The validator runs at every advertiser-triggered submission **and again at approval**. Re-running it is not redundant: a placement can be deactivated, an organization suspended, or a start date can fall into the past while a campaign sits in the queue.

The submission validator requires:

- at least one creative
- every creative `image`-kind, with dimensions matching a placement selected on the campaign
- every creative carrying a valid `http`/`https` click URL
- `start_ts` in the future, `end_ts` after `start_ts` or `0`
- the owning organization `active`
- every selected placement `_laao_ads_is_active`

The approval validator adds: **every placement mapping resolves**, and AdSanity is active. See [ADR-0007](adr/0007-placement-mapping-is-explicit-data.md) for why that check aborts before any write rather than failing partway.

## Approval, end to end

```
reviewer clicks Approve
        │
        ├─ capability + ownership check
        ├─ validator re-run
        ├─ Placement_Mapping::resolve_all()      ← fails closed, nothing written yet
        │
        ├─ for each creative:
        │     sha256 re-verify → sideload → attachment → alt text
        │     wp_insert_post( 'ads' ) → set_post_thumbnail
        │     meta: _url _target _size _start_date _end_date   (dates as ints)
        │     wp_set_object_terms( ad-group )
        │     read back and assert
        │     persist the ad ID                  ← so a retry reuses it
        │
        ├─ status → lap_scheduled or lap_live
        ├─ audit
        └─ notify the advertiser
```

Persisting each ad ID as it succeeds is what makes retry safe. A failure on the third of four creatives leaves two recorded ad IDs; the retry reconciles those and creates only the missing ones. Nothing is identified by title, so nothing is duplicated. Partial-failure handling lands with the publisher in Phase 6.
