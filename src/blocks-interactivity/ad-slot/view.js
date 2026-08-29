/**
 * The ad slot's Interactivity API store.
 *
 * The block is server-rendered and the ad arrives after paint, so the store
 * owns *when* a slot fills and *whether* it keeps filling. What a fill actually
 * renders lives in `fill.js`.
 *
 * Written against `@wordpress/interactivity` rather than as a DOM script,
 * because rotation needs per-slot state — an interval, a refresh count, whether
 * this slot is on screen — and the previous version's `data-aggr-filled="1"`
 * flag was a one-way latch that made a second fill impossible by construction.
 */

import { store, getContext, getElement } from '@wordpress/interactivity';
import { fillSlot } from './fill.js';

/**
 * The shortest rotation this will honour, in seconds.
 *
 * **Every rotation is a new impression.** A two-second interval would
 * manufacture them at fifteen times the rate a reader could plausibly see, and
 * that is the behaviour that gets a publisher removed from an exchange rather
 * than merely producing odd numbers. The editor's own control refuses anything
 * lower; this is the floor that holds when the attribute arrives from somewhere
 * that did not, such as a hand-edited block comment.
 */
export const MIN_ROTATE_SECONDS = 30;

/**
 * The most times one slot will rotate for a single page view.
 *
 * A tab left open overnight must not spend a campaign's whole daily cap. At the
 * default interval this is a little under an hour of continuous viewing, which
 * is far longer than any real session on one page.
 */
export const MAX_ROTATIONS = 100;

/**
 * Seconds an attribute asks for, clamped to something honest.
 *
 * @param {unknown} seconds Requested interval.
 * @return {number} Interval to use.
 */
export const rotationInterval = ( seconds ) => {
	const requested = Number( seconds );

	if ( ! Number.isFinite( requested ) ) {
		return MIN_ROTATE_SECONDS;
	}

	return Math.max( MIN_ROTATE_SECONDS, Math.floor( requested ) );
};

const { state } = store( 'aggr/ad-slot', {
	state: {
		/** Slots that have already had their first fill, keyed by element. */
		filled: new WeakSet(),
	},
	callbacks: {
		/**
		 * Fills the slot once, then rotates it if the block asked for that.
		 *
		 * Bound with `data-wp-init`, so it runs once per slot when the element
		 * enters the document — including a slot inside a block that WordPress
		 * hydrates late, which a `DOMContentLoaded` listener would have missed.
		 */
		async *fill() {
			const { ref } = getElement();
			const context = getContext();

			if ( ! ref || state.filled.has( ref ) ) {
				return;
			}

			state.filled.add( ref );

			const rendered = yield fillSlot( ref );

			// Nothing to rotate to. A slot that answered no-fill once will
			// answer no-fill again in thirty seconds, and asking anyway is a
			// request per slot per interval for as long as the tab is open.
			if ( ! rendered || ! context.rotate ) {
				return;
			}

			startRotation( ref, context );
		},
	},
} );

/**
 * Rotates one slot on an interval, but only while a person could see it.
 *
 * Two conditions gate every tick, and both matter for the same reason: an
 * impression recorded for an ad nobody could see is a fabricated impression.
 *
 * - `document.hidden` — a backgrounded tab rotates nothing. Browsers already
 *   throttle timers there, which is unreliable rather than absent, so this is
 *   explicit.
 * - `IntersectionObserver` — a slot scrolled far off screen rotates nothing.
 *   The same signal P11 measures viewability with, used here to decide whether
 *   the next impression would be worth recording at all.
 *
 * @param {HTMLElement} root    Slot wrapper.
 * @param {Object}      context Block context carrying the interval.
 */
const startRotation = ( root, context ) => {
	const seconds = rotationInterval( context.rotateSeconds );
	let onScreen = true;
	let rotations = 0;
	let busy = false;

	if ( typeof IntersectionObserver === 'function' ) {
		const observer = new IntersectionObserver(
			( entries ) => {
				onScreen = entries.some( ( entry ) => entry.isIntersecting );
			},
			{ threshold: 0 }
		);

		observer.observe( root );
	}

	const timer = window.setInterval( async () => {
		if ( busy || document.hidden || ! onScreen ) {
			return;
		}

		if ( rotations >= MAX_ROTATIONS ) {
			window.clearInterval( timer );

			return;
		}

		busy = true;
		rotations += 1;

		// A failed rotation leaves the ad that is already there. Blanking a
		// slot because one request lost the network is worse than showing the
		// previous creative for another interval.
		await fillSlot( root );

		busy = false;
	}, seconds * 1000 );
};
