# Open work

Deliberately unfinished work, written down so it survives a change of author.

`known-issues.md` records things that are true and will stay true.
`roadmap.md` records which phase builds a planned feature. This file is
narrower: work that is **started, understood, and not done**, with enough
detail that picking it up costs minutes rather than a re-investigation.

Delete an entry when it ships. An entry that has been here through three
releases is either not real or not wanted — say which, in the entry, and then
delete it.

## 1. Security and accessibility pass over the staff surface

**Why it is first.** Three separate authorization checks were found this cycle
that could be deleted with every test still passing, because an outer
capability gate excluded the case the inner check handled. Each was found by
deliberately breaking it, never by reading it. That method works and has not
been applied to the highest-privilege code.

**Not yet examined:**

- `Security\Ownership::map()` and the org-scoping filters — the tenancy
  boundary. `CLAUDE.md` already records one test here that passed for the wrong
  reason, because `map_meta_cap` never passes a custom meta capability to the
  filter and recurses with the generic `edit_post` instead.
- `Admin\Review_Screen` and `Admin\Review_Data` — they render another
  organization's unapproved creative and drive status transitions.
- Portal handler delegation. Six `Portal\Organization_Actions` handlers check
  no capability inline and rely on `Organization_Membership::can_manage()`.
  That is legitimate *if* the workflow always checks; the pattern was verified,
  each method was not.
- Keyboard and focus. The axe lane passes, but axe catches a minority of real
  barriers. Dialog focus traps and focus placement after an autosave are
  unaudited.

**Method.** For each authorization check: delete it, run the suite, and confirm
a test fails naming that check. If nothing fails, the check is untested no
matter how many tests cover the file — write the test that calls the guard
directly, the way `PackagesWriteTest::test_the_write_gate_refuses_without_the_capability`
does.

## 2. Staff notification for advertiser requests

**State.** The queue tab and menu badge ship (see `Admin\Review_Data`). Staff
can *see* a request. Nobody is *told* about one.

`Notification_Service::queue_submission()` is the template: recipient
resolution, per-recipient receipt, delivery, audit. The structural difference
is that existing notifications hang off `aggr_notify_campaign_transitioned`,
and an advertiser request is a meta write rather than a transition, so it needs
its own hook.

1. Fire `do_action( 'aggr_notify_advertiser_request', $campaign_id, $kind )`
   from `Campaign_Change_Manager::submit()` and `request_action()`, where
   `$kind` is `edits` or the requested status.
2. Handle it in `Notification_Service`, modelled on `queue_submission()`, with
   recipients from `$this->users->with_capability( Capabilities::REVIEW_CAMPAIGNS )`.
3. Receipt key: `request:{kind}:{revision}:{user_id}`. **The revision must be in
   the key.** Without it, a withdrawn and resubmitted request is suppressed as a
   duplicate and nobody is told the second time.
4. Retry on the `RETRY_HOOK` pattern, re-checking the request still exists
   before sending. A cancelled request must not produce a late email — the same
   rule `retry_advertiser_notice()` already applies to stale statuses.
5. Test: two reviewers each receive one message; an identical second submit
   sends none; a withdraw-and-resubmit sends again. Break the receipt key and
   confirm the middle assertion fails.

**Why it was stopped rather than half-built.** Receipt-based suppression and a
cron retry chain fail in two directions — the review team gets the same mail on
every tick, or mail silently drops — and neither is visible until the chain is
complete.

## 3. Remaining admin screens

`Settings`, `Packages` and `Organizations` are React screens writing through
REST, sharing `src/admin/shared/save.tsx`. Two are not converted:

- **Inventory / placements** — CRUD, closest in shape to Packages.
- **Review queue and review campaign** — the largest. Buttons are derived from
  `Domain\Transition_Table`, so the screen must stay data-driven rather than
  hardcoding actions; it also renders private creative and internal notes.

Conversion is not required for correctness. Both screens work as
server-rendered templates today.

## 4. Moving a member between organizations

A portal account belongs to exactly one organization
(`Organization_Membership::eligible_for_org()`). Staff correct a mistake by
removing the member and inviting them to the other organization; the screen
says so rather than hiding the gap behind a "move" button.

Genuine multi-organization membership is a tenancy change, not a screen:
`Ownership::map()`, fill scoping and every org-scoped query assume one
organization per user. Do not add it as a convenience.
