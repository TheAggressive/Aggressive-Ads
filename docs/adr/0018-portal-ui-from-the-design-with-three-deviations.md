# ADR-0018 — The portal UI follows the design, with three deviations

**Status:** Accepted — 2026-08-10

Refines [ADR-0017](0017-self-contained-design-tokens.md). It does not supersede it: the semantic token layer, literal values, host-theme isolation, and scoped reset are unchanged. What changes is the inventory of status tokens and three specific values.

## Context

The portal's visual design arrived as a Claude Design project, `Advertiser Portal.dc.html` — a dark navigation rail, a warm off-white canvas, panelled content, status pills, and a dashboard of tiles. It is a good design and the portal implements it.

Three things in it could not ship as drawn.

## Decision

**1. The web font is not fetched from a third party.**

The design loads Archivo from Google Fonts. A `<link>` to `fonts.googleapis.com` sends every advertiser's IP address to Google on every screen of a logged-in area — a live data-protection question for any EU visitor, and a German court has already found the equivalent unlawful. It is also a render-blocking request to a host we do not control, on the critical path of every page.

`--laao-ads-font` names `"Archivo"` first and falls back to the system stack. Archivo is SIL OFL, so self-hosting the woff2 is permitted and is the intended follow-up; until then the system stack carries the layout.

**2. The dashboard shows no impressions, clicks, CTR or spend.**

The design's dashboard leads with four metric tiles. Reporting is a later phase and there is no data behind any of them. A dashboard that invents business figures is worse than one showing fewer real ones, because somebody makes a decision on them and nothing about the screen says not to. The tiles that ship count campaigns by state, which the plugin actually knows.

**3. The status inks are darker than drawn, and the accent has a second value.**

Measured against WCAG 2.2 AA, six of the design's pairings failed for text at their rendered size:

| Pair | Design | Shipped |
|---|---|---|
| pending on its tint | 2.61:1 | 4.66:1 |
| ended on its tint | 3.12:1 | 4.64:1 |
| text-subtle on the sunken surface | 3.12:1 | 4.64:1 |
| white on the accent | 3.55:1 | 4.64:1 |
| live on its tint | 3.60:1 | 4.64:1 |
| danger on its tint | 3.75:1 | 4.60:1 |

A pill is 12px bold and a button label 14px bold. Neither is "large text" as WCAG defines it (18.66px bold, or 24px), so neither gets the 3:1 allowance — 4.5:1 is the bar.

**The tints are untouched.** Only the inks moved, so the screens still read as the design intended; they are simply legible. The accent keeps its drawn `#ff3b2f` wherever it marks state rather than carries text — the active rail chip, the focus ring — and `--laao-ads-color-accent-strong` is the darker red used behind white button text.

The status vocabulary is `live / pending / ended / danger / neutral` rather than ADR-0017's illustrative `success / warning / danger`. Campaign status is what these describe, and "a paused campaign is a warning" is a sentence nobody would write.

## Consequences

- `tests/php/Unit/Assets/PortalContrastTest.php` measures every ink against every surface it can land on, the cross product rather than the pairs in use today, plus each pill against its own tint. Restoring any design value fails the suite with the measured ratio in the message.
- The portal looks slightly heavier than the mock. That is the trade, and it is the right way round.
- Self-hosting Archivo is outstanding. Until it lands, the portal renders in the system stack on every machine that does not already have Archivo installed — which is nearly all of them.
- A future reader comparing the running portal against the design file will find the colours do not match. This record is why.

## Alternatives rejected

**Ship the design's palette and note the contrast issue.** Accessibility is a release blocker in this project, not a backlog item. A noted failure is a failure.

**Enlarge the pill and button text to earn the 3:1 allowance.** It would change the design far more visibly than darkening an ink, to buy a weaker guarantee.

**Preload the Google font rather than link it.** Preloading changes when the request happens, not who receives the IP address.
