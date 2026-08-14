# ADR-0008 — An explicit transition table owns every status write

**Status:** Accepted — 2026-08-08

## Context

A campaign has eleven statuses and roughly two dozen legal edges between them. Some are advertiser actions, some staff actions, some derived from the clock. Several carry side effects that can fail — approval publishes to a third-party system and can bill a customer.

The default WordPress approach is `wp_update_post( [ 'post_status' => … ] )` wherever a status needs to change, with the rules living in whichever controller happened to need them. That distributes the answer to "can this campaign go from here to there, and who may do it?" across every surface, and guarantees the surfaces disagree.

## Decision

`Campaign_State_Machine::TRANSITIONS` is a constant table of `from → to` edges, each carrying its actor, required capability, and guard. **An edge absent from the table cannot happen.** The full table is in [campaign-workflow.md](../campaign-workflow.md).

`Campaign_State_Machine::apply( int $campaign_id, string $to, array $context ): true|WP_Error` is the only thing that writes a campaign's `post_status`, and it runs a fixed sequence:

```
1. verify the edge exists in TRANSITIONS
2. verify the actor holds the required capability
3. verify ownership (org-scoped, via map_meta_cap)
4. run the guard
5. run side effects that can fail   ← AdSanity publish happens HERE
6. write post_status
7. write the transition's meta
8. write the audit row
9. dispatch the domain event
10. notifications (fire-and-forget)
```

Three properties come from that ordering, and all three are the reason for it:

**It never partially applies.** Mapping resolution and publication run at step 5, before the status write at step 6. A failed publish leaves the campaign in `lap_review` with an error — not marked live with no ads behind it.

**It never throws for a merely illegal transition.** An advertiser POSTing `lap_approved` is an expected event. `apply()` returns `WP_Error`, writes an `outcome=denied` audit row, and REST turns it into a 403. Exceptions are reserved for genuine faults.

**Notifications cannot roll back a business fact.** Step 10 is last, and its failure is swallowed and logged. A submitted campaign stays submitted when the mail server is down.

Enforcement is not left to discipline alone: a `transition_post_status` listener watches for campaign status changes that did not originate from `apply()` and writes an `outcome=denied` audit row naming what it saw. It cannot veto the write — `transition_post_status` has no veto — but a bulk edit, a WP-CLI script, or a future us reaching for `wp_update_post()` becomes visible in the audit log rather than surfacing months later as an inexplicable state.

Statuses use the `lap_` storage prefix, not `laao_ads_`, because `wp_posts.post_status` is `varchar(20)` and `laao_ads_changes_requested` is 26 characters — it would truncate on write and then never match on read. This is the only place in the codebase using `lap_`, and the inconsistency is deliberate.

## Consequences

- Every transition is authorized, guarded, audited, and announced identically, because there is one implementation.
- The table is exhaustively unit-testable with no database: every legal edge, and every illegal one, including the ones nobody would think to try.
- `lap_complete` and `lap_cancelled` have no outgoing edges. Renewing a completed campaign is a copy into a new draft, not a transition backwards.
- Adding a status means adding rows to the table, which is a visible, reviewable diff in one file.
- The clock-derived transitions (`lap_scheduled → lap_live → lap_complete`) are pure functions of time and are reconciled on read, with an hourly cron as a convenience for dashboards and counts rather than for correctness. Both paths funnel through `apply()`, so there is exactly one implementation of "this campaign is now live."

## Alternatives rejected

**A state-machine library.** A runtime Composer dependency ([ADR-0011](0011-no-composer-runtime-dependencies.md)) to replace roughly 120 greppable lines, and it would still not know about capabilities, ownership, or the audit log — the parts that are actually hard.

**Status writes in the REST controllers.** Each controller re-implements the rules; the third one gets a guard slightly wrong and an advertiser can withdraw a campaign a reviewer has already claimed.

**Post meta as the state field, leaving `post_status` as `publish`.** Loses `WP_Query` status filtering and core's own status handling, and produces campaigns that look published to anything not using our repository.

**Side effects after the status write.** Simpler code, and it produces the exact failure this product must not have: a campaign marked live with no ads behind it, discovered when the customer asks why they cannot see their ad.
