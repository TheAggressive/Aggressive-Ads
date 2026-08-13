# Interactivity API conventions

The portal is server-rendered PHP. JavaScript enhancements use WordPress
Interactivity API stores: no SPA, no router, and no client-side state tree.
See [ADR-0004](adr/0004-server-rendered-plus-interactivity-api.md).

## Stores

| Namespace | Status | Owns |
|---|---|---|
| `aggr/dialog` | **Shipped** | Shared dialog primitive — open/close, stack, focus, scroll lock. Consumers: creative replace, live-ad preview, draft preview, remove confirmation |
| `aggr/wizard` | **Shipped** | Step announcement, submit-step gating on the existing no-JS forms |
| `aggr/upload` | **Shipped** | Drag/drop onto the native file input, client size/type/dimension checks |
| `aggr/autosave` | **Shipped** | Debounced PATCH of the public allowlist, dirty/save status |

Four namespaces, deliberately. A single `portal` store would become the client-side god object this architecture exists to avoid.

## Source layout

Author under `src/`; ship compiled `dist/` (see [build-and-release.md](build-and-release.md)):

| Import map id | Source | Compiled |
|---|---|---|
| `@aggr/scroll-lock` | `src/interactivity/scroll-lock.ts` | `dist/interactivity/scroll-lock.js` |
| `@aggr/helpers` | `src/interactivity/helpers.ts` | `dist/interactivity/helpers.js` |
| `@aggr/logic` | `src/interactivity/logic.ts` | `dist/interactivity/logic.js` |
| `@aggr/dialog` | `src/interactivity/dialog.ts` | `dist/interactivity/dialog.js` |
| `@aggr/wizard` | `src/interactivity/wizard.ts` | `dist/interactivity/wizard.js` |
| `@aggr/autosave` | `src/interactivity/autosave.ts` | `dist/interactivity/autosave.js` |
| `@aggr/upload` | `src/interactivity/upload.ts` | `dist/interactivity/upload.js` |

`inc/Assets/class-assets.php` registers modules from `dist/`, reads `.asset.php`
manifests, and early-enqueues the dialog store (plus `@wordpress/interactivity`)
on screens that need it so block themes print the import map in `wp_head`.

## Per-instance keyed state

A page can hold several dialogs and several upload zones. All instances of a component share one store namespace, so **state is keyed by a unique instance ID**, never held as a namespace-level scalar:

```php
wp_interactivity_state( 'aggr/dialog', array(
    'dialogs' => array(
        $unique_id => array( 'isOpen' => false, 'animationDuration' => 200 ),
    ),
) );
```

The store reads `state.dialogs[ context.dialogId ]`. A namespace-level `state.isOpen` works perfectly until the second dialog appears, at which point opening one opens both.

## Dialog open/close is imperative

Visibility is driven by `classList` on `.aggr-overlay` (`is-open`), not by
a nested `data-wp-class` binding on `state.dialogs[context.dialogId].isOpen`.
Triggers outside the overlay are bound in `init` / `bootAllDialogs` via
`aria-controls` (click / Enter / Space); close controls use
`data-aggr-dialog-close`. Store `isOpen` still updates for stack and
hydration bookkeeping.

That matches Aggressive Apparel's modal and avoids a hard failure mode:
`preventDefault` on the trigger without a reliable open leaves the page looking
dead (hash navigation cancelled, overlay still closed). Do not reintroduce
declarative nested path class bindings for open state without verifying them
against a real block-theme portal page.

No-JS fallback remains `:target` on the overlay `id`. Accessibility contract:
[accessibility.md](accessibility.md).

## Hydration is the i18n boundary

**`wp_set_script_translations()` does not work for script modules.** WordPress has no translation mechanism for the Script Modules API the Interactivity API is built on.

Every user-facing string is therefore translated in PHP and hydrated into state:

```php
wp_interactivity_state( 'aggr/upload', array(
    'i18n' => array(
        'uploading' => __( 'Uploading…', 'aggressive-ads' ),
    ),
) );
```

**No string literal that a user will read appears in store modules.** See [i18n.md](i18n.md).

Hydration also carries server-derived configuration — REST route URLs, the nonce, size limits, allowed MIME types — so nothing is hard-coded client-side and nothing needs a bootstrap request.

## Pure logic lives outside the store

`src/interactivity/logic.ts` imports nothing from `@wordpress/interactivity`.
It holds the decidable parts: which wizard step comes next, whether a file's
dimensions match a placement, whether a visit to Submit is allowed. That
import boundary is the whole point — Jest tests it directly with no runtime
mocking. Anything requiring a mocked `@wordpress/interactivity` to test is a
sign the logic belongs in `logic.ts`. Keep decidable helpers free of
Interactivity imports (as `helpers.ts` / `scroll-lock.ts` already are).

## Module registration

Through `inc/Assets/class-assets.php`, which:

- no-ops gracefully when `wp_register_script_module` does not exist
- no-ops when the compiled file is missing, rather than emitting a 404 for every visitor
- declares `@wordpress/interactivity` where the store needs it
- reads version and dependencies from `.asset.php` manifests

Shared modules (`dialog`, `logic`, `scroll-lock`, `helpers`) are **registered but not enqueued** until a feature calls `enqueue_dialog()` or the campaign editor hydrates. A screen with no dialog ships no dialog code. Wizard, autosave and upload enqueue only on campaign detail.

Modules are enqueued only on the portal route. The plugin adds nothing to any other page on the site.

## Known hazards

**`data-wp-init` can fire twice.** An Interactivity root initializing twice is a real condition, observed in the reference implementation. Any `init` action that binds listeners or mutates external state must be idempotent — guard with a `Set` of initialized IDs.

**Exit animations need imperative timing.** The "wait for the transition, then finish close" sequence is hand-rolled: listen for `transitionend` filtered to `propertyName === 'opacity'`, with a `setTimeout` fallback of duration + 50ms because `transitionend` does not fire if the element is hidden or the transition is interrupted. Without the fallback a dialog occasionally never closes.

**Elements outside the interactive region need imperative binding.** A trigger rendered elsewhere on the page is bound in `init` / boot rather than through declarative open attributes, keeping `aria-expanded`, `aria-controls`, and `aria-haspopup` in sync manually.

**Import maps must print early.** On block themes, enqueuing the dialog module only from `wp_footer` can leave `@wordpress/interactivity` unresolved. Campaign detail early-enqueues the store so the import map appears in `wp_head`.

## Accessibility

Every store touching focus or the page's interactive surface honours the dialog contract in [accessibility.md](accessibility.md): focus trap on the shell, guarded focus restoration, reference-counted scroll lock, `inert` on the background, a single document-level capturing Escape handler closing only the top of the stack, and `prefers-reduced-motion` zeroing durations.

That behaviour lives in one place. A second implementation is how half the dialogs end up without a focus trap.
