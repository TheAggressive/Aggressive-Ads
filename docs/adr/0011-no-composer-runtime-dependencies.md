# ADR-0011 — No Composer runtime dependencies; `vendor/` never ships

**Status:** Accepted — 2026-08-08

## Context

A plugin of this scope has obvious uses for packages: a state-machine library, a UUID generator, an HTTP client, an image manipulation layer, an HTML sanitizer, a logger.

WordPress has **no dependency isolation**. Every plugin's `vendor/autoload.php` loads into one PHP process with one global class namespace. Two plugins shipping different versions of the same package produce a fatal attributed to whichever loaded second — and the site owner, who installed two unrelated plugins, has no way to fix it.

## Decision

`composer.json` `require` is `{"php": ">=8.4"}` and nothing else. Composer is **dev-only tooling** — PHPUnit, PHPStan, PHPCS, and the polyfills. `vendor/` is excluded from the package, and `bin/release/package.sh` **hard-fails** if it reaches the staging directory.

Core provides the substitutions, named here so nobody reaches for a package later:

| Temptation | Core |
|---|---|
| `ramsey/uuid` | `wp_generate_uuid4()` |
| `symfony/http-foundation` | `WP_REST_Request` / `WP_REST_Response` |
| `monolog` | The audit table ([ADR-0003](0003-audit-log-in-custom-table.md)) |
| `guzzle` | `wp_safe_remote_get()` |
| `intervention/image` | `wp_get_image_editor()` |
| `ezyang/htmlpurifier` | `wp_kses()` with an explicit allowlist |
| a state-machine library | `TRANSITIONS`, ~120 greppable lines ([ADR-0008](0008-explicit-transition-table.md)) |

If a runtime dependency ever becomes genuinely unavoidable, it ships **php-scoper-prefixed into `LAAO_Advertiser_Portal\Vendor\`**, and that decision gets its own ADR superseding this one.

## Consequences

- This plugin can never be the one that broke the site through a class collision. That is the entire benefit, and it is worth more than any package on the list.
- The package is small, which is a pleasant side effect and not the argument.
- Production autoloading is the plugin's own ([ADR-0012](0012-own-autoloader-in-production.md)), because there is no `vendor/autoload.php` in a shipped build.
- Some things get written by hand that a package would have provided. Each is small, and each is exactly as complex as this product's actual need rather than as complex as a general-purpose library.
- Anything on the substitution list appearing in `composer.json require` is a review failure, not a judgement call.

## Alternatives rejected

**Ship `vendor/` unprefixed.** The collision above, on someone else's site, at a time we do not control.

**Ship `vendor/` prefixed with php-scoper as standard practice.** Solves collisions and adds a build step, a source of confusing stack traces, and a new way for the packaged plugin to differ from the source tree. Reserved for a dependency that has earned it; kept available so the answer is "not yet" rather than "never".

**Require Composer install at the deploy step.** Works for a controlled deploy and fails for the ZIP-upload path, which is how this plugin will actually be installed. A plugin that fatals on activation because someone did not run a command is not installable.
