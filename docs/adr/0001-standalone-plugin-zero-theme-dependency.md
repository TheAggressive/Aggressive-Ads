# ADR-0001 — Standalone plugin, zero theme dependency, proven by e2e

**Status:** Accepted — 2026-08-08

## Context

The portal's two closest relatives are themes: LAAO (the site it runs on) and Aggressive Apparel (where several of these patterns were first proven). Both contain code this plugin genuinely wants — the bootstrap shape, the service container, the dialog implementation, the CI parity discipline.

The cheap path is to `require` or `extend` from the LAAO theme. It works on day one, on this site, with this theme active.

It also means the advertising workflow of a business stops functioning when someone switches themes, when a theme update renames a class, or when the site is cloned to staging with a different theme active. A theme is presentation. Advertising revenue is not presentation.

## Decision

The plugin has **no runtime dependency on any theme**. It never calls a theme class, never `require`s a theme file, never assumes theme markup exists, and never resolves a design value through a theme-defined CSS custom property.

Architecture and engineering lessons are ported. Code is re-implemented under `LAAO_Advertiser_Portal\` with theme assumptions removed, and gets its own tests.

The single supported coupling runs one direction only: the LAAO theme **may** override `--laao-ads-*` custom properties to make the portal feel native. The portal is fully functional without that (see [ADR-0017](0017-self-contained-design-tokens.md)).

This claim is **enforced, not asserted**. `tests/e2e/portal-smoke.spec.ts` switches the active theme to Twenty Twenty-Five, logs in as an advertiser, loads the portal, and runs axe. An accidental theme dependency fails that test.

## Consequences

- Code that exists once in the theme exists twice in the repository tree. Accepted: the duplicate is a fork with different requirements, not a copy that must stay in sync.
- The portal owns its own document (`templates/portal/base.php` calls neither `get_header()` nor `get_footer()`), so it renders identically under classic and block themes.
- Under Twenty Twenty-Five there is no site header or navigation. Deliberate — the portal is an application surface, and full-bleed is the right presentation.
- Any future "just pull this from the theme" shortcut has a named test standing in the way, which is the point.

## Alternatives rejected

**Ship the portal inside the LAAO theme.** Fastest to build, and the reason it is wrong is a single sentence: switching themes must not take down campaign submission.

**Depend on the theme when present, degrade when absent.** Two rendering paths, one of which is exercised in production and one of which is exercised never. The untested path is the one that breaks during a theme migration, which is exactly when nobody has time for it.

**A shared library package required by both.** Real dependency isolation does not exist in WordPress ([ADR-0011](0011-no-composer-runtime-dependencies.md)). A shared `vendor/` between a theme and a plugin is a version conflict waiting for the next independent release of either.
