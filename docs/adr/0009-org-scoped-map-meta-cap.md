# ADR-0009 — Custom roles plus an org-scoped `map_meta_cap` filter

**Status:** Accepted — 2026-08-08

## Context

Ownership in this product is **organizational**, not authorial. An organization has several member users, and a campaign created by member A must be editable by member B — an agency where only the person who clicked "create" can fix a typo is a broken product.

Core's meta-capability mapping asks `$post->post_author === $user_id`. Left alone, it would make the portal silently single-user-per-organization: everything appears to work until the second person at an agency tries to edit something.

Meanwhile the highest-value asset in the system is another organization's unpublished creative, and the most common web vulnerability class is exactly this one — an authenticated user reaching an object they do not own.

## Decision

Two custom roles (`laao_ads_advertiser`, `laao_ads_reviewer`) holding primitive capabilities declared once in `inc/Security/class-capabilities.php`, plus a `map_meta_cap` filter, `Security\Ownership::map()`, that resolves the three meta caps (`edit_`, `read_`, `delete_`) across all five post types **against the organization** rather than the author.

```
1. Return $caps untouched for any capability we do not own
2. Resolve the object's _laao_ads_org_id  (for laao_ads_org, that is the post ID)
3. Resolve the user's org memberships, memoized per request
4. Reviewer capability → the _others_ primitive
5. Object's org among the user's memberships → the owner primitive
6. Otherwise → array( 'do_not_allow' )
```

Controllers never compare IDs. They ask `current_user_can( 'edit_laao_ads_campaign', $id )` and this filter answers. **One implementation of ownership, used by every surface** — REST, portal screens, admin screens, and the file stream.

`laao_ads_review_campaigns` and `laao_ads_publish_to_adsanity` are separate capabilities on purpose: reviewing is a judgement, publishing writes to a public site and can bill a customer. A future triage-only role needs no redesign.

**`org_id` is never read from client input** — not a body field, not a query arg, not a hidden input. It is derived server-side from the authenticated user on every request, without exception. That single rule collapses most of the object-reference surface in [threat-model.md](../threat-model.md).

## Consequences

Three hazards live inside the filter, and all three have bitten implementations of this pattern before:

- **Never call `current_user_can()` or `map_meta_cap()` from inside it.** Both re-enter `map_meta_cap`, and the filter is already inside it. Read `get_userdata( $user_id )->allcaps` directly. The recursion does not reliably blow the stack — sometimes it returns a wrong answer from a half-built capability array, which is worse.
- **Memoize the membership lookup.** `map_meta_cap` fires dozens of times per render; an uncached meta query there means sixty queries to draw a list of five campaigns.
- **Map missing and deleted posts to `do_not_allow` explicitly.** Falling through to core means comparing `$post->post_author` on a `null` post, a comparison that can grant.

Also:

- The filter runs on every capability check in WordPress, including core's own, so it **must be inert** for anything it does not own.
- The advertiser role deliberately lacks `upload_files`, `edit_posts`, and `unfiltered_html`. The last is decisive: `_code` and `html5` creatives are arbitrary HTML on a public page. Asserted in `tests/php/Security/RolesTest.php`.
- `inc/Security/class-admin-guard.php` redirects advertisers out of wp-admin. That is convenience, not security — the capability model already denies everything the redirect hides. Both the wiring and the behaviour are tested, because a guard that silently stops being hooked looks identical to one that works.
- Security tests assert `has_filter( 'map_meta_cap', … ) === 10` **and** the behaviour. A test proving the method is correct does not prove the method runs.
- The co-member case — advertiser B allowed on advertiser A's campaign within the same org — is a required test, because it is the one that proves ownership is org-scoped rather than accidentally author-scoped. A pure-denial test suite passes just as happily against a broken single-user implementation.
- Roles are written at install and re-applied on a `laao_ads_roles_version` bump, so a capability added in an update reaches existing sites.

## Alternatives rejected

**Core's author-based mapping.** Single-user-per-organization, arrived at silently.

**ID comparison in each controller.** N implementations of ownership, N chances to forget one. The forgotten one is an IDOR.

**A bespoke permission service that does not use `map_meta_cap`.** Every core and third-party path that checks a capability — admin list tables, `wp_delete_post()`, REST — would bypass it. Filtering core's pipeline means our answer is the answer everywhere.

**A single `laao_ads_manage` capability.** Cannot separate reviewing from publishing, and cannot express object scope at all.
