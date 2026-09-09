/**
 * The one measure of how wide the page is.
 *
 * Both request paths send it — the per-slot fill and the page batch — and they
 * must send the same number. A responsive placement resolves to a size by
 * comparing this against its breakpoints, so two definitions that disagree
 * anywhere put the two paths on opposite sides of a breakpoint and serve the
 * same slot different artwork depending on which one filled it.
 *
 * That is not hypothetical. This started as one function in `fill.js` and a
 * second copy in `batch.js` that added a `window.innerWidth` fallback. The
 * fallback is the bug: `innerWidth` includes the scrollbar and `clientWidth`
 * does not, so on any page where the two differ the batch path reported a wider
 * viewport than the per-slot path for the same page.
 */

/**
 * The viewport width a slot is being rendered into, in CSS pixels.
 *
 * `documentElement.clientWidth` rather than `innerWidth`, because the former
 * excludes the scrollbar and so matches what CSS media queries and the slot's
 * own layout actually see. A pixel of disagreement here puts the decision on
 * the other side of a breakpoint from the box it fills.
 *
 * Zero when the document is not measurable, which the server reads as "no
 * viewport reported" and answers with the placement's base size — the same
 * answer every non-responsive placement has always had.
 *
 * @return {number} Viewport width, or 0.
 */
export const viewportWidth = () => {
	const width = document.documentElement?.clientWidth;

	return Number.isFinite( width ) && width > 0 ? Math.floor( width ) : 0;
};
