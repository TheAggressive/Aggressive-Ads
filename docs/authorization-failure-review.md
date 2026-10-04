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
| REST | 52 route patterns, 56 methods (2026-10-03) | A real permission callback on every method; feature gate first, object gate in the workflow/handler; private reads hide existence |
| Authenticated forms | 42 `admin_post_*` registrations (2026-10-03), four of them the signed-in twins of the public forms | Authentication, feature capability, action/object-bound nonce, workflow authorization |
| Public forms | 4 `admin_post_nopriv_*` registrations | Closed allowlist: login, signup, password request, password set; nonce, abuse bounds, non-enumerating responses |
| Public delivery | fill, impression beacon, click hop | Same-origin/fetch-metadata checks where applicable, signed site-bound tokens, expiry/live-state validation, replay protection |
| Staff admin | Review, Organizations, Placements, Packages, Settings | Independent primitive per screen and REST write; publishing remains separate from reviewing |
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

## Pre-P18 adversarial audit

Date: 2026-10-03. Audited: `master` at `bb952bc`, P0–P17 complete, before any
P18 rich creative work.

The aim was to break the existing model rather than restate it. The surface was
taken from the running plugin, not from this document: every `aggr/v1` route
with its permission callback, and every `admin_post_*` and `wp_ajax_*` hook
registered.

### Result

No Critical, High or Medium finding. Two Low findings and one test gap, all
fixed with regression tests that fail against `bb952bc`. Nothing found blocks
P18.

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | Link-check redirects were judged only by core's `wp_http_validate_url()`. On WordPress 6.7–6.9, which the plugin supports and which do not block link-local or shared address space, an advertiser could save a destination that answers `302` to `169.254.169.254` and read the metadata service's status code back: a blind SSRF. The address filter also passed `100.64.0.0/10` (Alibaba Cloud metadata), NAT64 and 6to4 forms of internal addresses | Low | Fixed: `Link_Checker` follows each hop itself under the plugin's rules; `Link_Check_Rules::is_public_address()` uses `FILTER_FLAG_GLOBAL_RANGE` and decodes NAT64 |
| 2 | Anonymous rate limits counted each IPv6 address separately, so a client on a /64 could step around the beacon and click-count bounds on every request | Low | Fixed: an IPv6 client is its /64; an IPv4-mapped address counts as its IPv4 address |
| 3 | `AttackSurfaceTest::test_no_admin_ajax_handlers_are_registered` matched `laao_ads`, a pre-rename prefix, and could not fail | Test gap | Fixed: matches any `wp_ajax_*aggr*` hook, proves itself on a planted handler, and pins core's one taxonomy hook to its callback and capability |

Production runs WordPress 7.1.2, whose core list blocks the ranges in finding
1, so the live site was not exposed. Finding 2 was not reachable for sign-in,
signup or password-reset mail in practice: core's `wp-login.php` and
lost-password flows remain available to advertisers and have no limit at all.

### Informational

- **Plaintext passthrough.** A private file without the cipher header is read
  as plaintext, so `encrypt_existing_files()` can keep serving the queue while
  it migrates. The threat model claimed every on-disk substitution was a read
  failure; it now names the exception. The checksum still refuses a substituted
  file at promotion, and writing one needs filesystem access.
- **Acting-as on multisite.** `_aggr_acting_as` is network-wide user meta. Staff
  acting for organization 5 on one site are scoped to whatever organization has
  id 5 on another, with no session-start audit row there. No privilege is
  gained — `may_act()` requires the review capability on the current site, which
  already reaches every organization there — but the second site's audit trail
  has no session start. Accepted; worth a blog-scoped key if multisite staff
  become common.
- **`_code` / `html5`.** Nothing renders either kind today. P18 introduces the
  renderer and owns that boundary; the threat model now says so.
- **Late clicks.** A click more than `Fill_Token::TTL_SECONDS` (300 s) after the
  ad loaded gets a 404 and counts nothing. Not a security issue — replay stays
  bounded either way — but a revenue and experience one, recorded for the
  product owner rather than changed here.

### Investigated and not vulnerable

Recorded so the next audit can start where this one stopped.

| Suspicion | Why it holds |
|---|---|
| IDOR through custom-table rows (line items, creative assignments) | Editors authorize the campaign, then load the row by its id **and** the campaign id; a foreign row is the same 404 |
| Copying another organization's artwork onto one's own size | `Creative_Copies` requires the source to belong to the form's campaign and to be editable; the copy lands in the source's own campaign |
| Private creative stream and preview | `read_aggr_creative` through `Ownership`; one 404 for every failure; MIME from the allowlist, `nosniff`, `sandbox` CSP, `no-store` |
| Path traversal and symlink escape in private storage | Server-generated names; containment checked after `realpath()` with a trailing separator; NUL refused |
| Plaintext written when crypto is unavailable | `store()` fails closed before writing |
| Polyglot, SVG, double extension, decompression bomb | Header-only dimensions before decode; the plugin's own allowlist; a core extension "correction" is refused; promoted filename is the UUID, never the client's |
| Reviewer publishing without `aggr_publish` | Every approval path checks both capabilities; the transition table requires both for `review → approved` |
| A person taking a clock-driven transition | `apply()` and `apply_system()` are mutually exclusive in both directions |
| SQL injection | Every interpolation in 270 `$wpdb` calls is a prefix-derived table, a generated placeholder list, an allowlisted column or a fixed literal; values are bound; paging is capped |
| Stored XSS in portal, staff screens and the public slot | Templates escape at output; staff bootstrap JSON sits in an `esc_attr`ed `data-` attribute; `fill.js` builds DOM by property; every creative-destination write validates `http(s)` |
| Open redirect after sign-in or password set | `wp_validate_redirect()` against the site host |
| Forwarded-header spoofing of rate limits | Only `REMOTE_ADDR` is read |
| Forged, cross-site or replayed delivery tokens | HMAC over every field with canonical-form check, site-bound, expiring; one `(token_hash, event)` row each; the beacon accepts only `served` and `viewable` |
| Conversions against another tenant | Credential, definition and campaign organizations must agree; attribution needs a recorded click or view in window |
| Page facts as an oracle for unpublished posts | Only published posts and public taxonomies are read |
| Advertiser history leaking staff detail | Fixed sentences; rows scoped in SQL by campaign and organization; actors named only as "You", "Your team", "The review team" |
| Header injection through campaign names in mail | Plain-text mail with fixed headers; PHPMailer strips line breaks from subjects |
| Multisite crossing | Per-site table prefix; tokens HMAC the blog id and are refused on another site |
| CI token exposure | Every `contents: write` job runs only on a manual dispatch from `master`; `pr-policy` never checks out pull-request code; action SHAs are pinned and zizmor-checked |

### Accepted limitations, unchanged

The click destination is a deliberate open redirect (human review is the
control). DNS rebinding between resolution and connection survives on the link
check. Viewability and conversions are attested, not verified. Behind a
reverse proxy that does not restore `REMOTE_ADDR`, all anonymous clients share
one counter — a deployment concern for the host. Staff brute force on
`wp-login.php` remains site infrastructure.

### New regression contracts

- `LinkCheckTest`: the checker follows redirects itself; no refused target is
  requested after a redirect; a public redirect is followed to its answer;
  relative locations resolve; a chain past three hops is unreachable.
- `LinkCheckRulesTest`: shared address space, benchmarking, protocol
  assignments, NAT64 (well-known and local-use), 6to4 and documentation ranges
  are not public; NAT64 of a public address is.
- `PortalLoginTest::test_an_ipv6_client_is_counted_by_its_network`.
- `AttackSurfaceTest::test_the_admin_ajax_guard_sees_a_planted_handler` and the
  pinned core taxonomy hook.

Each was run against the unfixed code and failed, and each fix was reverted
once to confirm the test goes red without it.

## Review rule going forward

Any new route, public form, capability, object type, staff action or system hook
changes this review's inventory. Its change must include the corresponding
default-deny, cross-tenant and failure-side-effect assertions; a successful UI
flow alone is not sufficient evidence.
