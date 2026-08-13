# ADR-0024 — White-label is token values, contrast-gated

**Status:** Accepted — 2026-08-12

Amends [0017](0017-self-contained-design-tokens.md) and
[0018](0018-portal-ui-from-the-design-with-three-deviations.md) on *who
sets the values*, not on the token architecture. Prefixes stay `aggr_`.
Does not supersede 0017.

## Context

ADR-0022 made the advertiser-facing default “Advertising”, not “Aggressive
Ads”. The rail and sign-in screens still hardcode “LAAO”. A museum must be
able to put its name and colours on the portal without forking CSS or
renaming post types.

## Decision

Brand settings (product name, tagline, optional logo URL, accent, accent-strong,
canvas, surface, text) live in `aggr_settings` under the schema in ADR-0023.

**Save rejects WCAG 2.2 AA failures** for the pairs those tokens actually
carry: text on canvas, text on surface, white on accent-strong, accent-strong
on surface. The bar is 4.5:1, same as `PortalContrastTest`. The check is
`Domain\Contrast` — no WordPress, so it is unit-tested without a bootstrap.

Overrides are written as inline `--aggr-*` on `.aggr-portal` after the
compiled stylesheet. Token *names* never change per tenant. An empty logo
falls back to the product name as the mark. An empty tagline omits the
subtitle.

The LAAO theme may still override tokens (ADR-0017). Inline brand values
win on `.aggr-portal` for the keys Brand owns, which is the tenant’s
deliberate choice.

## Consequences

- Contrast-gated save is a release blocker, not a warning banner.
- Logo is a URL, not a Media Library dependency in the portal. Staff can
  paste a media URL they already uploaded in wp-admin.
- Status pill inks are not Brand fields. Changing “pending” per tenant is
  how a dashboard becomes unreadable; those stay in the stylesheet.

## Alternatives rejected

**A second CSS theme per tenant.** Forks the component layer. Forbidden by
the suite outline.

**Allowing a failing palette with a warning.** Accessibility is a release
blocker here. A noted failure is a failure.

**Making the code prefix a setting.** Schema is not white-label (ADR-0022).
