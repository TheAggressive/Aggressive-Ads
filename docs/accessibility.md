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

One implementation, in `src/interactivity/dialog.ts` plus `src/styles/components/_overlay.css`. Every dialog in the portal uses it — creative preview, replace, delete confirmation, cancellation, rejection detail, approval confirmation, retry. Building a second one is how half of them end up without a focus trap.

The contract, ported from the Aggressive Apparel implementation:

| Behaviour | Implementation |
|---|---|
| Semantics | `div[role="dialog"][aria-modal="true"]` with `aria-labelledby`. **Not native `<dialog>`** |
| Focus trap | Applied to the **shell**, not the panel, so close controls positioned outside the panel stay in the Tab cycle. Focusable list excludes anything under `[hidden]` or `[inert]` |
| Focus restoration | The element focused at open time is captured and restored, guarded against restoring to `document.body` or a detached node |
| Background | `inert` on the page root, feature-detected, re-exempting any other open overlay |
| Scroll lock | **Reference-counted**, so a stacked dialog closing does not unlock the page underneath. Scrollbar width compensated to avoid layout shift |
| Escape | One document-level capturing `keydown` listener, closing **only the top of the stack** |
| Reduced motion | Exit duration collapses to zero |
| Announcement | An `aria-live="polite"` region updates on open and close, for readers that do not react to programmatic focus alone |
| Close control | Icon-only gets `aria-label`; a visible text label supplies the accessible name and **no `aria-label` is added** — a redundant one overrides the visible text and breaks voice control |

Native `<dialog>` was not used because its top-layer and backdrop semantics conflict with the drawer positioning and animation variety the portal needs, and because the reference implementation being ported is already custom and already correct.

## Upload accessibility

Drag and drop is an enhancement, never the only path. Every upload zone has a real `<input type="file">` reachable by keyboard, with a visible label.

Progress is announced through a live region, not only shown as a bar. Completion and failure are both announced.

**Validation errors state the actual problem and the actual fix:**

```
Uploaded: 1200 × 400
Required: 1200 × 300
```

Not "invalid image". The advertiser has to fix this themselves without calling anyone, and "invalid" tells them nothing about which of six possible things went wrong.

## The wizard

Progress is conveyed by text and structure, not only by a coloured bar. Each step is a landmark with a heading. Moving between steps moves focus to the new step's heading, so a screen-reader user knows the context changed. Validation errors summarize at the top with in-page links to the offending fields — the standard pattern, because it works.

## Creative alt text

Every creative carries `_laao_ads_alt_text`, collected from the advertiser and written to `_wp_attachment_image_alt` when the creative is promoted at approval.

This closes a real gap. The LAAO theme currently patches missing ad alt text at render time in `inc/Accessibility/class-ad-link-labels.php`, injecting `alt="Advertisement: {title}"` — written because three ads on the front page had no alt text and were failing an axe link-name check. That shim is a workaround for ads created by hand in AdSanity's admin, where alt text is not a field.

Ads this portal publishes will not need it: the alt text is required at upload and travels with the file. The theme's shim can stay as a safety net for legacy ads, but it will have nothing to do for ours.

## Verification

- `pnpm test:e2e` runs axe on every major screen under **Twenty Twenty-Five**, failing on any serious or critical violation.
- Keyboard-only traversal of campaign creation and upload is asserted explicitly, not assumed from the axe pass.
- Focus order, trap, and restoration are asserted per dialog.
- Manual screen-reader passes supplement all of the above before any release that changes a flow.
