# Roles and capabilities

## Primitive capabilities

Declared once in `inc/Security/class-capabilities.php`, which is the source of truth consumed by `Roles`, by the REST permission callbacks, and by the tests.

| Capability | Grants |
|---|---|
| `laao_ads_access_portal` | Reach the front-end portal at all |
| `laao_ads_upload_creative` | Upload a creative file |
| `laao_ads_submit_campaign` | Move a campaign into review |
| `laao_ads_review_campaigns` | See the queue; claim, request changes, reject |
| `laao_ads_publish_to_adsanity` | Approve, which publishes |
| `laao_ads_manage_placements` | Reach the Ad delivery screen and change placement-to-ad-group mappings |
| `laao_ads_manage_packages` | Edit packages |
| `laao_ads_manage_orgs` | Edit any organization |
| `laao_ads_view_audit_log` | Read the audit log |
| `laao_ads_manage_settings` | Change plugin settings |

`laao_ads_review_campaigns` and `laao_ads_publish_to_adsanity` are separate on purpose. Reviewing is a judgement; publishing writes to a third-party system and can bill a customer. Keeping them apart allows a future junior-reviewer role that can triage but not go live, without redesigning anything.

## Roles

| Role | Display | Holds |
|---|---|---|
| `laao_ads_advertiser` | Advertiser | `read`, `laao_ads_access_portal`, `_upload_creative`, `_submit_campaign`; create/edit/edit_published/delete on `laao_ads_campaigns` and `laao_ads_creatives`; read-only on placements, packages, orgs |
| `laao_ads_reviewer` | Ad Reviewer | Everything above, plus `_review_campaigns`, `_publish_to_adsanity`, `_view_audit_log`, and the `_others_` / `_private_` variants on all five post types |
| `administrator` | — | Every `laao_ads_*` primitive and every generated capability, granted on install |

`editor` receives nothing. The filter `laao_ads_roles_receiving_caps` (default `['administrator']`) is the supported way to grant the full set to another role.

Organization ownership is data, not another WordPress role. The user ID stored
in `_laao_ads_owner_user_id` may invite, approve, deny, revoke, remove members,
rename the organization, and transfer ownership only for that organization.
`laao_ads_manage_orgs` is the explicit staff override and also gates the
Organizations admin screen that suspends or reactivates a tenant. Portal form
handlers still derive the organization from the authenticated user; they never
trust a submitted `org_id`, and each workflow scopes the access row or member id
to the same tenant before changing it. Staff suspension writes accept an
organization id only after `MANAGE_ORGS` and an existence check, with a
per-organization nonce. Removing a member never clears the owner meta key;
ownership moves only through the transfer workflow, which requires an existing
member target and leaves the former owner as a member. Rename reserves the
destination canonical `active_key` before releasing the previous identity row
so two tenants cannot claim the same name.

### What the advertiser deliberately does not have

Asserted explicitly in `tests/php/Security/RolesTest.php`:

- **`upload_files`** — advertisers never touch the Media Library. Creative goes to private storage and only becomes an attachment at approval. See [ADR-0010](adr/0010-two-stage-creative-storage.md).
- **`edit_posts`** — no access to site content of any kind.
- **`unfiltered_html`** — decisive, given that `_code` and `html5` creatives are arbitrary HTML on the public site.

`inc/Security/class-admin-guard.php` redirects the advertiser role out of `/wp-admin/` to the portal, exempting `admin-ajax.php` and `admin-post.php` so nothing legitimate breaks. That guard is convenience, not security: the capability model already denies everything the redirect hides. Both the wiring and the behaviour are tested, because a guard that silently stops being hooked looks identical to one that works.

## Generated meta capabilities

`map_meta_cap => true` on all five post types means each generates:

- meta caps: `edit_<singular>`, `read_<singular>`, `delete_<singular>`
- primitives: `edit_<plural>`, `edit_others_<plural>`, `edit_private_<plural>`, `edit_published_<plural>`, `publish_<plural>`, `read_private_<plural>`, `delete_<plural>`, `delete_others_<plural>`, `delete_private_<plural>`, `delete_published_<plural>`, `create_<plural>`

A meta cap is never granted to a role. `current_user_can( 'edit_laao_ads_campaign', 42 )` is translated by `map_meta_cap` into whichever primitive applies to *that object*, and the primitive is what the role holds.

## Ownership is the organization, not the author

**This is the part to get right, and it is the reason a `map_meta_cap` filter is mandatory rather than optional.**

Core's meta-cap mapping tests `$post->post_author === $user_id`. That is the wrong question here. An organization has several member users, and a campaign created by member A must be editable by member B. Left to core, the portal would silently become single-user-per-organization — and would appear to work, right up until the second person at an agency tried to fix a typo.

### The filter never sees our capability name

**core does not pass a custom meta capability to the `map_meta_cap` filter.** For `edit_laao_ads_campaign` it looks the name up in the global `$post_type_meta_caps`, then *returns* a recursive call using the generic `edit_post` — so the outer `apply_filters()` never runs, and all the filter is ever handed is `edit_post` plus the object id.

A filter that only handles our own names therefore looks correct and does nothing. Every object check falls through to core's `post_author` comparison, which denies strangers for the wrong reason and **grants reads**, because core maps `read_post` on a published post to plain `read`. This was written that way first, and the co-member test is what caught it.

So `Ownership::map()` owns `edit_post`, `read_post` and `delete_post`, and returns `$caps` untouched whenever the target is not one of our five post types.

### The mapping

`Security\Ownership::map( array $caps, string $cap, int $user_id, array $args ): array` handles the three meta caps across all five post types:

1. Return `$caps` untouched for any capability it does not own. **The filter must be inert for everything else** — this runs on every capability check in WordPress, including core's own.
2. Resolve the object's `_laao_ads_org_id`. For `laao_ads_org` itself, that is the post ID.
3. Resolve the user's organization memberships via `Org_Repository::org_ids_for_user()`, **memoized per request**.
4. **Placements and packages are shared configuration**, not anyone's property. They carry no owning org, so an org comparison would deny every advertiser — including on the wizard screen whose whole job is choosing among them. Reads map to `read_private_<plural>`; writes map to `laao_ads_manage_placements` / `laao_ads_manage_packages`, which neither advertisers nor reviewers hold.
5. If the user holds `laao_ads_review_campaigns`, map to the `_others_` primitive.
6. If the object's org is among the user's memberships, map to the owner primitive — and for a read, to plain `read`.
7. Otherwise return `array( 'do_not_allow' )`.

**A member's read maps to `read`, not to `read_private_<plural>`.** Membership is the authorization; requiring the primitive as well would be redundant *and* actively harmful, because `read_private_laao_ads_orgs` granted to advertisers would do nothing for their own organization and everything for everyone else's. The advertiser role holds `read_private_` on placements and packages only, and that is deliberate.

### Three hazards

**Never call `current_user_can()` or `map_meta_cap()` from inside the filter.** Both re-enter `map_meta_cap`, and the filter is already inside it. Read `get_userdata( $user_id )->allcaps` directly to test the reviewer flag. The recursion does not always blow the stack — sometimes it just returns a wrong answer from a half-built capability array, which is worse.

**Memoize the membership lookup.** `map_meta_cap` fires dozens of times per page render. An uncached meta query there is not a micro-optimization concern; it is a page that issues sixty queries to draw a list of five campaigns.

**Map missing and deleted posts to `do_not_allow`, explicitly.** If the object cannot be loaded, do not fall through to core — core compares `$post->post_author` against the user ID, and on a `null` post that comparison can grant. A deleted campaign must deny, not default.

## The capability audit

Read left to right for any route. Every REST route and portal screen must satisfy all four columns.

| Question | Mechanism |
|---|---|
| Is the caller authenticated? | `is_user_logged_in()`, via `auth_redirect()` on the portal or a REST `permission_callback` |
| May they use this feature at all? | A `laao_ads_*` primitive |
| May they touch *this object*? | A meta cap, resolved through `Ownership::map()` |
| Is the request genuine? | Nonce — `wp_rest` for REST, `check_admin_referer()` for `admin-post` |

Missing the third column is how IDOR happens, and it is the easiest one to skip because the first two already returned true.

## Never trusted from the client

`org_id` is **never** read from request input — not from a body field, not a query arg, not a hidden input. It is always derived server-side from the authenticated user.

That single rule removes most of the object-reference attack surface described in [threat-model.md](threat-model.md). Everything else — placement IDs, package IDs, campaign IDs — is validated against the repository under the caller's own ownership scope before use.
