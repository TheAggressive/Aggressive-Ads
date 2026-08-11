# REST API

Namespace `laao-advertiser-portal/v1`. Hand-rolled, not `wp/v2` — the five post types are `show_in_rest => false` precisely so no generic CRUD surface exists.

## Rules

**Every route has a real `permission_callback`.** `'__return_true'` is banned and `bin/ci/check-permission-callbacks.sh` fails the build on it. It is a thing people write while debugging and then commit.

**Every route declares an `args` schema** with `type`, `sanitize_callback`, `validate_callback`, and `required`. Write controllers then extract only a named field allowlist before calling the workflow. A field outside that allowlist has nowhere to land, which makes mass assignment structurally impossible rather than dependent on a denylist staying current.

**Every route touching an object checks ownership**, via `current_user_can( 'edit_laao_ads_campaign', $id )` — never by comparing IDs in the controller. One implementation of ownership, in `Ownership::map()`.

**`org_id` is never a parameter.** Not in any route, ever. It is derived from the authenticated user.

**Unauthorized reads return 404, not 403.** A `403` on a real ID and `404` on a fake one is a working object-ID oracle. Writes may return 403, since the caller already knows the object exists.

**Nonce required for browser requests.** The `wp_rest` nonce, verified by core's cookie authentication. Routes do not additionally hand-roll nonce checks; they rely on `is_user_logged_in()` inside the permission callback, which is only true for cookie auth once the nonce has validated.

**Controllers contain no business logic.** They validate input, call a workflow or repository service, and shape the response. A controller that decides anything is a controller that has to be tested through HTTP.

## Routes

Routes marked “planned” remain contracts for later phases. Every other row is implemented and covered through the real WordPress REST server.

| Method | Path | Capability | Notes |
|---|---|---|---|
| `GET` | `/campaigns` | `laao_ads_access_portal` | Own org only, always. Paged, server-side |
| `POST` | `/campaigns` | `laao_ads_submit_campaign` | Creates a draft |
| `GET` | `/campaigns/{id}` | `read_laao_ads_campaign` | 404 when not owned |
| `PATCH` | `/campaigns/{id}` | `edit_laao_ads_campaign` | Autosave; allowlisted fields; `_laao_ads_autosave_rev` for optimistic concurrency |
| `POST` | `/campaigns/{id}/transitions` | varies by target | Body carries `to`; the state machine authorizes the specific edge |
| `POST` | `/campaigns/{id}/creatives` | `laao_ads_upload_creative` | Multipart. Rate-limited |
| `GET` | `/creatives/{id}/file` | `read_laao_ads_creative` | **Streams bytes. Never redirects** |
| `DELETE` | `/creatives/{id}` | `delete_laao_ads_creative` | Removes private bytes and the record only while advertiser-editable and unpublished |
| `GET` | `/placements` | `laao_ads_access_portal` | Active placements only; no ad-group IDs in the response |
| `GET` | `/packages` | `laao_ads_access_portal` | Active, completely configured packages only; includes advertiser-facing placement labels, duration and integer-cent price |
| `GET` | `/queue` | `laao_ads_review_campaigns` | **Planned as REST.** Staff currently use the server-rendered review screen |
| `GET` | `/audit` | `laao_ads_view_audit_log` | **Planned as REST.** Current staff timeline is org-filtered **in SQL** |

## Draft creation and autosave

`POST /campaigns` accepts an optional `title`. The organization and author are
derived from the authenticated user; an account without an active organization
cannot create an unowned draft.

`PATCH /campaigns/{id}` accepts only `title`, `package_id`, `placement_ids`, `start_ts`,
`end_ts`, `advertiser_notes`, and `wizard_step`, plus the required
`autosave_rev` concurrency token. Placement references must still be active,
the date window must be internally ordered, and only advertiser-editable
statuses may be changed. A stale revision returns `409 laao_ads_edit_conflict`
with the current revision and does not overwrite the newer draft. Successful
writes return the newly incremented `autosave_rev`.

Selecting `package_id` is one validated editor operation: `Campaign_Editor` validates that the package,
all of its placements, duration, integer-cent price, and ISO currency are
currently usable, then copies the package id, placement set, price and currency
onto the draft. Later catalogue edits do not silently reprice or reshape that
campaign. Inactive or malformed packages are omitted from `GET /packages` and
are still rejected if their ids are posted directly.

The server-rendered form calls the same `Campaign_Editor` workflow. It converts
HTML dates from the WordPress timezone to UTC Unix integers before saving, so
progressive enhancement and REST autosave cannot develop different rules.
Setting `wizard_step` to `review` is the Step 4 completion boundary: both
deliveries require exactly one creative for every selected placement, a future
start, and either no end or an end after the start. Validation runs only after
object authorization and the optimistic revision check, so readiness failures
cannot be used to probe another tenant's campaign.

`GET /campaigns/{id}` also returns `readiness`; collection rows deliberately do
not, avoiding a full submission validation for every item in a paginated list.
The detail shape is `{ ready, problems }`, where each problem contains only
`code`, localized `message`, wizard `step`, and in-page `target`. Validator
context is never serialized because it may contain destinations or internal
object identifiers. This is presentation guidance, not a submission claim:
the transition endpoint revalidates immediately before changing status.

The progressive Step 6 form is a second delivery of that transition contract,
not a second implementation. It posts a campaign-bound nonce to `admin-post`,
uses the transition rate limiter, and calls the same state machine with
`lap_submitted`. The `submit` confirmation is selected by query string only;
`PATCH /campaigns/{id}` cannot persist it as `wizard_step`. Replayed form or
REST submissions are refused by the current-state edge check and recorded as
denied transitions.

## The file-stream route

The highest-value endpoint in the system, so its contract is explicit.

```
GET /wp-json/laao-advertiser-portal/v1/creatives/{id}/file
```

1. `read_laao_ads_creative` — which resolves through org-scoped `map_meta_cap`.
2. Resolve `_laao_ads_private_path` and confirm it stays inside the private root after `realpath()`.
3. `readfile()` the bytes with:
   - `Content-Type` from a strict allowlist, never from the stored value
   - `X-Content-Type-Options: nosniff`
   - `Content-Disposition: inline; filename="…"` with a sanitized name
   - `Cache-Control: private, no-store`

**It never redirects to the raw file.** A redirect hands the caller a URL that outlives their session and can be pasted anywhere — the authorization becomes a one-time check on a permanent capability. Streaming keeps every request authorized.

## Creative writes

`POST /campaigns/{id}/creatives` and the server-rendered form share
`Creative_Manager`. A write requires a selected active placement, valid HTTP(S)
destination without credentials, non-empty alternative text, and
server-detected dimensions exactly matching the placement. JPEG, PNG, GIF, and
WebP are allowed up to 2 MB; SVG remains denied regardless of site-wide MIME
plugins. One creative may cover each placement.

Validation after private staging compensates by deleting staged bytes on
failure. Persistence failure removes both the partial record and its file.
`DELETE /creatives/{id}` removes the private file before its record and refuses
another tenant, a locked campaign, or attached/published creative. Upload and
removal write campaign-scoped audit events without logging filenames, paths,
destination URLs, or alternative text.

## Responses

Advertiser-facing responses never include: `_laao_ads_internal_notes`, `_laao_ads_adgroup_term_id`, raw file paths, `_laao_ads_private_token`, reviewer identities, or any AdSanity ID. Response shaping is explicit per-route — never `get_post_meta( $id )` serialized wholesale, which is how internal fields leak the moment someone adds one.

Errors use `WP_Error` with a `laao_ads_` code prefix and a message safe to show a user. Diagnostics go to the audit log, not the response body.

## Rate limits

| Endpoint | Limit |
|---|---|
| `POST /campaigns/{id}/creatives` | 30 per hour per user |
| `PATCH /campaigns/{id}` | 120 per hour per user |
| `POST /campaigns/{id}/transitions` | 20 per hour per user |

Deliberately generous. The goal is to bound the cost of abuse, not to police normal use — an advertiser correcting a rejected campaign at 11pm should never meet a limit. Exceeding one returns 429 with `Retry-After` and writes an `outcome=denied` audit row.
