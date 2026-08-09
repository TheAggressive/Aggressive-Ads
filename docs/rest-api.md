# REST API

Namespace `laao-advertiser-portal/v1`. Hand-rolled, not `wp/v2` — the five post types are `show_in_rest => false` precisely so no generic CRUD surface exists.

## Rules

**Every route has a real `permission_callback`.** `'__return_true'` is banned and `bin/ci/check-permission-callbacks.sh` fails the build on it. It is a thing people write while debugging and then commit.

**Every route declares an `args` schema** with `type`, `sanitize_callback`, `validate_callback`, and `required`. Fields outside the schema are discarded, which makes mass assignment structurally impossible rather than defended against.

**Every route touching an object checks ownership**, via `current_user_can( 'edit_laao_ads_campaign', $id )` — never by comparing IDs in the controller. One implementation of ownership, in `Ownership::map()`.

**`org_id` is never a parameter.** Not in any route, ever. It is derived from the authenticated user.

**Unauthorized reads return 404, not 403.** A `403` on a real ID and `404` on a fake one is a working object-ID oracle. Writes may return 403, since the caller already knows the object exists.

**Nonce required for browser requests.** The `wp_rest` nonce, verified by core's cookie authentication. Routes do not additionally hand-roll nonce checks; they rely on `is_user_logged_in()` inside the permission callback, which is only true for cookie auth once the nonce has validated.

**Controllers contain no business logic.** They validate input, call a workflow or repository service, and shape the response. A controller that decides anything is a controller that has to be tested through HTTP.

## Routes

Phase 1 ships none of these — the route shell exists and the table is the contract for Phases 3–6. Recorded now so the surface is designed rather than accreted.

| Method | Path | Capability | Notes |
|---|---|---|---|
| `GET` | `/campaigns` | `laao_ads_access_portal` | Own org only, always. Paged, server-side |
| `POST` | `/campaigns` | `laao_ads_submit_campaign` | Creates a draft |
| `GET` | `/campaigns/{id}` | `read_laao_ads_campaign` | 404 when not owned |
| `PATCH` | `/campaigns/{id}` | `edit_laao_ads_campaign` | Autosave; allowlisted fields; `_laao_ads_autosave_rev` for optimistic concurrency |
| `POST` | `/campaigns/{id}/transitions` | varies by target | Body carries `to`; the state machine authorizes the specific edge |
| `POST` | `/campaigns/{id}/creatives` | `laao_ads_upload_creative` | Multipart. Rate-limited |
| `GET` | `/creatives/{id}/file` | `read_laao_ads_creative` | **Streams bytes. Never redirects** |
| `DELETE` | `/creatives/{id}` | `delete_laao_ads_creative` | Only while the campaign is editable |
| `GET` | `/placements` | `laao_ads_access_portal` | Active placements only; no ad-group IDs in the response |
| `GET` | `/packages` | `laao_ads_access_portal` | Active packages only |
| `GET` | `/queue` | `laao_ads_review_campaigns` | Staff |
| `GET` | `/audit` | `laao_ads_view_audit_log` | Org-filtered **in SQL** |

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
