# ADR-0017 — Self-contained `--laao-ads-*` tokens with literal fallbacks

**Status:** Accepted — 2026-08-08

## Context

The portal should look native inside LAAO and must remain fully usable under any theme ([ADR-0001](0001-standalone-plugin-zero-theme-dependency.md)). Those pull in opposite directions.

WordPress offers an apparent bridge: theme.json generates `--wp--preset--color--*` and `--wp--preset--font-size--*` custom properties, so a plugin could style itself from the active theme's palette and inherit its look for free.

It works until the theme changes. **Twenty Twenty-Five and the LAAO theme expose different preset names.** A token defined as `var(--wp--preset--color--primary)` resolves on one and to nothing on the other — and an unresolved custom property in a `color` declaration renders as transparent text on a transparent background. Not a degraded look; an unreadable screen.

## Decision

The portal defines its own semantic token layer, `--laao-ads-*`, and **every token carries a literal value**. Nothing resolves through `--wp--preset--*` or any theme-defined property.

Tokens name roles, never values: `--laao-ads-color-surface`, `--laao-ads-color-surface-raised`, `--laao-ads-color-text`, `--laao-ads-color-text-muted`, `--laao-ads-color-accent`, `--laao-ads-color-success`, `--laao-ads-color-warning`, `--laao-ads-color-danger`, `--laao-ads-border-color`, `--laao-ads-radius-panel`, `--laao-ads-radius-control`, `--laao-ads-shadow-panel`, `--laao-ads-z-dialog`, `--laao-ads-control-min`.

The defaults are accessible on their own: contrast verified against WCAG 2.2 AA, and `--laao-ads-control-min` is 44px so every touch target meets the minimum without a component thinking about it.

**The LAAO theme may override any `--laao-ads-*` token.** That is the entire supported coupling — one direction, opt-in, and the portal is fully functional without it.

Two supporting rules:

- All portal CSS lives in `@layer laao-ads-*` cascade layers, so a theme's own rules can win where they should without a specificity fight.
- **The reset is scoped to `.laao-ads-portal`, never global.** A plugin that restyles `body` is a plugin that breaks the host site.

One top z-index token, enforced by `lint:files`, so dialog stacking has a single source of truth rather than an escalating series of magic numbers.

## Consequences

- The portal renders correctly under Twenty Twenty-Five, under LAAO, and under a theme nobody has written yet.
- Verified, not asserted: `tests/e2e/portal-smoke.spec.ts` runs under Twenty Twenty-Five with axe, so a token that silently resolves to nothing fails the accessibility scan rather than shipping.
- The portal does not automatically inherit LAAO's palette. Making it feel native is a deliberate, small block of overrides in the theme, which is also the only place a designer would look for it.
- Component CSS never contains a literal colour, radius, or shadow. A component asks for a role; the token layer decides what the role looks like. Overriding one token then changes every component consistently, which is the property that makes the theme override worth supporting at all.
- `prefers-reduced-motion: reduce` zeroes both duration tokens in one place, so no component has to remember.

## Alternatives rejected

**Consume `--wp--preset--*` directly.** Transparent text under any theme with different preset names. This is the specific failure the ADR exists to prevent.

**`var(--wp--preset--color--primary, #1a1a1a)` — theme value with a literal fallback.** Looks like the best of both and is worse than either: the portal's appearance becomes a function of whether a theme happens to use a particular preset name, so it renders one way here, another way there, and nobody can predict which without checking a theme.json.

**Import the LAAO theme's stylesheet.** A runtime theme dependency, forbidden by [ADR-0001](0001-standalone-plugin-zero-theme-dependency.md).

**Literal values in component CSS, no token layer.** No override point, so the theme coupling becomes a stylesheet fork, and a palette change becomes a find-and-replace across every component.
