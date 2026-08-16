# Open work

Deliberately unfinished work, written down so it survives a change of author.

`known-issues.md` records things that are true and will stay true.
`roadmap.md` records which phase builds a planned feature. This file is
narrower: work that is **started, understood, and not done**, with enough
detail that picking it up costs minutes rather than a re-investigation.

Delete an entry when it ships. An entry that has been here through three
releases is either not real or not wanted — say which, in the entry, and then
delete it.

## 1. The review screens are still server-rendered

`Settings`, `Packages`, `Organizations` and `Inventory` are React screens
writing through REST, sharing `src/admin/shared/save.tsx`. The review queue and
review campaign screens are not.

**The routes they need already exist.** `REST\Review_Controller` ships the two
reads and three writes. The other two were already there and must not be
duplicated: `Transitions_Controller` owns status changes, and
`Creative_Controller` owns staff decisions on an ad replacement.

| What | Route |
|---|---|
| Queue, with tab counts | `GET /review/queue` |
| One campaign, in full | `GET /review/campaigns/{id}` |
| Internal notes | `POST /review/campaigns/{id}/notes` |
| Decide campaign edits | `POST /review/campaigns/{id}/changes` |
| Close an advertiser request | `POST /review/campaigns/{id}/request` |
| Decide an ad replacement | `POST /creative-replacements/{id}/decision` (already existed) |
| Status change | `POST /campaigns/{id}/transitions` (already existed) |

That closed the gate this entry used to warn about: `Review_Data::campaign()`
still has no capability check of its own, and now never needs one, because the
only two callers — `Review_Screen::render()` and the controller — each gate it.
**Read that asymmetry before editing the controller.** Its writes have two gates
and its reads have exactly one, so `permission()` is the whole of the protection
on the campaign payload, which carries internal notes, private creative previews
and the audit timeline.

What is left is the UI, and it is genuinely only the UI:

- Two views rather than one — a queue with tabs, filters and pagination, and a
  campaign detail — where every other converted screen is a single list.
- Transition buttons stay derived from `Domain\Transition_Table` through
  `Review_Data::actions_for()`, which already filters per-edge by capability.
  Hardcoding the actions in TSX would be a second lifecycle.
- These screens carry the plugin's own design system (`Assets::STYLE_ADMIN`),
  not the native admin markup the other four use. Either port the styling or
  accept that this screen looks different from its siblings; do not half-do it.
- Deleting `templates/admin/review-queue.php`, `templates/admin/review-campaign.php`,
  `Admin\Campaign_Change_Actions` and `Admin\Creative_Change_Actions` is part of
  the job. Leaving handlers registered with no form pointing at them is an
  unreferenced write path.

Conversion is not required for correctness. The screens work today.

Two things the Inventory conversion is worth copying:

- The e2e fixture must use a slug `tests/e2e/reset.php` already deletes. A
  unique-per-run slug escapes teardown and leaves a row behind on every run,
  which then breaks the next run's assertions with a strict-mode violation.
- Assert the bundle mounted before anything else. Without that first assertion
  every later locator times out and the failure reads "no such button" rather
  than "the bundle never ran".

## 2. Moving a member between organizations

A portal account belongs to exactly one organization
(`Organization_Membership::eligible_for_org()`). Staff correct a mistake by
removing the member and inviting them to the other organization; the screen
says so rather than hiding the gap behind a "move" button.

Genuine multi-organization membership is a tenancy change, not a screen:
`Ownership::map()`, fill scoping and every org-scoped query assume one
organization per user. Do not add it as a convenience.
