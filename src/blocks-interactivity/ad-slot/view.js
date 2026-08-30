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

/**
 * Slots whose first fill has already been dispatched.
 *
 * Module scope rather than store state, matching `autosave.ts` and the rest of
 * this plugin's stores. Interactivity state is a reactive proxy meant for values
 * directives read; a `WeakSet` used purely to make an initializer idempotent is
 * neither reactive nor readable, and putting it there would only make the
 * store's shape misleading.
 */
const started = new WeakSet();

/**
 * Removes a slot that has no ad to show.
 *
 * **The whole wrapper, not the canvas.** Block supports put the border, the
 * padding and the background on the outer element, so hiding only the inner
 * canvas leaves a bordered strip of nothing — which is precisely the empty box
 * this exists to get rid of.
 *
 * `remove()` rather than `display: none` for the same reason: a hidden element
 * still occupies the grid it was placed in, and a slot inside a flex or grid
 * layout would leave a gap where an ad never was.
 *
 * @param {HTMLElement} root Slot wrapper.
 */
const collapse = ( root ) => {
	root.remove();
};

/**
 * Fills a slot and, if the block asked for it, keeps filling it.
 *
 * @param {HTMLElement} root    Slot wrapper.
 * @param {Object}      context Block context.
 */
const run = async ( root, context ) => {
	const rendered = await fillSlot( root );

	/*
	 * Nothing to show, so show nothing.
	 *
	 * The reserved box exists to stop the page jumping when an ad arrives after
	 * paint. That is worth nothing when no ad is coming, and an empty bordered
	 * rectangle is worse than the reflow collapsing costs — a publisher with an
	 * unsold placement should see their own page, not a grey hole in it.
	 *
	 * **Only ever here, on the first fill.** A rotation that comes back empty
	 * leaves the previous ad up rather than collapsing, because a slot vanishing
	 * out from under somebody mid-read is a far worse shift than one that
	 * happens before they have started.
	 */
	if ( ! rendered ) {
		collapse( root );

		return;
	}

	/*
	 * Nothing to rotate to. A slot that answered no-fill once will answer
	 * no-fill again in thirty seconds, and asking anyway is one request per
	 * slot per interval for as long as the tab stays open.
	 */
	if ( ! context.rotate ) {
		return;
	}

	startRotation( root, context );
};

store( 'aggr/ad-slot', {
	callbacks: {
		/**
		 * Fills the slot when the element enters the document.
		 *
		 * A plain synchronous callback dispatching to an async helper, which is
		 * how the rest of this plugin's stores handle async work. The
		 * Interactivity runtime understands a sync callback and a `function*`
		 * generator; an `async function*` is neither, and a directive bound to
		 * one silently never completes — which is exactly how this shipped the
		 * first time and why the browser tests caught it.
		 */
		fill() {
			const { ref } = getElement();

			if ( ! ref || started.has( ref ) ) {
				return;
			}

			started.add( ref );

			void run( ref, getContext() );
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
