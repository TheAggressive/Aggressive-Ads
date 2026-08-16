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
writing through REST, sharing `src/admin/shared/save.tsx`. One is not converted:

- **Review queue and review campaign** — the largest. Buttons are derived from
  `Domain\Transition_Table`, so the screen must stay data-driven rather than
  hardcoding actions; it also renders private creative and internal notes.

  Carry this over from the security pass: **`Admin\Review_Data::campaign()` has
  no capability check of its own.** It returns internal notes, private creative
  previews and the audit timeline, and is safe today only because
  `Review_Screen::render()` is its sole caller and gates it. A REST route
  reaching it directly would have no gate at all, which is exactly what a React
  conversion adds. Give the read route its own `aggr_review_campaigns` check;
  do not rely on the screen's.

Conversion is not required for correctness. The screen works as a
server-rendered template today.

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
