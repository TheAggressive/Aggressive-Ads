# ADR-0019 — Organization matching is private and membership requires approval

**Status:** Accepted — 2026-08-11

## Context

Public signup needs to avoid creating `ACME`, `Acme`, and `ACME, INC.` as
separate advertiser tenants. A conventional autocomplete would make spelling
easy, but it would also publish a searchable customer directory. Automatically
joining the best fuzzy match is worse: a typo becomes cross-tenant access.

The workflow also needs to survive concurrent signup and approval requests.
WordPress posts, user roles, post meta, mail, and custom-table state do not share
one application transaction.

## Decision

The signup form remains a plain organization field. Matching happens only on
the server. Display names are uppercased; a canonical comparison value removes
accents and punctuation and collapses whitespace. A unique HMAC-derived
`active_key` in `laao_ads_org_access` atomically reserves each identity.

An exact or conservatively scored, unambiguous fuzzy match is a suggestion only.
The new WordPress user stays a subscriber and receives no organization meta or
advertiser capability. The existing owner receives a pending request and must
approve or deny it. Pending addresses are never sent to an ordinary member's
view data.

Owners may bypass spelling entirely by sending an invitation. Its 256-bit
URL-safe token is stored only as a salted HMAC, bound to one normalized email,
expires after three days, and is atomically claimed exactly once. A new invitee
receives the portal's password setup; an existing WordPress user's roles are
preserved while the advertiser role is added.

Every owner portal mutation derives its organization from the authenticated
user. The submitted row ID is looked up again under that organization, expected
kind, pending state, and expiry. `laao_ads_manage_orgs` remains the explicit
staff override in the workflow layer.

Because no database transaction spans all stores, the workflow orders privilege
last and compensates failures. It removes only membership/role state introduced
by the current attempt, never state that existed before it. Raw tokens, names,
and email addresses do not enter audit context.

## Consequences

- Signup provides typo tolerance without an organization-enumeration endpoint.
- Nobody can join an existing advertiser solely by knowing or guessing its
  name.
- Owners have a small approval burden for accidental or malicious close-name
  requests.
- Very different misspellings may still create a second organization; safe
  under-matching is preferred to unsafe auto-attachment.
- Identity, token, expiry, tenant scoping, and compensation require a custom
  table and a version-2 migration.

## Alternatives rejected

**Public autocomplete.** It discloses the customer directory and still cannot
prove that the selecting person belongs to the organization.

**Automatically join an exact or fuzzy match.** Organization names are not
secrets or credentials. This would be direct cross-tenant privilege escalation.

**Silently merge similar organizations.** Fuzzy similarity is not identity;
unrelated businesses can have nearly identical names.

**Store raw invitation tokens in post meta.** A database read would expose live
bearer credentials, and post meta has no atomic uniqueness primitive for claim
or canonical-name reservation.
