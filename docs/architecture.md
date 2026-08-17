# Architecture

## The shape

```
aggressive-ads.php        constants, guards, autoloader require
        ↓
inc/class-autoloader.php          Aggressive\Ads\X\Y_Z → inc/X/class-y-z.php
        ↓
inc/class-plugin.php              composition root (boot + init order)
        ↓
inc/class-service-registrar.php   factory closures — instantiates nothing
        ↓
inc/class-service-container.php   lazy singletons, no reflection
        ↓
init_services()                   explicit, ordered ->init() calls
```

The root plugin file does four things: declare the header, define constants, guard the PHP/WP floor, and hand off. If it ever grows a fifth responsibility, that responsibility belongs in a service.

`register_services()` and `init_services()` are deliberately separate. Registering a service must never cause application behaviour — a factory closure is stored, nothing runs. Behaviour begins only when `init_services()` calls `->init()`, in an order `Plugin` makes visible. Adding a service costs two greppable edits: one `register()` in `Service_Registrar` and, when the service needs hooks, one entry in `Plugin::service_init_order()`. There is no autowiring magic to reverse-engineer at 2am.

## Layers

| Layer | Directory | May call |
|---|---|---|
| Domain | `inc/Domain/` | Nothing. Pure PHP value objects and rules. **No WordPress functions at all.** |
| Repository | `inc/Repository/` | `WP_Query`, `get_post_meta`, `$wpdb`, and the rest of the WP data API |
| Workflow | `inc/Workflow/` | Domain + Repository |
| Security | `inc/Security/` | Repository, plus the capability API |
| Integration | `inc/Integration/Native/` | Domain + Workflow (fill cache). No third-party plugin. |
| Delivery | `inc/REST/`, `inc/Portal/`, `inc/Admin/` | Workflow + Security + Repository query interfaces. |
| Core / Install / Support | `inc/Core/`, `inc/Install/`, `inc/Support/` | Infrastructure; wired at bootstrap |

Two rules carry most of the weight:

**`inc/Repository/` is the only place data access appears.** `WP_Query`, `get_posts()`, `get_post_meta()`, `update_post_meta()`, and `$wpdb` do not appear anywhere else in `inc/`. Enforced by `bin/ci/check-repository-boundary.sh`, which fails the build on a violation.

Delivery presenters and controllers may depend on repositories to assemble
authorized read models; they still never call WordPress persistence functions
directly. Business operations with lifecycle effects go through Workflow, so a
screen cannot replace the campaign state machine with a convenient repository
status write.

**AdSanity identifiers appear nowhere in `inc/` or `templates/`.** The strings `'ad-group'`, `ADSANITY_EOL`, and every former AdSanity meta key (`_url`, `_size`, `_start_date`, …) fail the build. Enforced by the same script.

Campaign composition resolves `Ad_Provider_Interface`, never a concrete class. The interface exposes publication/reconciliation and the delivery lifecycle effects the transition table needs. The native publisher busts fill cache and returns success; there is no downstream ad CPT.

**There is no AdSanity adapter, so there is no repository-rule exemption for one.** Data access stays in `inc/Repository/`.

**`inc/Domain/` calls no WordPress function.** Enforced by the same script.

The domain layer's "no WordPress at all" rule buys something narrower but real: the campaign rules — is this transition legal, is this creative valid for this placement, is this date range sane — are testable in milliseconds with no database and no bootstrap. That is what makes it affordable to test them exhaustively.

That is not a figure of speech. `TransitionTableTest` checks **all 121 status pairs**, not the handful anyone remembers, and the whole unit suite runs in under ten milliseconds. One `get_option()` in `inc/Domain/` and that property is gone: the exhaustive test becomes an integration test, and an integration test is not something anyone runs on every save.

## Dependency direction

```
WordPress
    ↓
Aggressive Ads
```

Separately, and only in this direction:

```
LAAO theme  →  may restyle the portal via CSS custom properties
```

The portal never calls a theme class, never requires a theme file, and never assumes a theme's markup or CSS variables exist. It must run under Twenty Twenty-Five with no visual or functional degradation beyond the absence of site chrome.

This is not an aspiration maintained by discipline. `tests/e2e/campaign-wizard.spec.ts` switches the active theme to Twenty Twenty-Five, logs in, loads the portal, and runs axe. A theme dependency introduced by accident fails that test.

## Public pages without a theme embed

Native fill only runs where the theme (or an editor) places `aggr/placement`,
the PHP helper, or the shortcode. Until the LAAO theme swaps AdSanity group
blocks for those embeds, public pages show empty reserved slots. That theme
change is outside this plugin. Approval, Inventory, and the clock do not
depend on it.

Staff create placements in Advertising → Inventory: a common IAB size or
custom width × height, stored as `{width}x{height}` with ASCII `x`. Size is
not identity — two slots may share 728×90. There is no delete: deactivate,
same as packages. The Block Editor block is authored under `src/blocks/`
(same layout as the LAAO theme) and styled with core block `supports`.

The hot read model remains authoritative posts/meta rather than a second
eventually-consistent delivery table. `Delivery_Repository` resolves a
placement with two bounded, index-led reads and validates a consumed token by
its exact creative/campaign/placement tuple. `Fill_Cache` stores a compact id
vector plus individual creative payloads. Capacity measurements and production
requirements live in [delivery-performance.md](delivery-performance.md).

## Stylesheets

`bin/ci/check-styles.mjs` compares the two sides that a CSS linter cannot see at
once: every `aggr-*` class named in a `class=` or `className=` attribute must
resolve to a selector in `src/styles/`, and every `var(--aggr-*)` read without a
fallback must resolve to a declaration.

It exists because the stylesheets had no equivalent of the boundary guards that
protect `inc/`, and it showed. `aggr-linkbutton` was written into two components
and defined nowhere, so the browser drew its default button — a grey box around
a campaign name — while PHPCS, Stylelint, axe and the whole test suite stayed
green. Stylelint reads the stylesheet in isolation and cannot know what the
markup asks for; nothing else was looking at all.

Names built at runtime are matched as prefixes, so `aggr-pill--${status}` needs
only that something starting `aggr-pill--` exists — which status maps to which
modifier is the server's business. **Behaviour hooks are data attributes, not
classes**: `data-aggr-autosave`, `data-aggr-review-content`. A class with no
rule behind it is indistinguishable from a class whose rule was forgotten, which
is the whole thing this guard is looking for.

What it does not check is whether a token *resolves where it is used*. Every
`--aggr-*` is declared on `.aggr-portal`, the front end puts that class on
`<body>` and wp-admin does not, so a dialog rendered outside that scope resolves
every token to nothing — a transparent panel rather than a degraded one. That
failure is a scope question rather than an existence one, and it is caught by
looking at the screen.

## File size

`bin/check-file-length.sh` warns above 800 lines and fails above 1000, with no allowlist. The remedy is always to split by responsibility. Raising the threshold is not an option, because the threshold is not the point — a 1200-line class is telling you it has more than one job, and the number is just how you found out.
