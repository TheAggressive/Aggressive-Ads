# Authorization and failure-state review

Status: **complete**, 2026-08-22.

This is the Phase 11 review of the surfaces that read private tenant data,
change durable business state, publish or serve an ad, or cross a trust
boundary. It is an implementation audit backed by executable contracts, not a
claim inferred from the role definitions.

Concurrent request and soak testing is separate. That remaining exercise tests
capacity and contention on production-equivalent infrastructure; it does not
change the authorization conclusion recorded here.

## Outcome

The review found two gaps: one in authorization and one in failure disclosure.
The six organization `admin-post` handlers checked an action-specific nonce and
delegated organization ownership to `Organization_Membership`, but did not
independently require `aggr_access_portal`. An owner whose portal capability had
been revoked could therefore keep mutating membership and organization identity
if they submitted a valid nonce.

`Portal\Organization_Actions` now requires an authenticated session and
`aggr_access_portal` before nonce validation on every handler. The workflow
still independently requires ownership or `aggr_manage_orgs`. Tests exercise
all six handlers with a valid nonce, a durable owner record, and a revoked
portal capability, and assert both the denial and the absence of side effects.

Separately, a deliberately unavailable event ledger produced the correct `503`
beacon response and preserved click-through, but the replay diagnostic query
could emit WordPress's raw database error under a diagnostic configuration.
`Event_Repository::exists()` now suppresses that expected infrastructure error
and both event writes and replay diagnostics now restore the caller's prior
suppression state with `try/finally`. The failure-injection test asserts the
clean `503` body and the still-working click destination; a separate regression
test proves that successful and duplicate writes do not leak database error
policy into the surrounding request.

No other authorization bypass, failure disclosure or unsafe partial-commit path
was found in the reviewed scope.

## Surface inventory

| Surface | Inventory | Authorization contract |
|---|---:|---|
| Portal screens | Declared route grammar only | Authentication, `aggr_access_portal`, route-specific module state, tenant-derived view data |
| REST | 35 route patterns, 37 methods | A real permission callback on every method; feature gate first, object gate in the workflow/handler; private reads hide existence |
| Authenticated forms | 39 `admin_post_*` registrations | Authentication, feature capability, action/object-bound nonce, workflow authorization |
| Public forms | 4 `admin_post_nopriv_*` registrations | Closed allowlist: login, signup, password request, password set; nonce, abuse bounds, non-enumerating responses |
| Public delivery | fill, impression beacon, click hop | Same-origin/fetch-metadata checks where applicable, signed site-bound tokens, expiry/live-state validation, replay protection |
| Staff admin | Review, Organizations, Inventory, Packages, Settings | Independent primitive per screen and REST write; publishing remains separate from reviewing |
| Scheduled work | lifecycle, notifications, rollups, retention | No request-selected object scope; fixed hooks query eligible server-side state and use system-only transitions where required |

`tests/php/Security/AuthorizationSurfaceTest.php` is the closed REST inventory.
It fails when a route is added without updating the reviewed surface, rejects a
missing or non-callable permission callback, and proves that anonymous and bare
authenticated users reach only the two deliberately public native-delivery
endpoints.

`tests/php/Security/AttackSurfaceTest.php` holds the public `admin-post`
allowlist. A new `admin_post_nopriv_aggr_*` hook fails the suite until it is
classified and reviewed.

## Authorization invariants checked

| Invariant | Evidence |
|---|---|
| Authentication is not authorization | REST default-deny contract uses both anonymous and logged-in subscriber callers |
| Feature authority is separate from object authority | Revoked-owner organization tests; campaign and creative workflow capability checks |
| Organization scope is server-derived | Cross-tenant campaign, creative, report, organization and acting-as tests; posted `org_id` ignored outside the explicit staff route |
| Co-members can work without author identity becoming authority | Real `map_meta_cap` tests against WordPress users and posts |
| Staff visibility does not leak into advertiser payloads | Review-route gate, internal-note exclusions, private creative 404 equivalence |
| Reviewing does not imply publishing | Capability matrix and transition/replacement decision tests |
| New HTTP surfaces fail closed | Exact REST inventory and public form allowlist |
| Capability revocation takes effect immediately | Acting-as revocation test and organization handler revocation tests |
| Multisite identifiers never cross sites | Site-scoped ownership, fill token and cache tests under the multisite bootstrap |

## Failure-state invariants checked

The review followed writes through the delivery layer, workflow and repository,
including the error paths. Existing tests were retained and read as part of the
audit; the table names the behavior they pin.

| Failure | Required terminal state |
|---|---|
| Partial campaign draft/meta write | Prior snapshot restored; no half-edited campaign |
| Stale autosave | `409`; current revision remains authoritative |
| Upload validation or persistence failure | No usable creative record and no orphaned private bytes |
| Creative replacement/promotion failure | Current live creative remains intact; staged state can be retried or withdrawn |
| Publisher read-back mismatch | Publication is reconciled or rolled back; approval is not falsely reported |
| Signup/setup-mail failure | Compensating deletion of the new user, organization and access state |
| Membership grant/notification failure | Grant writes compensate when required; post-commit notification failure does not revoke valid access |
| Campaign notification failure | Successful business transition remains committed; only failed recipients enter bounded retry |
| Invalid, expired, reused or cross-site delivery token | No event increment; stable `400`, `403` or `409` response by failure class |
| Event write failure | Beacon reports `503`; click destination remains available without claiming a count |
| Unauthorized or cross-tenant mutation | Denial is side-effect free and, where relevant, audited |
| Missing private creative | Same `404` shape as forbidden creative; no path, token or storage detail disclosed |

## Verification record

Executed against WordPress 7.1, MySQL 8.0.46 and PHP 8.5.6 using the repository's
native runner:

- Unit: 423 tests, 2,158 assertions.
- Integration + security + REST + upgrade: 819 tests, 5,208 assertions.
- Multisite: 7 tests, 31 assertions.
- Complete fast QA: passing, including PHPCS, PHPStan, JavaScript tests,
  TypeScript, styles, build, i18n drift and repository policy gates.
- Static route-permission and repository-boundary gates: passing.

CI remains authoritative for its pinned PHP 8.4 and MySQL 8.4 matrix. The local
MySQL helper now includes the requested port in its Unix socket name, so an
isolated test instance cannot collide with another runner merely because both
belong to the same operating-system user.

## Review rule going forward

Any new route, public form, capability, object type, staff action or system hook
changes this review's inventory. Its change must include the corresponding
default-deny, cross-tenant and failure-side-effect assertions; a successful UI
flow alone is not sufficient evidence.
