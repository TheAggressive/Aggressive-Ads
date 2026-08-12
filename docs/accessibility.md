# Accessibility

Target: **WCAG 2.2 AA**. Accessibility is a release blocker, not a polish pass.

Automated scanning runs against the `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` axe tags. Best-practice rules are excluded deliberately — they are advice rather than conformance, and mixing the two means the conformance signal gets muted the first time someone needs to ship.

Axe catches roughly a third of real problems. The rest is keyboard traversal, focus order, announcement quality, and whether the error message actually tells someone what to do. Those are asserted per-flow and checked manually.

## Requirements

- Complete keyboard operation. Every action reachable and performable without a mouse.
- Visible focus everywhere, using the focus-ring tokens. Never `outline: none` without a replacement that is at least as visible.
- Logical heading order, no skipped levels, one `<h1>` per screen.
- Semantic HTML first. **Prefer native elements over ARIA** — a `<button>` is better than a `div` with `role="button"` and three event handlers, every time.
- Labels programmatically associated, not merely adjacent.
- Errors associated with their field via `aria-describedby`, announced, and never communicated by colour alone.
- Instructions and constraints available *before* the field, not only after a failed submit.
- Live regions for asynchronous status: upload progress, autosave confirmation, validation results.
- Reduced-motion support — `prefers-reduced-motion: reduce` zeroes both duration tokens.
- Touch targets at least 44×44px, via `--laao-ads-control-min`.
- Never colour alone. Status chips carry text; validation carries an icon and a message.

## The dialog primitive

One implementation: `src/interactivity/dialog.ts` → `dist/interactivity/dialog.js`
(store namespace `laao-advertiser-portal/dialog`), with overlay styles in
`src/styles/components/_overlay.css` (bundled into `dist/styles/portal.css`).
Shared helpers live in `scroll-lock.ts` and `helpers.ts`. Every portal dialog
must use this stack — building a second one is how half of them end up without
a focus trap.

**Shipped today:** creative replace on campaign detail (overlays in
`templates/portal/partials/creative-replace-dialogs.php`, triggers in
`campaign-ad-updates.php`). Preview, delete confirmation, and the other
named dialogs adopt the same primitive when those screens need them.

**Open/close is imperative**, same pattern as Aggressive Apparel's modal:
`classList` on the overlay shell, trigger listeners bound by `aria-controls`,
close via `data-laao-ads-dialog-close`. Nested Interactivity path bindings such
as `data-wp-class--is-open="state.dialogs[context.dialogId].isOpen"` are not
reliable enough here; a `preventDefault` without a visible open leaves the
page looking dead. Store state still tracks `isOpen` per instance for stacking
and hydration — the visible class is not driven by a declarative class binding.
See [interactivity-stores.md](interactivity-stores.md).

No-JS keeps working through `:target` on the overlay `id` (Update links are
`href="#laao-ads-replace-{id}"`). Do not remove that path when enhancing.

The contract, ported from the Aggressive Apparel implementation:

| Behaviour | Implementation |
|---|---|
| Semantics | `div[role="dialog"][aria-modal="true"]` with `aria-labelledby`. **Not native `<dialog>`** |
| Focus trap | Applied to the **shell**, not the panel, so close controls positioned outside the panel stay in the Tab cycle. Focusable list excludes anything under `[hidden]` or `[inert]` |
| Focus restoration | The element focused at open time is captured and restored, guarded against restoring to `document.body` or a detached node |
| Background | `inert` on `.laao-ads-shell`, feature-detected; overlays render in `wp_footer` outside the shell so they are not inerted with the page |
| Scroll lock | **Reference-counted**, so a stacked dialog closing does not unlock the page underneath. Scrollbar width compensated to avoid layout shift |
| Escape | One document-level capturing `keydown` listener, closing **only the top of the stack** |
| Reduced motion | Exit duration collapses to zero |
| Announcement | An `aria-live="polite"` region updates on open and close, for readers that do not react to programmatic focus alone |
| Close control | Icon-only gets `aria-label`; a visible text label supplies the accessible name and **no `aria-label` is added** — a redundant one overrides the visible text and breaks voice control |

Native `<dialog>` was not used because its top-layer and backdrop semantics conflict with the drawer positioning and animation variety the portal needs, and because the reference implementation being ported is already custom and already correct.

## Upload accessibility

Drag and drop is an enhancement, never the only path. Every upload zone has a real `<input type="file">` reachable by keyboard, with a visible label.

Progress is announced through a live region, not only shown as a bar. Completion and failure are both announced.

The progressively enhanced baseline ships native file inputs, visible labels,
pre-field type/size/dimension instructions, required destination and
alternative-text fields, linked error summaries, and server-rendered completion
notices. Drag/drop and live progress remain enhancements; upload, review, and
removal do not depend on them.

**Validation errors state the actual problem and the actual fix:**

```
Uploaded: 1200 × 400
Required: 1200 × 300
```

Not "invalid image". The advertiser has to fix this themselves without calling anyone, and "invalid" tells them nothing about which of six possible things went wrong.

## The wizard

Progress is conveyed by text and structure, not only by a coloured bar. Each step is a landmark with a heading. Moving between steps moves focus to the new step's heading, so a screen-reader user knows the context changed. Validation errors summarize at the top with in-page links to the offending fields — the standard pattern, because it works.

Destination-and-schedule Step 4 renders creative destinations as text rather
than activating external links during campaign creation. Its native date inputs
have visible labels and pre-field instructions; start is programmatically
required and carries the earliest allowed local date. Server errors link back
to the affected date or destination summary and are included in that field's
`aria-describedby` only while the error is present.

Review Step 5 exposes all current submission problems in one ordered summary,
not only the first failure. The problem state is an alert, the ready state is a
status, and every issue has a keyboard-operable 44px edit target that returns
to the exact step and field. Review sections use labelled landmarks and native
description lists. Creative previews use their generated alternative text;
destination URLs render as text so keyboard users are not sent away from an
unfinished campaign accidentally.

Submit Step 6 has a visible heading, plain-language consequences before the
action, a native POST form, and separate Back and Submit controls with shared
touch-target and focus tokens. It does not use a surprise dialog or depend on
JavaScript. If readiness changes, the submit button disappears and the full
ordered, linked problem summary replaces it. Successful submission is
announced with `role="status"`; server refusal returns an alert without placing
the campaign into a false submitted state.

## Creative alt text

Every creative carries `_laao_ads_alt_text` and writes it to `_wp_attachment_image_alt` when promoted. The portal generates concise text from the validated destination host, so advertisers are not asked for a separate description; API clients may still supply more specific text.

This closes a real gap. The LAAO theme currently patches missing ad alt text at render time in `inc/Accessibility/class-ad-link-labels.php`, injecting `alt="Advertisement: {title}"` — written because three ads on the front page had no alt text and were failing an axe link-name check. That shim is a workaround for ads created by hand in AdSanity's admin, where alt text is not a field.

Ads this portal publishes will not need it: accessible text is generated during upload and travels with the file. The theme's shim can stay as a safety net for legacy ads, but it will have nothing to do for ours.

## Verification

- `pnpm test:e2e` runs axe on the dashboard, review, submit, and both pre/post-save Ad delivery mapping surfaces, failing on every violation carrying one of the configured WCAG conformance tags.
- The browser suite asserts the keyboard skip path, complete native-form campaign flow, private preview, review, final submission, labeled mapping controls, and the real mapping write; these are not inferred from axe.
- Focus order, trap, and restoration are asserted per dialog.
- Manual screen-reader passes supplement all of the above before any release that changes a flow.
