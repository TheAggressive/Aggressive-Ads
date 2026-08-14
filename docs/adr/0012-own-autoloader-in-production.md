# ADR-0012 — The plugin's own autoloader is the production autoloader

**Status:** Accepted — 2026-08-08

## Context

[ADR-0011](0011-no-composer-runtime-dependencies.md) means a shipped build has no `vendor/` and therefore no `vendor/autoload.php`. Something still has to turn `LAAO_Advertiser_Portal\Workflow\Campaign_State_Machine` into a file path.

There is also a WordPress-specific wrinkle: WordPress Coding Standards mandate `class-{name}.php` filenames in lowercase with hyphens, while PSR-4 mandates `{ClassName}.php`. A Composer PSR-4 autoloader cannot resolve WPCS filenames without a classmap that must be regenerated on every new class — and a stale classmap fails as "class not found" for a file that is plainly sitting right there.

## Decision

`inc/class-autoloader.php` is the production autoloader, `require`d directly from the root plugin file. It maps:

```
LAAO_Advertiser_Portal\Workflow\Campaign_State_Machine
        →  inc/Workflow/class-campaign-state-machine.php
```

Namespace segments after the root map to directories verbatim; the final segment lowercases, underscores become hyphens, and one of the WPCS file prefixes — `class-`, `interface-`, `trait-`, `enum-` — is applied. They are tried in that order, so `Core\Service` finds `inc/Core/interface-service.php`. The extra `is_file()` checks are paid once per class, on a miss, at first load.

Every namespace segment must match `/^[A-Za-z0-9_]+$/`. Anything else is not a legal PHP identifier and so cannot name a real class — which also means no caller-supplied string can walk out of the base directory with `..` or a path separator.

It registers only for its own prefix and returns immediately for anything else, so it never participates in resolving another plugin's classes.

Composer's autoloader exists for **dev and tests only**. `bin/release/verify-package.sh` lists `inc/class-autoloader.php` in `PACKAGE_REQUIRED` and fails the release if it is missing from the actual ZIP, because dropping it produces a plugin that fatals on activation with an error naming a class rather than the missing loader.

## Consequences

- WPCS filenames and namespaced classes coexist with no classmap to regenerate and no build step between writing a class and using it.
- Zero third-party code in the load path, and one fewer thing that can conflict with another plugin.
- The mapping is mechanical and unit-tested, including the cases people get wrong: a class in the root namespace, a sub-namespaced interface, a namespace that merely *starts* with our prefix, and traversal segments.
- `resolve()` is separate from `autoload()` and free of side effects, so the mapping — the part that is easy to get subtly wrong — is testable without loading anything. The traversal test deliberately points a `..` segment at a file that genuinely exists one level up; an earlier version asserted null against a path where nothing existed, and passed with the guard removed.
- Adding a class is creating a file. Nothing to register, nothing to dump.
- The autoloader is load-bearing infrastructure that cannot be dropped from a package, which is why the packaging check names it explicitly rather than trusting the exclude list.

## Alternatives rejected

**Composer PSR-4 in production.** Requires shipping `vendor/`, contradicting [ADR-0011](0011-no-composer-runtime-dependencies.md), and requires PSR-4 filenames, contradicting WPCS.

**Composer classmap in production.** Same `vendor/` problem, plus a regeneration step whose omission produces a confusing runtime failure.

**Explicit `require_once` for every class.** Honest and unmaintainable past about thirty files, and it loads every class on every request including the admin-only ones on a front-end page view.

**Renaming files to PSR-4 and disabling the WPCS filename sniff.** Trades a 40-line autoloader for a permanent deviation from the standard the rest of the PHPCS configuration enforces, and makes every future contributor's editor scaffolding wrong.
