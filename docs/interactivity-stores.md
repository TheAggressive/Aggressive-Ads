# Interactivity API conventions

The portal is currently server-rendered PHP. Its planned JavaScript enhancements
use WordPress Interactivity API stores: no SPA, no router, and no client-side
state tree. See [ADR-0004](adr/0004-server-rendered-plus-interactivity-api.md).

## Planned stores

| Namespace | Owns |
|---|---|
| `laao-advertiser-portal/dialog` | The shared dialog primitive — open/close, stack, focus, scroll lock |
| `laao-advertiser-portal/wizard` | Step navigation, per-step validity, resume point |
| `laao-advertiser-portal/upload` | File selection, progress, validation results, replace and remove |
| `laao-advertiser-portal/autosave` | Debounced PATCH, dirty tracking, save status |

Four namespaces, deliberately. A single `portal` store would become the client-side god object this architecture exists to avoid.

## Per-instance keyed state

A page can hold several dialogs and several upload zones. All instances of a component share one store namespace, so **state is keyed by a unique instance ID**, never held as a namespace-level scalar:

```php
wp_interactivity_state( 'laao-advertiser-portal/dialog', array(
    'dialogs' => array(
        $unique_id => array( 'isOpen' => false, 'returnFocusTo' => '' ),
    ),
) );
```

The store reads `state.dialogs[ context.dialogId ]`. A namespace-level `state.isOpen` works perfectly until the second dialog appears, at which point opening one opens both.

## Hydration is the i18n boundary

**`wp_set_script_translations()` does not work for script modules.** WordPress has no translation mechanism for the Script Modules API the Interactivity API is built on.

Every user-facing string is therefore translated in PHP and hydrated into state:

```php
wp_interactivity_state( 'laao-advertiser-portal/upload', array(
    'i18n' => array(
        'uploading' => __( 'Uploading…', 'laao-advertiser-portal' ),
    ),
) );
```

**No string literal that a user will read appears in TypeScript.** See [i18n.md](i18n.md).

Hydration also carries server-derived configuration — REST route URLs, the nonce, size limits, allowed MIME types — so nothing is hard-coded client-side and nothing needs a bootstrap request.

## Pure logic lives outside the store

`src/interactivity/logic.ts` imports nothing from `@wordpress/interactivity`. It holds the decidable parts: which wizard step comes next given a validity map, whether a file's dimensions match a placement, how to format a size for display, what the exit animation should be.

That import boundary is the whole point — Jest tests it directly with no runtime mocking. Anything requiring a mocked `@wordpress/interactivity` to test is a sign the logic belongs in `logic.ts`.

## Module registration

Through a wrapper on `wp_register_script_module()` / `wp_enqueue_script_module()` in `inc/Assets/class-assets.php`, which:

- no-ops gracefully when `wp_register_script_module` does not exist
- no-ops when the built file is missing, rather than emitting a 404 for every visitor
- auto-prepends `@wordpress/interactivity` as a dependency
- reads version and dependencies from the `.asset.php` manifest

Shared modules (`dialog`, `scroll-lock`, `helpers`, `logic`) are **registered but not enqueued**. They load on demand as declared dependencies of the feature modules that need them, so a screen with no dialog ships no dialog code.

Modules are enqueued only on the portal route. The plugin adds nothing to any other page on the site.

## Known hazards

**`data-wp-init` can fire twice.** An Interactivity root initializing twice is a real condition, observed in the reference implementation. Any `init` action that binds listeners or mutates external state must be idempotent — guard with a `Set` of initialized IDs.

**Exit animations need imperative timing.** Declarative bindings handle state fine, but the "wait for the transition, then unmount" sequence is hand-rolled: listen for `transitionend` filtered to `propertyName === 'opacity'`, with a `setTimeout` fallback of duration + 50ms because `transitionend` does not fire if the element is hidden or the transition is interrupted. Without the fallback a dialog occasionally never closes.

**Elements outside the interactive region need imperative binding.** A trigger button rendered elsewhere on the page is bound in the `init` action rather than through declarative attributes, keeping `aria-expanded`, `aria-controls`, and `aria-haspopup` in sync manually.

## Accessibility

Every store touching focus or the page's interactive surface honours the dialog contract in [accessibility.md](accessibility.md): focus trap on the shell, guarded focus restoration, reference-counted scroll lock, `inert` on the background, a single document-level capturing Escape handler closing only the top of the stack, and `prefers-reduced-motion` zeroing durations.

That behaviour lives in one place. A second implementation is how half the dialogs end up without a focus trap.
