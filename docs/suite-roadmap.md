# Aggressive Ads — suite direction

Working identity: **Aggressive Ads** / `aggr_`. Tagline: **Live means live.**

This document records the durable product direction for the white-label
advertising suite. It is **not** the live backlog. Actionable work is tracked in
GitHub Issues; see [work-tracking.md](work-tracking.md).

The original suite closeout work is largely shipped. The platform expansion
beyond it is tracked in
[platform-implementation-progress.md](platform-implementation-progress.md).

## Product shape

Aggressive Ads is one WordPress advertising product with two deliberately
separate experiences:

- a dedicated advertiser portal outside wp-admin; and
- capability-gated publisher/staff administration inside wp-admin.

The plugin owns the campaign, inventory, serving, measurement and direct-sales
workflow. External systems may extend delivery, payment or sales operations but
must not become mandatory for native direct sales.

### Staff control plane

One Advertising menu, with distinct capabilities for review, organizations,
placements, packages, reports and settings. Shell unification never implies
permission unification.

Brand settings own the advertiser-facing display name, logo, tagline and design
tokens. Prefixes, post-type identifiers and internal API namespaces remain
stable and are not tenant-configurable.

### Advertiser portal

Advertisers never need wp-admin. Authentication, campaign creation, creative
management, organization/account management, review feedback and reporting are
presented through the portal while WordPress core remains authoritative for
users, password hashing and sessions.

Critical advertiser workflows remain functional without JavaScript where
practical; JavaScript enhances rather than invents the only path through a
business transition.

## Native delivery

Editors place a **slot**, never a campaign:

- the Aggressive Ads placement block;
- `aggr_placement( 'slot-slug' )`; or
- the equivalent shortcode/render path.

Cached page HTML reserves the slot. Selection and counting happen outside the
page-cache artifact so a cached page does not freeze one creative or one metric
increment into every view.

Native delivery is the baseline provider and remains usable with every optional
external provider disabled.

## Decisioning and measurement

The current platform has moved beyond simple rotation. The serving pipeline now
owns eligibility, exact schedule/daypart evaluation, targeting, frequency,
pacing, priority, weighted creative selection and page-level coordination.

Measurement distinguishes request, fill/no-fill, served, viewable, click and
conversion facts with rollup-backed reporting. Completed phase evidence belongs
in the detailed platform docs; this suite document does not duplicate it.

## Direct-sales direction

The product is deliberately optimized for publishers that sell their own
inventory and need a coherent lifecycle:

> inventory → availability → commercial terms → campaign → creative review →
> delivery → measurement → reporting → renewal/make-good

The competitive expansion is tracked under #258. The main product issues are:

- #279 scheduled white-label advertiser reports;
- #280 advertiser inventory storefront and booking;
- #281 reusable campaign templates / sales-product presets;
- #282 proposal, quote and insertion-order workflow;
- #283 publisher sales and revenue dashboard;
- #284 deterministic renewal and sales intelligence;
- #285 CRM integration boundary;
- #286 cross-channel campaign and line-item model;
- #287 newsletter advertising; and
- #288 sponsored-content/native-sponsorship workflow.

These are sequenced by dependency in
[work-tracking.md](work-tracking.md), not by their issue numbers.

## External-system boundaries

Aggressive Ads may integrate with payment providers, CRMs, email platforms,
GAM, Prebid/OpenRTB, VAST-compatible video providers and other services, but the
core product stays whole when they are unavailable or disabled.

Durable rules:

- delivery never waits on a payment provider or CRM;
- external providers advertise capabilities rather than leaking product-name
  conditionals into the core domain;
- provider/channel metrics retain source and freshness provenance;
- external markup remains untrusted;
- native guarantees are not silently outranked by external demand;
- privacy/consent gates identifier use before collection/disclosure;
- remote systems never gain authority merely because they can send a webhook or
  API request; and
- Campaign remains the commercial umbrella as additional channels arrive.

## Modern WordPress direction

Prefer WordPress-native primitives where they strengthen the product:
repositories around private data models, Script Modules/Interactivity for
progressive UI, REST with explicit permission callbacks, block-based placement,
object-cache APIs and WordPress user/authentication infrastructure.

Avoid architecture that exists only to imitate a generic SaaS stack: a mandatory
React wp-admin SPA, a second presentation theme, counting inside cached page
HTML, provider-specific core entities, or tenant-configurable internal prefixes.

## Where actionable work lives

Do not add implementation checklists to this file.

- Platform phase status: [platform-implementation-progress.md](platform-implementation-progress.md)
- Work-tracking rules/order: [work-tracking.md](work-tracking.md)
- Competitive product umbrella: #258
- Tracking migration: #259

When a durable suite principle changes, update this document. When a task needs
to become done, create or update an Issue instead.