# ADR-0010 — Two-stage creative storage: private on upload, attachment on approval

**Status:** Accepted — 2026-08-08

## Context

An advertiser uploads artwork for a campaign that has not been reviewed, may be rejected, and may never run. That artwork is the most commercially sensitive data in the system — an unreleased campaign, a competitor's creative, an embargoed announcement.

The obvious implementation is `wp_handle_upload()` into the Media Library. Which means: a public URL under `/wp-content/uploads/` that requires no authentication, guessable by date directory and filename, indexable, and visible to every user in the site's media browser. WordPress has no per-attachment access control.

## Decision

Two stages, with promotion gated on approval.

**Stage 1 — upload.** The file is written to a private root outside the uploads URL space, under `{uuid}.{ext}` — a filename we generate, never the client's. A 32-character token is stored alongside. `.htaccess`, `web.config`, and `index.php` deny files are written. No attachment post exists. The advertiser role does not hold `upload_files`, so the Media Library is not merely bypassed; it is unreachable.

Reads go through `GET /creatives/{id}/file`, which checks `read_laao_ads_creative` through the org-scoped mapping in [ADR-0009](0009-org-scoped-map-meta-cap.md), confirms the resolved path stays inside the private root after `realpath()`, and **streams the bytes**. It never redirects to the file.

**Stage 2 — promotion, at approval only.** The recorded `sha256` is re-verified, the file is sideloaded into the Media Library, `_laao_ads_alt_text` is written to `_wp_attachment_image_alt`, and the attachment becomes the AdSanity ad's featured image.

Validation at stage 1 treats the file as hostile: `wp_check_filetype_and_ext()` and `getimagesize()` must **agree** — if core "corrected" the extension, reject rather than accept the correction, because a correction means the claimed type and the real type differ, which is the attack. SVG is hard-denied by MIME and by extension in our own allowlist, independent of `upload_mimes`. Size and pixel caps (2 MB, `width × height ≤ 25,000,000`) are enforced **before** any image processing.

Advertisers may only create `image` creatives. The `code`, `text`, and `html5` kinds require `laao_ads_review_campaigns`.

## Consequences

- Unapproved creative has no public URL to leak, forward, or index. Every read is authorized at the moment it happens.
- **It never redirects** is the load-bearing half of that. A redirect hands the caller a URL that outlives their session and can be pasted anywhere, turning authorization into a one-time check on a permanent capability.
- The `sha256` re-verification at promotion proves the published file is the file that was reviewed. Without it, "approved" describes a moment rather than an artifact.
- The Media Library stays clean: only approved creative ever appears there, so it reflects what is actually running.
- Streaming costs a PHP request per image view instead of a static file serve. Fine at portal traffic volumes, and non-negotiable at any volume.
- **nginx reads none of the deny files.** If production runs nginx, that layer contributes nothing. The layer that actually holds is path unguessability plus the fact that reads go through an authorized endpoint at all — neither depends on server configuration. A Site Health check warns when the directory cannot be proven blocked, and the nginx `location` snippet belongs in the deployment notes. Recorded in [known-issues.md](../known-issues.md).
- Files for campaigns terminal more than 90 days are purged by the Phase 7 retention job. The policy exists now so that job has something to implement rather than something to invent.
- **On SVG specifically:** `svg-support` is active on this site, so WordPress will accept SVG uploads generally. An accepted SVG is an XML document with `<script>` support rendering inline on a public page — stored XSS against every visitor. Our allowlist is independent of `upload_mimes` precisely so a site-wide setting cannot re-open this. SVG creative would need a real sanitizer and its own ADR.
- **On the pixel-cap ordering:** a decompression bomb is a small file that expands to gigabytes in memory. GD allocates during `wp_generate_attachment_metadata()`, so a validator running afterwards never runs at all — the request is already dead. Dimensions come from `getimagesize()`, which reads only the header.

## Alternatives rejected

**Straight into the Media Library.** Public URLs for unreviewed commercial artwork, and advertisers needing `upload_files`, which hands them the whole library.

**Media Library plus a filename nobody can guess.** Security by obscurity on a URL that is permanent, un-revocable, and handed to a CDN.

**Attachment posts marked private.** `post_status` on an attachment gates the *post*, not the file. The URL still serves the bytes to anyone.

**Redirecting to a signed, expiring URL.** Better than a bare redirect, and still hands out a credential that leaves our control. Streaming is simpler and strictly stronger.

**Promoting at upload rather than at approval.** Puts rejected and abandoned creative permanently in the library, and removes the checkpoint where the reviewed bytes are proven to be the published bytes.
