# REST API

Namespace `aggr/v1`. Hand-rolled, not `wp/v2` — the five post types are `show_in_rest => false` precisely so no generic CRUD surface exists.

## Rules

**Every route has a real `permission_callback`.** `'__return_true'` is banned and `bin/ci/check-permission-callbacks.sh` fails the build on it. It is a thing people write while debugging and then commit.

**Every route declares an `args` schema** with `type`, `sanitize_callback`, `validate_callback`, and `required`. Write controllers then extract only a named field allowlist before calling the workflow. A field outside that allowlist has nowhere to land, which makes mass assignment structurally impossible rather than dependent on a denylist staying current.

**Every route touching an object checks ownership**, via `current_user_can( 'edit_aggr_campaign', $id )` — never by comparing IDs in the controller. One implementation of ownership, in `Ownership::map()`.

**`org_id` is never a parameter.** Not in any route, ever. It is derived from the authenticated user.

**Unauthorized reads return 404, not 403.** A `403` on a real ID and `404` on a fake one is a working object-ID oracle. Writes may return 403, since the caller already knows the object exists.

**Nonce required for browser requests.** The `wp_rest` nonce, verified by core's cookie authentication. Routes do not additionally hand-roll nonce checks; they rely on `is_user_logged_in()` inside the permission callback, which is only true for cookie auth once the nonce has validated.

**Controllers contain no business logic.** They validate input, call a workflow or repository service, and shape the response. A controller that decides anything is a controller that has to be tested through HTTP.

## Routes

Routes marked “planned” remain contracts for later phases. Every other row is implemented and covered through the real WordPress REST server.

| Method | Path | Capability | Notes |
|---|---|---|---|
| `GET` | `/campaigns` | `aggr_access_portal` | Own org only, always. Paged, server-side. `impressions` / `clicks` / `ctr` only while Reporting is on. `ctr` is a ratio, or null when impressions are 0 |
| `POST` | `/campaigns` | `aggr_submit_campaign` | Creates a draft |
| `GET` | `/campaigns/{id}` | `read_aggr_campaign` | 404 when not owned. Same metric fields as the list, same module gate |
| `PATCH` | `/campaigns/{id}` | `edit_aggr_campaign` | Autosave; allowlisted fields; `_aggr_autosave_rev` for optimistic concurrency |
| `POST` | `/campaigns/{id}/copy` | `aggr_submit_campaign` | New draft; source is read, not edited. No `org_id`. Rate-limited |
| `GET` | `/campaigns/{id}/line-items` | `aggr_access_portal` + `read_aggr_campaign` | The campaign's delivery strategy. Creates the default row on first read. Omits `organization_id` and the timestamps |
| `PATCH` | `/campaigns/{id}/line-items/{line_item_id}` | `aggr_submit_campaign` + `edit_aggr_campaign` | Whole-number fields reject decimal and exponent forms. `revision` is required. `budget_cents` is not accepted |
| `POST` | `/campaigns/{id}/transitions` | varies by target | Body carries `to`; the state machine authorizes the specific edge |
| `POST` | `/campaigns/{id}/creatives` | `aggr_upload_creative` | Multipart. Rate-limited |
| `GET` | `/creatives/{id}/file` | `read_aggr_creative` | **Streams bytes. Never redirects** |
| `DELETE` | `/creatives/{id}` | `delete_aggr_creative` | Removes private bytes and the record only while advertiser-editable and unpublished |
| `GET` | `/placements` | `aggr_access_portal` **or** `edit_posts` **or** `edit_theme_options` | Active placements only; includes public `slug` for the slot block; no ad-group IDs |
| `GET` | `/packages` | `aggr_access_portal` | Active, completely configured packages only; includes advertiser-facing placement labels, duration and integer-cent price |
| `POST` | `/settings` | `aggr_manage_settings` | Autosave for the Settings screen. Replaces the whole document; shares `Settings_Input` and `Settings::save()` with the admin-post form, so the WCAG contrast gate applies identically. Rejects the whole payload on any schema error |
| `GET` | `/review/queue` | `aggr_review_campaigns` | Queue page plus tab counts. An unknown `filter` falls back to the default rather than erroring |
| `GET` | `/review/campaigns/{id}` | `aggr_review_campaigns` | The staff view: internal notes, private creative previews, audit timeline. **The capability check on this route is the only gate** — `Review_Data` holds none |
| `POST` | `/review/campaigns/{id}/notes` | `aggr_review_campaigns` + `edit_aggr_campaign` | Staff-only internal notes. Returns the refreshed campaign |
| `POST` | `/review/campaigns/{id}/changes` | `aggr_review_campaigns` | `approve` or `reject` the advertiser's proposed campaign edits |
| `POST` | `/review/campaigns/{id}/request` | `aggr_review_campaigns` | Closes an advertiser's action request with an explanation they will read |
| `GET` | `/audit` | `aggr_view_audit_log` | **Planned as REST.** Current staff timeline is org-filtered **in SQL** |
| `POST` | `/creatives/{id}/replacement` | `aggr_upload_creative` + object ownership | Stages a private replacement for a scheduled/live ad; multipart `file`, `click_url`, and optional `alt_text` |
| `GET` | `/fill/{slot}` | public (always registered) | Public, same-origin. Uncached. One live creative from the equal-rotation set, or house. Mints a token bound to that campaign **and the current `blog_id`**. Response omits internal ids and never lists candidates |
| `POST` | `/i` | public (always registered) | Public same-origin beacon. Prefetch 400, replay 409, cross-origin 403, success 204 |
| `DELETE` | `/creative-replacements/{id}` | `aggr_upload_creative` + object ownership | Withdraws the caller's pending replacement |
| `POST` | `/creative-replacements/{id}/decision` | `aggr_review_campaigns`; approval also requires `aggr_publish` | Staff `approve` or `reject`; rejection requires `review_notes` |

Replacement routes call the same `Creative_Change_Manager` as the HTML forms.
They never accept a campaign, organization, placement, provider-ad, or current
creative relationship from the caller; every relationship is derived from the
authorized creative record. Approval busts fill cache and swaps our creative
records; there is no downstream ad to rewrite.

## Draft creation and autosave

`POST /campaigns` accepts an optional `title`. The organization and author are
derived from the authenticated user; an account without an active organization
cannot create an unowned draft.

`PATCH /campaigns/{id}` accepts only `title`, `package_id`, `placement_ids`, `start_ts`,
`end_ts`, `advertiser_notes`, and `wizard_step`, plus the required
`autosave_rev` concurrency token. Placement references must still be active,
the date window must be internally ordered, and only advertiser-editable
statuses may be changed. A stale revision returns `409 aggr_edit_conflict`
with the current revision and does not overwrite the newer draft. Successful
writes return the newly incremented `autosave_rev`.

Selecting `package_id` is one validated editor operation: `Campaign_Editor` validates that the package,
all of its placements, fixed or explicitly custom duration, integer-cent price,
and ISO currency are
currently usable, then copies the package id, placement set, price and currency
onto the draft. Later catalogue edits do not silently reprice or reshape that
campaign. Inactive or malformed packages are omitted from `GET /packages` and
are still rejected if their ids are posted directly. Catalogue rows expose
`custom_duration` and `is_default` booleans alongside `duration_days`; consumers
must not infer a custom schedule merely from a zero duration.

The server-rendered form calls the same `Campaign_Editor` workflow. It converts
HTML dates from the WordPress timezone to UTC Unix integers before saving, so
progressive enhancement and REST autosave cannot develop different rules.
Setting `wizard_step` to `review` is the Step 4 completion boundary: both
deliveries require exactly one creative for every selected placement, a future
start at `00:00:00` in the WordPress site timezone, and either no end or an end
at `23:59:59` after the start. The inclusive end is the last second before the
following midnight, matching AdSanity's comparison semantics. Partial-day REST
timestamps are rejected at Step 4 completion and by submission/approval
validation. Validation runs only after object authorization and the optimistic
revision check, so readiness failures cannot be used to probe another tenant's
campaign.

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
`aggr_submitted`. The `submit` confirmation is selected by query string only;
`PATCH /campaigns/{id}` cannot persist it as `wizard_step`. Replayed form or
REST submissions are refused by the current-state edge check and recorded as
denied transitions.

## Line items

`GET /campaigns/{id}/line-items` returns the campaign's delivery strategy. Every
campaign has exactly one line item today — the compatibility row — and the route
creates it on first read rather than 404ing, so a campaign predating P1 answers
the same as one created after it. The response omits `organization_id` and both
timestamps: the tenant is already implied by the campaign the caller reached
through, and re-stating it only widens what a leak would carry.

`PATCH` takes a `revision` and the fields to change. It refuses an empty update
(`aggr_line_item_fields_required`), and a stale revision is a `409` carrying
`current_revision` so a client can reconcile without a second request.

Two refusals are worth stating, because both look like the route being awkward:

**Whole-number fields reject decimal and exponent forms.** `goal_amount`,
`daily_cap`, `lifetime_cap`, `priority`, `weight` and `revision` are whole-number
domain values, and `is_numeric()` is the wrong gate for them — it accepts `1.5`,
`"1e3"` and `" 12 "`, which `absint()` then turns into 1, 1000 and 12. A client
sending a cap of `10.99` would get 10 stored and a `200` back, which is a lossy
write reported as a successful one. So the raw value is checked before anything
coerces it: an integer passes, a string passes only if it is digits and nothing
else, and a float never passes — `1.0` included, because JSON that meant a whole
number would have sent one. The refusal is `422 aggr_line_item_value_invalid`.

**`budget_cents` is not accepted here.** It is a projected field, and
`data-schema.md` names its writer: the Campaign. The route used to take it
anyway, so an advertiser could set a line-item budget, get a `200`, see it
stored, and lose it on their next unrelated save when a schedule or package edit
re-projected — with nothing reporting the loss. Sending it alone is refused as
an empty update; sending it beside an accepted field is ignored rather than
smuggled through.

Unauthorized and non-existent are the same answer. A campaign belonging to
another organization returns the same `404` body as one that does not exist, and
a line-item id belonging to a different campaign is `404` rather than `403`, so
neither can be used to enumerate.

## Creative assignments

Three routes, nested under the campaign so tenancy is decided by the campaign the
caller reached through rather than by an id they supplied.

| Route | Method | Capability |
|---|---|---|
| `/campaigns/{campaign_id}/creative-assignments` | `GET` | `aggr_access_portal` |
| `/campaigns/{campaign_id}/creative-assignments/{id}` | `PATCH` | `aggr_submit_campaign` |
| `/campaigns/{campaign_id}/creative-assignments/{id}/assignment` | `DELETE` | `aggr_submit_campaign` |

`PATCH` accepts `weight`, `start_at_ts`, `end_at_ts` and `status`, and takes a
`revision` like every other write here — the same optimistic-concurrency
mechanism, checked in the SQL `WHERE` rather than read-then-written.

`DELETE` withdraws the creative from its placement and keeps the creative. It
retires the assignment and frees the compatibility slot rather than removing the
row, so the history of what ran there survives the withdrawal. Withdrawing an
already-withdrawn assignment is refused rather than answered `200`: a status may
legally stay itself, so the "is this a legal transition?" question alone would
have accepted a second withdrawal.

Two refusals worth knowing:

**A window may only narrow the campaign's.** Widening is `422`, not a clamp, for
the reason `domain-model.md` gives — a silently different date is worse than a
rejection.

**A status change must be a legal edge.** `completed` and `cancelled` are
terminal, so nothing leaves them.

The response omits `organization_id` and `compat_key`. The tenant is implied by
the campaign, and `compat_key` is a migration detail no client has any business
knowing — removed by subtraction rather than allowlisted in, matching the
line-item presenter.

Unauthorized and non-existent are again the same answer: a campaign belonging to
another organization and an assignment id belonging to a different campaign both
return the same `404`, so neither can be used to enumerate. Both write routes are
rate limited on the autosave bucket.

## The file-stream route

The highest-value endpoint in the system, so its contract is explicit.

```
GET /wp-json/aggr/v1/creatives/{id}/file
```

1. `read_aggr_creative` — which resolves through org-scoped `map_meta_cap`.
2. Resolve `_aggr_private_path` and confirm it stays inside the private root after `realpath()`.
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

Advertiser-facing responses never include: `_aggr_internal_notes`, `_aggr_adgroup_term_id`, raw file paths, `_aggr_private_token`, reviewer identities, or provider ids. Response shaping is explicit per-route — never `get_post_meta( $id )` serialized wholesale, which is how internal fields leak the moment someone adds one.

Errors use `WP_Error` with a `aggr_` code prefix and a message safe to show a user. Diagnostics go to the audit log, not the response body.

## Rate limits

| Endpoint | Limit |
|---|---|
| `POST /campaigns/{id}/creatives` | 30 per hour per user |
| `PATCH /campaigns/{id}` | 120 per hour per user |
| `POST /campaigns/{id}/transitions` | 20 per hour per user |

Deliberately generous. The goal is to bound the cost of abuse, not to police normal use — an advertiser correcting a rejected campaign at 11pm should never meet a limit. Exceeding one returns 429 with `Retry-After` and writes an `outcome=denied` audit row.
