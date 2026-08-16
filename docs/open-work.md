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

**Done: `Admin\Review_Screen` / `Admin\Review_Data`.** Ten checks mutated; one
was held. The rest are now covered in `AdminReviewTest`, or recorded below as
deliberately not worth a test. Again nothing in `inc/` changed.

The one that mattered most was not a missing test but a wrong one.
`check_admin_referer()` reads the nonce from **`$_REQUEST`**, which PHP does not
populate from `$_POST` under CLI. All three transition-handler nonce tests set
only `$_POST`, so all three presented no nonce at all and died in the same place
— including `test_transition_handler_rejects_an_advertiser_with_a_valid_nonce`,
which therefore never reached a capability check and passed with **both**
capability gates on that path deleted. Any test here that posts to a handler
must set `$_REQUEST` or it is testing `wp_nonce_ays()` and nothing else.

Also newly covered: `render()`'s own capability gate (the screen showing another
tenant's unapproved creative, internal notes and audit trail); CSRF on the notes
handler, which had none while the transition handler had three tests; the
object-level `edit_aggr_campaign` check in `Review_Actions::save_internal_notes()`,
which is the same two-key shape found in `Ownership::map()`; and the per-edge
capability filter in `Review_Data::actions_for()`.

Left untested on purpose, so nobody re-derives it:

- the gates in `process_transition()` / `process_notes()`. Genuinely redundant —
  the handler gates them, and `Campaign_State_Machine::apply()` re-checks actor
  and per-edge capability against the object anyway.
- `menu_title()`'s capability check. `add_submenu_page()` never registers the
  item for a user without the capability, so the count cannot reach them. The
  check avoids a query, it does not prevent a disclosure.
- `Review_Data::campaign()` has no capability check of its own and is entirely
  caller-gated. That is fine while `Review_Screen::render()` is its only caller.
  It stops being fine the moment a REST route or a React screen calls it — worth
  remembering when the review screen is converted.

**Done: `Portal\Organization_Actions` delegation.** The delegation is sound —
all six workflow methods do call `can_manage()`. The coverage was not: **no test
reached any of the six handlers at all**, so all six `check_admin_referer()`
calls could be deleted together with the suite green, and two of the six
`can_manage()` guards (`invite` and `deny`) were untested as well.

Worse, `current_org_id()` derives the tenant from the authenticated user and
ignores request input — and teaching it to trust a posted `org_id`, which is the
ordinary way this gets written, left all 668 tests green. That one field is the
difference between a portal account managing its own organization and managing
any organization on the site.

Covered now in `PortalOrganizationActionsTest`: CSRF on all six (a data provider
over the handlers, plus a wrong-action nonce), the tenant derivation, and the
`invite` and `deny` guards through a plain member who belongs to the
organization but does not own it.

**Not yet examined:**

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
