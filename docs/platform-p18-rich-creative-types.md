# P18 — Rich creative types

## Status

- Phase: **P18 — Rich creative types**; tracking: #262.
- Roadmap state: `[ ]`; definition tracked in #372.
- Source audit: 2026-11-13, against `6daf745` (PR #371).
- Authority: the supported runtime matrix and verification lanes in
  [build and release](build-and-release.md), including Chromium and WebKit.

This defines the phase contract and its acceptance requirements. Responsive
image implementation is tracked in #374 and described below.
HTML5, tags and video remain separate follow-ups (#375–#377). This is not phase
exit evidence; script and external modes retain their browser/P27 gates.

### Responsive image implementation

The image handler accepts a required base raster and up to five optional
`renditions[]` files through the existing authorized upload/replacement
workflows. Sizes come from the placement's P15 map, including its stored base
size. Every file passes the existing raster validator and placement limit;
the set also has a 2 MiB aggregate limit. Each set remains one creative revision,
one weighted candidate and one signed delivery identity.

One encrypted, bounded source artifact holds the exact raster bytes. The
revision's versioned JSON manifest binds every rendition's dimensions, MIME,
length and checksum. Publication verifies the complete source before writing
public files, creates real marked Media Library attachments for each member,
and records a source-bound receipt. Partial publication removes only resources
created by that attempt and preserves the private source for retry.

The receipt includes a durable staff actor, timestamp and digest covering the
revision, site/organization/parents, resources, destination and description.
That proof is required before the assignment becomes eligible. Existing P17
timeline/audit writers retain their existing failure semantics; their presence
alone does not authorize image-set delivery. This keeps artifact approval
evidence in the same committed receipt as publication without adding another
approval workflow. A text revision preserves resource references but must
receive a new proof for its new revision and text before serving.

Assignment projection stores the format version, available size keys and the
approved manifest hash. Candidate selection never loads every manifest: one
bounded indexed read resolves the winning revision. Both single and batch fill
use the same versioned image descriptor. Token consumption independently checks
the current source/publication/review binding. Legacy images keep their existing
attachment compatibility path; unknown/new malformed records fail closed.

Private preview renders every rendition as an authorized raster in the existing
empty sandbox. Copy/renew reconstruct the complete reviewed source after private
purge; copying to another placement revalidates every member against its map.
No archive ingestion, executable renderer or runtime dependency is introduced.

The executable evidence for this slice lives in `ImageSetTest`,
`ResponsiveImagesTest`, `ImageProjectionUpgradeTest`, `SiteScopedTenancyTest`,
and the shared `responsive-images-cases.ts` browser cases for Chromium/WebKit.
It covers actual upload → staff approval → assignment → single/batch fill,
private/public rendition bytes, denied ownership/publication/nonces, independent
database claims, compensation/retry, six-member and exact 2 MiB boundaries,
real MySQL DDL failure/retry and 100-row migration batches. The 500-candidate
fixture checks the existing 12 cold / 4 warm / 8 token-query ceilings, a 1 MiB
candidate-cache ceiling and 16 KiB slot payload ceiling. These are checked
fixture bounds, not a hosting-capacity certification.

Removing token review binding, source serialization or member checksum guards
made their regression tests fail. Removing renderer dispatch made all four
unsupported-descriptor tests fail. Each guard was restored before verification.
An additional real-workflow interleaving test completes a second replacement
during the first upload's inspection; the stale writer is refused and the
completed source survives. Removing the expected-source hash check makes that
regression fail as well.
Both real private-image REST routes also reject nested/malformed rendition
query values; removing the declared size validation makes that regression fail.
The browser cases exercise explicit and scriptless ingestion, invalid nonce
refusal, 320px reflow/axe, the unchanged empty sandbox, both served image sizes
and one impression request per rendered page. Full CI remains required for the
implementation PR; these tests do not qualify executable HTML5 or third-party
network access.

Local verification for this implementation passed the complete `qa:fast` gate,
1,367 PHP unit tests, 2,069 native WordPress integration tests, 11 multisite
tests, 227 JavaScript tests and 71 browser cases. Locked dependency audits found
no new advisories. The Studio runner restored its disposable database/uploads;
the working site's data was not used as a test fixture. GitHub's full CI and
installed-artifact checks are separate merge requirements.

## Outcome and scope

Publishers can review and deliver responsive image sets, local HTML5 packages,
staff-controlled tags and a declared native VAST video profile through the
existing Campaign → line item → asset → revision → assignment model.

P18 owns format validation, publication, rendering, isolation and fallback.
It does not add another selector, Campaign model, approval workflow, report
ledger or provider registry. P24 owns the general provider registry; P27 owns
privacy/consent. No payment, auction, external player SDK, runtime Composer
package or remote font service becomes necessary for native image delivery.

## Entry criteria and current seams

P2 and [P17](platform-p17-creative-experience.md) are complete. Keep their revision,
ownership, variant, approval and measurement guarantees. PR #371's complete CI
baseline is green; rerun the required baseline for each implementation head.

The table records the P17 seams audited before #374. Responsive images now use
the concrete handler above; a general provider registry is still outside P18:

| Existing authority | P18 change required |
|---|---|
| `Creative_Manager` and `Creative_Uploader` accept one image | Dispatch format validation inside the existing authorized workflow. |
| `Creative_Promoter` publishes an image attachment | Publish all reviewed resources and record a verifiable publication receipt. |
| `Revision_Policy`, coverage and eligibility use image/attachment facts | Resolve typed publication readiness without a fake attachment id. |
| `Decision_Engine::payload_from_row()` emits an image URL | Emit a bounded, versioned descriptor for the selected revision. |
| `fill.js` paints an anchor and image | Dispatch a known renderer after the existing decision; retain its fallback and measurement ordering. |
| `Preview_Frame` and file routes render private images | Use format-specific isolation without widening the image policy. |

P15 already resolves viewport widths to placement sizes. Reuse that resolution;
responsive creative resources are not new placements or separately weighted
variants. The first handler abstraction must ship with its actual responsive
image caller, not as an unused extensibility framework.

## Canonical model and ownership

| Fact | Authoritative writer |
|---|---|
| Asset identity and organization | Existing asset workflow/repository. |
| Revision kind, source/resource hashes, manifest version, click URL, alternative text and fallback | Existing creative workflow, through a format validator. Frozen at approval. |
| Published resource references and compiled-artifact hash | Existing approval/promotion workflow, after re-verification of every resource. |
| Assignment parentage, weight, schedule and status | Existing assignment/share workflows. |
| Delivery counters and review history | Existing event/projector and decision writers. |

Store a versioned resource manifest on the revision. It names generated resource
references, detected types, dimensions, byte counts and hashes; it is not raw
client JSON. Publication is an immutable receipt over that exact manifest and
artifact, not an independent mutable `approved` flag. Review history alone cannot
make missing or unpublished resources servable.

The assignment projection carries only bounded format/readiness/size facts and
the revision reference. It must not copy HTML, tag source or full resource
manifests into every candidate. Load at most the winning revision's manifest.
Unknown versions or unavailable handlers are ineligible and use safe fallback.

## Supported format contracts

These are initial product profiles, not claims of full HTML5 or VAST support.

| Format | Input and review | Rendering and failure |
|---|---|---|
| Existing image | Existing raster allowlist, size/pixel/byte checks and approval. | Existing image/link behavior and attribution remain compatible. |
| Responsive image | At most six raster renditions in one revision; distinct sizes from the placement map, including its base size. All resources reviewed together. | Server selects an approved rendition for the resolved placement size. No resampling, new selection algorithm or extra impression on resize. Missing size uses house/no-fill. |
| Local HTML5 | Staff upload only initially; one ZIP with `index.html`, local CSS, classic scripts and allowed raster resources. No remote imports, workers, module imports, fonts, nested documents or server programs. | Compiled, bounded document in an isolated frame. A separately approved raster fallback is mandatory. Script execution has the additional qualification gate below. |
| Third-party tag | Staff only, explicit site-admin opt-in, disabled by default. Review the exact tag bytes, declared origins/purposes and raster fallback. | Never insert markup/script into the publisher DOM. Isolated renderer and P27 authorization are mandatory; unknown/denied state renders fallback without contacting the vendor. |
| Native video | Staff imports VAST 4.3 XML: one `InLine` Ad, one Linear creative, progressive MP4/WebM, approved poster/fallback and accessible controls. | Use the native video element; start by user action. No unbounded XML resolution or external SDK. Native impression/viewability remains distinct from playback events. |

Native video's first profile rejects Wrappers, ad pods, executable verification,
VPAID/SIMID, non-linear units and unknown required extensions explicitly. It
does not silently discard required trackers and call itself a conforming general
VAST player. An unsupported document is refused; an approved fallback remains
available. Extend this profile only with a new tested contract. See the
[IAB VAST specification](https://github.com/InteractiveAdvertisingBureau/VAST4.x/blob/main/4.3.md).

Use publisher-controlled public media for the initial video profile. Copy and
hash the reviewed resource into revision-owned publication storage; a mutable
Media Library URL alone is not a frozen revision. External media/tracking is a
separate, P27-gated mode. No server-side VAST URL fetch or wrapper resolution is
introduced by the initial profile.

## Invariants

- Derive organization/site scope from membership and verified parents. Check
  each resource, revision, assignment and fallback; an id is not authorization.
- Preserve `Revision_Policy` as the freeze authority. Source, compiled output,
  policy/origin changes or rendition changes require a new full review. A
  text-only classification is derived from all reviewed resource/policy hashes.
- Re-verify all hashes before publication. Activate no assignment until the
  publication receipt, review decision and required audit records are durable.
- Advertisers retain image-only kind authority, including responsive raster
  sets. Staff HTML5/tag/video ingestion requires review authority; publication
  independently requires the existing publish capability. Do not rename legacy
  capabilities or broaden roles as an incidental format change.
- Keep one native decision pipeline, signed identity, click hop and report
  grain. Count a responsive set as its revision, not its individual files.
- Never trust a child's message for ownership, approval, destination, consent
  or viewability. Preview writes no delivery event and contacts no live vendor.
- A new resource or failed read is not permission to replace a live revision.
  Retain the last approved assignment on ingestion/publication failure.

## Archive, parser and resource limits

These are design caps to implement and measure, not measured capacity claims.
The publisher may tighten limits; it cannot raise the hard ceilings implicitly.

| Resource | Hard bound |
|---|---:|
| Responsive set | 6 renditions, 2 MiB aggregate; existing per-image placement and 25,000,000-pixel limits remain |
| HTML5 compressed input and compiled document | Each within the placement byte limit, never above 2 MiB |
| ZIP input work | 128 entries, 8 MiB actual expanded bytes, 2 MiB per entry, maximum 100:1 expansion |
| ZIP path | 128 ASCII bytes, 5 directory levels; one root `index.html` |
| Manifest / emitted decision descriptor | 16 KiB each; no embedded document/media body in fill JSON |
| Script blocks | 32; generated policy/header at most 8 KiB |
| VAST XML | 64 KiB, depth 16, 512 elements; one Ad/Linear; no network/entity expansion |
| Native video publication | 20 MiB, 60 seconds, at most 1920×1080; validate media metadata before publication |
| Ingest work | 5 seconds processing deadline; at most 64 MiB peak memory above bootstrap, excluding streamed media files |

Check actual bytes while reading/decompressing; a ZIP directory's declared sizes
are not sufficient. Reject absolute/drive/UNC paths, backslashes, `..`, NULs,
encoded/ambiguous paths, duplicate or case-colliding names, symlinks/special
files, encrypted archives, nested archives and unknown compression methods.
Reject dotfiles, `.htaccess`, `.user.ini`, PHP/server code and SVG. Never use a
general extraction operation that writes attacker-selected paths.

Stage bounded streams beneath a generated private directory outside served
content, with owner-only permissions. Never execute, include or shell out to an
archive member. Encrypt durable private resources through existing storage;
delete temporary plaintext in `finally` and make orphan cleanup retry-safe.
Malformed XML, DTDs and entities are refused before normalization. No parser may
fetch a URL. This applies the [OWASP upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)
to a deliberately small supported archive profile.

## Isolation and privacy qualification

Keep raster preview's current empty sandbox. Do not globally change
`Preview_Frame::SANDBOX` to enable scripts for every preview.

For executable formats, use a trusted wrapper that places the compiled document
in an opaque child frame. The concrete design to qualify is a Blob child with
`sandbox="allow-scripts"`, no `allow-same-origin`, and an enforcing wrapper CSP
whose `frame-src` permits only Blob children. That wrapper blocks the child's
navigation to WordPress or remote HTTP documents; it must never interpolate
creative markup into its own DOM/script. Revoke Blob URLs on teardown. Private
preview embeds authorized bytes, not a reusable public/private bearer URL.

The child's policy starts at `default-src 'none'`, allows only reviewed script
hashes and inline styles needed by the supported profile, and denies connection,
object, worker, frame, base and form capabilities. No `unsafe-eval`, permissive
script source or wildcard origin. Block forms, popups, downloads, top navigation
and permission-dependent device APIs. Keep referrers suppressed. If a required
browser control is unsupported, use the raster fallback rather than relaxing it.

Do not serve uploaded/compiled hostile HTML as a directly navigable
WordPress-origin document. Public entry points emit the trusted wrapper only;
raw source downloads use attachment disposition and `nosniff`. Test direct
navigation as well as framing. The [HTML sandbox rules](https://html.spec.whatwg.org/multipage/iframe-embed-object.html#attr-iframe-sandbox)
explain why script plus same-origin permission is unacceptable for same-origin
content. Enforce response policies; CSP's sandbox directive is not enforced by
a report-only header or a meta element. See [CSP sandbox](https://www.w3.org/TR/CSP/#directive-sandbox).

**CSP and an opaque origin are not a complete network/privacy boundary.**
Qualify navigation, DNS hints, WebRTC/STUN, WebSocket/fetch/beacon, cookies,
storage and child-created contexts in every supported engine. In particular,
the [draft CSP WebRTC directive](https://www.w3.org/TR/CSP/#directive-webrtc)
is not assumed to work merely because it appears in the specification.
Static source scanning or replacing a JavaScript global is not that boundary.

Script-enabled HTML5 and live third-party execution remain blocked until these
controls are qualified and P27 authorizes the actual recipients/purposes.
Script-free, self-contained HTML5 can ship independently. External tag/media/
tracking modes require the same P27 gate; absence, unknown state, refusal or
withdrawal means no external request and immediate safe fallback. Cached fills
cannot carry an earlier visitor's consent. Credentials, user identifiers,
consent strings and native measurement tokens never enter creative source.

The approved tag manifest freezes the local integration and declared trust
policy; it cannot promise that a vendor's future remote response is immutable.
Review must disclose that distinction. Do not claim remote content received the
same byte-for-byte review as native published resources.

## Migration and compatibility

This definition changes no schema. Implementation will add revision manifests
and bounded assignment projection fields; allocate the next schema version at
that time, through the existing migration map. No second creative table/model
or general provider registry is required.

Existing images normalize to the image handler from their current attachment,
dimensions and revision. Preserve ids, click attribution, historical counters
and review history. No historical row is guessed into a rich format and no
advertiser recreates valid artwork. Legacy/unknown `code`, `text` or `html5`
values do not become executable through migration.

Projection backfill processes at most 100 assignments per batch, with an
idempotent cursor, failed-record diagnostics and missing-schedule repair.
Failed reads/writes do not advance progress. Existing images keep serving while
backfill is incomplete; new formats remain gated until their schema and
publication readers are ready. Prove fresh install, real-version upgrade,
interruption/resume, duplicate races, multisite/new-site installation and
site deletion. Schema/index changes need actual MySQL evidence.

Before code rollback, disable rich formats and invalidate their delivery
caches. Preserve manifests/history; never lower schema markers or drop data.
Test old-code image serving and refusal/fallback for new formats. Compatibility
normalization remains while supported revisions or rollback targets need it.

## Workflows and API

Extend existing create/revise, preview, approve/reject, assign, retire and delete
operations. Each verifies capability, parentage, site/organization, edit window,
raw input and expected revision. HTML forms retain action-specific nonces and
the no-JS submission path; REST retains its permission checks. Use the existing
upload/transition limits; a bundle cannot multiply the attempt allowance by its
file count. Failed counter storage refuses the operation.

Advertiser read models omit private paths, tag source, policy internals and
staff notes. Missing/foreign resources remain non-enumerating. Any new route or
form must appear in the closed endpoint inventory and its guard tests. Shared
serializers must not return raw manifests merely to make a preview convenient.

## Failure, recovery and measurement

Validate and stage before publishing. Compensate partial publication, preserve
the old assignment, record a bounded failure and allow an idempotent retry.
Reference-aware cleanup must never remove another revision's resources.

Browser renderers have a 4-second preparation/startup deadline. Failure, blocked
policy, unsupported media/handler or unavailable publication uses the approved
raster fallback, then the existing house/no-fill policy. A stopped video gets
a bounded stall watchdog; teardown cancels timers/listeners and revokes resources.
Do not wait for a provider during a PHP fill; the initial profiles make zero
server-side external calls during delivery, preview or validation.

Only the successfully displayed creative/fallback reaches native impression and
viewability measurement, with the selected revision's existing signed identity.
No success beacon for a timed-out render, no second impression from poster →
video, and no billing/event assertions taken from arbitrary frame messages.
Video starts/completions must be separately named, source-labelled facts if
implemented; they are not native impressions or evidence of unique reach.

## Performance and operations

Preserve the [delivery budget](delivery-performance.md): cold fill ≤12 database
queries, warm fill ≤4, authoritative token validation ≤8. Keep the existing
500-candidate cap and indexed placement reads. At most one additional indexed
winning-manifest read must fit those budgets; no per-candidate resource queries.
No archive, XML parsing, hashing or provider call belongs on the fill hot path.

Measure at least 1,000 campaigns with mixed formats, maximum-size manifests,
500 eligible candidates, six-rendition sets and maximum-boundary archives.
Exercise cold/warm caches and concurrent invalidation. Record p50/p95/p99 using
the existing load harness and its environment; retain its acceptance thresholds.
Measure actual persistent-cache item bytes and ingest memory/work deadlines.

Site Health reports handler/schema readiness, publication integrity, disabled
external modes, privacy qualification and cleanup/backfill schedule health.
Use stable reason codes and bounded structured ids in logs/audit; never log raw
source, paths, signed URLs, vendor credentials, visitor data or XML bodies.
The runbook must cover failed publication, key loss, integrity mismatch, worker
repair, format disable, cache purge and rollback. No health probe contacts a live
ad vendor or executes untrusted creative in an administrator's origin.

## Accessibility and internationalization

Review screens expose kind, sizes, resource limits and failure reasons without
color alone. Preserve labelled uploads, keyboard review, focus recovery, 320px
reflow and translations. Preview has a meaningful frame title and cannot trap
focus. Native video needs accessible controls, a poster, a text alternative and
captions for speech; do not autoplay audio. Respect reduced motion through a
static fallback. All critical ingestion/review actions retain server-rendered
forms; browser delivery's existing JavaScript requirement is unchanged.

## Required executable evidence

| Boundary | Evidence required before that slice ships |
|---|---|
| Real composition | Writers → frozen revision → publication → assignment → production single/batch fill → actual renderer; no fixture-invented readiness or kind fields. |
| Authorization | Advertiser/staff separation, foreign/site-mismatched parents and resources, nonce/REST checks and attached limiter guards. Remove guards to prove tests fail. |
| Resource validation | Boundary byte/pixel/entry/depth/deadline cases, forged ZIP sizes, expansion bombs, traversal/collisions/symlinks, XML entities and unsupported profiles. |
| Review integrity | Source/manifest/output tampering, text-only misclassification, publication/write failure, concurrent approval/replacement, retry and deletion/reference races. |
| Isolation/privacy | Real Chromium/WebKit assertions for wrapper/child policies, direct navigation, parent/cookie/storage access, navigation/popup/download/message abuse and network attempts including local STUN. Missing controls must select fallback. |
| Client contract | Server descriptor keys and browser readers, responsive sizes, single/batch parity, deadline and cleanup, no phantom/double beacons, fallback attribution and no live preview measurement. |
| Storage/scale | Actual MySQL schema/query plans, cache bytes, large fixtures, migration/rollback, bounded file reads/cleanup and multisite lifecycle. |
| UI/operations | No-JS upload/review, keyboard/reflow/axe, reduced motion/captions, translated errors, health diagnostics and recovery. |
| Baseline/package | Full required PHP/JS/static/security/browser/package checks; installed artifact includes every renderer and format resource. |

The local [design probe](../bin/dev/probe-p18-isolation.mjs) exercised opaque
Blob frames with enforced hash policies. Chromium 151.0.7922.34 and WebKit 26.5
ran child scripts, refused parent DOM access and blocked HTTP fetch/image/frame
navigation attempts. Both reported the draft `webrtc` directive as unrecognized
and sent local STUN packets (two and three respectively). Thus the tested
wrapper is **not qualified for script-enabled mode**. No external service was
contacted. Reproduce with installed browsers:

```bash
node bin/dev/probe-p18-isolation.mjs
```

The probe exits nonzero for an observed escape; it is research, not a passing
CI gate or production integration test. A future zero exit covers only its
fixture. The executable suite above remains required, including a solution to
the observed network escape before script mode can be enabled.

## Implementation sequence and documentation

1. Image handler/publication compatibility plus a real responsive-set workflow,
   bounded projection migration, delivery and private preview.
2. Staff local HTML5 ingestion, archive budgets and qualified isolation/fallback.
3. Disabled-by-default staff tags, with P27 and browser qualification before any
   live external/script mode can be enabled.
4. Native VAST video profile and explicit provider failure/fallback behavior;
   external modes retain the same P27 gate.

The last two slices can proceed independently after the shared publication work;
no slice closes the parent phase alone. Keep actionable scope in child Issues.
Update domain/schema/API, threat model, administration, runbook, testing and
delivery budgets when behavior ships. Keep this document and the phase index
honest about implemented, gated and unqualified modes.

## Exit criteria and decision

P18 remains open until all four format contracts work through the real workflow,
the migration/rollback and resource bounds are proven, privacy/isolation gates
are resolved for promised executable modes, required operations/accessibility
and complete baseline evidence pass, and shipped behavior is documented.

If P27 or browser controls still block a promised mode, record that dependency
and keep the phase open. Passing image tests or a header-string assertion cannot
close a rich-creative phase. No exit decision has been made.
