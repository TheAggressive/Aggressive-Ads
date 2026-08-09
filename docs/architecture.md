# Architecture

## The shape

```
laao-advertiser-portal.php        constants, guards, autoloader require
        ↓
inc/class-autoloader.php          LAAO_Advertiser_Portal\X\Y_Z → inc/X/class-y-z.php
        ↓
inc/class-plugin.php              composition root (singleton)
        ↓
inc/class-service-container.php   lazy singletons, no reflection
        ↓
register_services()               builds factories — instantiates nothing
init_services()                   explicit, ordered ->init() calls
```

The root plugin file does four things: declare the header, define constants, guard the PHP/WP floor, and hand off. If it ever grows a fifth responsibility, that responsibility belongs in a service.

`register_services()` and `init_services()` are deliberately separate. Registering a service must never cause application behaviour — a factory closure is stored, nothing runs. Behaviour begins only when `init_services()` calls `->init()`, in an order the file makes visible. Adding a service costs two edits in one file, and that is the point: the wiring stays greppable, and there is no autowiring magic to reverse-engineer at 2am.

## Layers

| Layer | Directory | May call |
|---|---|---|
| Domain | `inc/Domain/` | Nothing. Pure PHP value objects and rules. **No WordPress functions at all.** |
| Repository | `inc/Repository/` | `WP_Query`, `get_post_meta`, `$wpdb`, and the rest of the WP data API |
| Workflow | `inc/Workflow/` | Domain + Repository |
| Security | `inc/Security/` | Repository, plus the capability API |
| Integration | `inc/Integration/Adsanity/` | Repository, plus AdSanity's data |
| Delivery | `inc/REST/`, `inc/Portal/`, `inc/Admin/` | Workflow + Security. **Never a repository directly, never AdSanity.** |
| Core / Install / Support | `inc/Core/`, `inc/Install/`, `inc/Support/` | Infrastructure; wired at bootstrap |

Two rules carry most of the weight:

**`inc/Repository/` is the only place data access appears.** `WP_Query`, `get_posts()`, `get_post_meta()`, `update_post_meta()`, and `$wpdb` do not appear anywhere else in `inc/`. Enforced by `bin/ci/check-repository-boundary.sh`, which fails the build on a violation.

**`inc/Integration/Adsanity/` is the only place AdSanity exists.** The strings `'ads'`, `'ad-group'`, `ADSANITY_EOL`, and every AdSanity meta key (`_url`, `_size`, `_start_date`, …) appear nowhere else. Enforced by the same script.

Both boundaries exist for the same reason. AdSanity is a third-party plugin whose internals we read but do not control, and whose meta keys are undocumented implementation detail. When it changes — and a licensed plugin on a weekly update cadence will change — the blast radius must be one directory. Scatter `get_post_meta( $id, '_start_date' )` through the codebase and every future AdSanity release is a full-repository audit.

**`inc/Domain/` calls no WordPress function.** Enforced by the same script.

The domain layer's "no WordPress at all" rule buys something narrower but real: the campaign rules — is this transition legal, is this creative valid for this placement, is this date range sane — are testable in milliseconds with no database and no bootstrap. That is what makes it affordable to test them exhaustively.

That is not a figure of speech. `TransitionTableTest` checks **all 121 status pairs**, not the handful anyone remembers, and the whole unit suite runs in under ten milliseconds. One `get_option()` in `inc/Domain/` and that property is gone: the exhaustive test becomes an integration test, and an integration test is not something anyone runs on every save.

## Dependency direction

```
WordPress
    ↓
LAAO Advertiser Portal
    ↓
AdSanity  (optional — the portal degrades, it does not break)
```

Separately, and only in this direction:

```
LAAO theme  →  may restyle the portal via CSS custom properties
```

The portal never calls a theme class, never requires a theme file, and never assumes a theme's markup or CSS variables exist. It must run under Twenty Twenty-Five with no visual or functional degradation beyond the absence of site chrome.

This is not an aspiration maintained by discipline. `tests/e2e/portal-smoke.spec.ts` switches the active theme to Twenty Twenty-Five, logs in, loads the portal, and runs axe. A theme dependency introduced by accident fails that test. See [ADR-0001](adr/0001-standalone-plugin-zero-theme-dependency.md).

## What AdSanity's absence looks like

AdSanity being inactive is a supported state, not an error state:

- Placements still exist and are still visible to staff — they seed with `_laao_ads_adgroup_term_id = 0`.
- Advertisers can still create campaigns, upload creative, and submit.
- Staff can still review, request changes, and reject.
- **Approval fails**, cleanly, with an error naming the unmapped placement. No status change, no partial publish.

That last line is the whole design. See [ADR-0007](adr/0007-placement-mapping-is-explicit-data.md).

## File size

`bin/check-file-length.sh` warns above 800 lines and fails above 1000, with no allowlist. The remedy is always to split by responsibility. Raising the threshold is not an option, because the threshold is not the point — a 1200-line class is telling you it has more than one job, and the number is just how you found out.
