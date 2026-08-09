# ADR-0004 — Server-rendered templates plus the Interactivity API, no SPA

**Status:** Accepted — 2026-08-08

## Context

The portal is a multi-screen application: dashboard, campaign list and detail, organization, account, and a six-step wizard with autosave, drag-and-drop upload, live validation, and several dialogs. That description reads like a React SPA, and the plan explicitly warns against building one for its own sake.

The countervailing facts: every screen's data is already on the server, authorization must be enforced server-side regardless of what the client does, and an SPA means a second router, a second authorization surface to keep honest, and a client bundle that must be downloaded before anything renders.

## Decision

Screens are **server-rendered PHP templates**. Interaction is added with the **WordPress Interactivity API**, in four stores with distinct namespaces:

| Namespace | Owns |
|---|---|
| `laao-advertiser-portal/dialog` | The shared dialog primitive |
| `laao-advertiser-portal/wizard` | Step navigation, per-step validity, resume point |
| `laao-advertiser-portal/upload` | Selection, progress, validation results, replace and remove |
| `laao-advertiser-portal/autosave` | Debounced PATCH, dirty tracking, save status |

Four namespaces rather than one, because a single `portal` store becomes the client-side god object this architecture exists to avoid.

Two conventions carry most of the value:

**State is keyed per instance.** A page holds several dialogs and several upload zones, all sharing one namespace, so state lives at `state.dialogs[ context.dialogId ]` and never as a namespace-level scalar. A namespace-level `isOpen` works perfectly until the second dialog appears, at which point opening one opens both.

**Pure logic lives in `src/interactivity/logic.ts`, which imports nothing from `@wordpress/interactivity`.** That import boundary is what lets Jest test the decidable parts — next wizard step given a validity map, whether dimensions match a placement — with no runtime mocking. Anything needing a mocked Interactivity runtime to test belongs in `logic.ts`.

## Consequences

- Screens render without JavaScript and remain usable; interaction is progressive enhancement.
- No client-side routing, so no second place where "may this user see this?" is decided.
- Modules are enqueued only on the portal route. The plugin adds nothing to any other page on the site. Shared modules are registered but not enqueued, loading on demand as declared dependencies, so a screen with no dialog ships no dialog code.
- **Script modules have no translation mechanism.** `wp_set_script_translations()` does not work for the Script Modules API. Every user-facing string is translated in PHP and hydrated through `wp_interactivity_state()`, and no string literal a user will read may appear in TypeScript. This is a core gap, tracked in [known-issues.md](../known-issues.md).
- Two known runtime hazards, both from the reference implementation: `data-wp-init` can fire twice, so any `init` binding listeners must be idempotent; and exit animations need an imperative `transitionend` listener with a `setTimeout` fallback, because `transitionend` does not fire for a hidden or interrupted transition and the dialog then never closes.

## Alternatives rejected

**A React SPA** (`@wordpress/element` or otherwise). Duplicates routing and authorization, delays first paint behind a bundle, and makes every screen a client fetch. The wizard is the only screen with SPA-shaped state, and it is one screen.

**jQuery and hand-rolled DOM code.** No state model, so the wizard's step validity becomes ad-hoc flags on elements — which is how upload zones end up each with their own subtly different focus handling.

**Blocks for the application screens.** Editor-facing composition is a real use for blocks; a campaign wizard is not page composition. Blocks are limited to the three entry points in the plan.
