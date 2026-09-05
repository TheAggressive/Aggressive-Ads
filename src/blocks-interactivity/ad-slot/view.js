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
import { settleEmptySlot } from './empty.js';
import { rotationCap, rotationInterval } from './rotation.js';

export {
	MAX_ROTATIONS,
	MIN_ROTATE_SECONDS,
	rotationCap,
	rotationInterval,
} from './rotation.js';

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
 * Fills a slot and, if the block asked for it, keeps filling it.
 *
 * @param {HTMLElement} root    Slot wrapper.
 * @param {Object}      context Block context.
 */
const run = async ( root, context ) => {
	const rendered = await fillSlot( root, 0 );

	/*
	 * Nothing to show, so show nothing — unless the block asked otherwise.
	 *
	 * The reserved box exists to stop the page jumping when an ad arrives after
	 * paint. That is worth nothing when no ad is coming, and an empty bordered
	 * rectangle is worse than the reflow collapsing costs — a publisher with an
	 * unsold placement should see their own page, not a grey hole in it. A
	 * fixed-layout page can say otherwise per slot; `empty.js` owns that choice.
	 *
	 * **Only ever here, on the first fill.** A rotation that comes back empty
	 * leaves the previous ad up rather than collapsing, because a slot vanishing
	 * out from under somebody mid-read is a far worse shift than one that
	 * happens before they have started.
	 */
	if ( ! rendered ) {
		settleEmptySlot( root, context );

		return;
	}

	/*
	 * Nothing to rotate to. A slot that answered no-fill once will answer
	 * no-fill again in thirty seconds, and asking anyway is one request per
	 * slot per interval for as long as the tab stays open.
	 *
	 * That holds for a slot keeping its space too, and it is the weaker
	 * argument there: the box is already reserved, so a later fill would cost
	 * no layout shift at all. It stays for now because polling a placement
	 * nobody has sold is a request per slot per interval for the life of the
	 * tab, and a page load is not a long wait for inventory that arrives on a
	 * campaign schedule.
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

		if ( rotations >= rotationCap( context.maxRefreshes ) ) {
			window.clearInterval( timer );

			return;
		}

		busy = true;
		rotations += 1;

		// A failed rotation leaves the ad that is already there. Blanking a
		// slot because one request lost the network is worse than showing the
		// previous creative for another interval.
		await fillSlot( root, rotations );

		busy = false;
	}, seconds * 1000 );
};
