# Open work

Deliberately unfinished work, written down so it survives a change of author.

`known-issues.md` records things that are true and will stay true.
`roadmap.md` records which phase builds a planned feature. This file is
narrower: work that is **started, understood, and not done**, with enough
detail that picking it up costs minutes rather than a re-investigation.

Delete an entry when it ships. An entry that has been here through three
releases is either not real or not wanted — say which, in the entry, and then
delete it.

## 1. Moving a member between organizations

A portal account belongs to exactly one organization
(`Organization_Membership::eligible_for_org()`). Staff correct a mistake by
removing the member and inviting them to the other organization; the screen
says so rather than hiding the gap behind a "move" button.

Genuine multi-organization membership is a tenancy change, not a screen:
`Ownership::map()`, fill scoping and every org-scoped query assume one
organization per user. Do not add it as a convenience.
