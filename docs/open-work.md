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

**Done: `Security\Ownership::map()`.** Seven checks were mutated one at a time
against the full suite. Four were held by a test that named them; three were
not, and all three are now covered in `OwnershipTest`:

- the missing-object denial reached through one of our own capability names.
  The two tests that document it go through `current_user_can()`, where core
  denies a nonexistent post before this filter has an opinion — so both stayed
  green with the branch deleted outright.
- the non-member read mapping to `read_private_<plural>` rather than `read`.
- the non-member write mapping to `edit_others_<plural>` rather than `edit_`.

The last two are one finding twice. The reviewer role holds both halves of each
pair, so the split is invisible to every test that uses the role; it is only
load-bearing for a user granted `aggr_review_campaigns` without the primitives,
which is the ordinary "help work the queue" misconfiguration. Nothing in `inc/`
changed — the guards were right, and untested.

Note for the remaining slices: the membership gate and the primitive scope mask
each other. Deleting either alone leaves the suite nearly green, because the
other still denies. Mutate one at a time and read *which* tests fail, not just
whether any do.

**Not yet examined:**

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

## 2. Remaining admin screens

`Settings`, `Packages` and `Organizations` are React screens writing through
REST, sharing `src/admin/shared/save.tsx`. Two are not converted:

- **Inventory / placements** — CRUD, closest in shape to Packages.
- **Review queue and review campaign** — the largest. Buttons are derived from
  `Domain\Transition_Table`, so the screen must stay data-driven rather than
  hardcoding actions; it also renders private creative and internal notes.

Conversion is not required for correctness. Both screens work as
server-rendered templates today.

## 3. Moving a member between organizations

A portal account belongs to exactly one organization
(`Organization_Membership::eligible_for_org()`). Staff correct a mistake by
removing the member and inviting them to the other organization; the screen
says so rather than hiding the gap behind a "move" button.

Genuine multi-organization membership is a tenancy change, not a screen:
`Ownership::map()`, fill scoping and every org-scoped query assume one
organization per user. Do not add it as a convenience.
