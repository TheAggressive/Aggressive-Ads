/**
 * What a slot does when there is no ad to show.
 *
 * Separated from the store for the same reason `fill.js` is: this is the part
 * with a decision in it, and a decision reachable only through a directive, a
 * hydration lifecycle and the Interactivity runtime is a decision nothing
 * cheap can test. `view.js` owns *when* this runs; this owns *what* it does.
 */

/**
 * Marks a slot that asked to keep its space and had nothing to put in it.
 *
 * A styling hook and nothing more — this file ships no rule for it. The box is
 * only visible at all because the publisher gave the block a border or a
 * background in the editor, which is the same thing that made them want it
 * held open; inventing a placeholder appearance here would put a grey
 * rectangle on pages that asked for a reserved gap.
 */
export const EMPTY_CLASS = 'aggr-slot--empty';

/**
 * Whether an unfilled slot should take itself off the page.
 *
 * **Only an explicit `false` keeps the space.** Collapsing is the shipped
 * behaviour and the safe one — a slot that vanishes costs a publisher a gap
 * they did not want, while a slot that stays costs every reader an empty box
 * on every page — so an absent key, a malformed context, or a block comment
 * predating the attribute all collapse, exactly as they did before it existed.
 *
 * @param {Object} context Block context.
 * @return {boolean} Whether to remove the slot.
 */
export const collapsesWhenEmpty = ( context ) =>
	false !== context?.collapseWhenEmpty;

/**
 * Settles a slot whose first fill returned nothing.
 *
 * Removes the whole wrapper, or marks it and leaves it standing.
 *
 * **The whole wrapper, not the canvas.** Block supports put the border, the
 * padding and the background on the outer element, so hiding only the inner
 * canvas leaves a bordered strip of nothing — which is precisely the empty box
 * collapsing exists to get rid of.
 *
 * `remove()` rather than `display: none` for the same reason: a hidden element
 * still occupies the grid it was placed in, and a slot inside a flex or grid
 * layout would leave a gap where an ad never was.
 *
 * @param {HTMLElement} root    Slot wrapper.
 * @param {Object}      context Block context.
 * @return {boolean} Whether the slot was removed.
 */
export const settleEmptySlot = ( root, context ) => {
	if ( collapsesWhenEmpty( context ) ) {
		root.remove();

		return true;
	}

	root.classList.add( EMPTY_CLASS );

	return false;
};
