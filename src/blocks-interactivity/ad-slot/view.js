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
import { fillSlot, renderPayload } from './fill.js';
import { pageDecisions } from './batch.js';
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
 * The first fill, taken from the page's decision when there is one.
 *
 * **The page is asked once, and only about the first fill.** Page rules —
 * competitive separation, roadblocks, category exclusivity — are about which
 * ads appear together on a page view, which is a question that exists at page
 * load and not on a rotation thirty seconds later. A rotation refreshes one
 * slot and goes back down the per-slot route.
 *
 * The per-slot route is therefore kept deliberately rather than left behind:
 * it serves rotations, single-slot pages, a slug that appears twice, and any
 * page where the batch could not answer. There is no second decision
 * implementation in either case — both routes end in the same PHP pipeline, and
 * the payload they return is painted by the same `renderPayload`.
 *
 * @param {HTMLElement} root Slot wrapper.
 * @return {Promise<{rendered: boolean, servable: number}>} What was rendered.
 */
const firstFill = async ( root ) => {
	const slug = root.dataset.aggrSlot;
	const decided = slug ? ( await pageDecisions() ).get( slug ) : undefined;

	if ( decided ) {
		return renderPayload( root, decided );
	}

	return fillSlot( root, 0 );
};

/**
 * Fills a slot and, if the block asked for it, keeps filling it.
 *
 * @param {HTMLElement} root    Slot wrapper.
 * @param {Object}      context Block context.
 */
const run = async ( root, context ) => {
	const { rendered, servable } = await firstFill( root );

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

	/*
	 * **Nothing else to show, so stop showing the same thing again.**
	 *
	 * Rotation re-decides and can legitimately land on the same creative — an
	 * advertiser who bought ninety per cent of a slot's share should appear
	 * nine times in ten, and excluding whatever showed last would quietly
	 * hand that share to somebody who did not buy it. Repeats are the
	 * weighting working.
	 *
	 * One candidate is a different thing. There the repeat is certain, the
	 * image never changes, and the rotation exists only to fire another
	 * beacon: at the one-second floor that is sixty impressions an hour from
	 * one reader looking at one unchanged advertisement, which is the volume
	 * an exchange calls invalid traffic. A house advertisement and an unsold
	 * slot report zero for the same reason.
	 *
	 * The set can grow while the page is open — a campaign going live, a
	 * budget freeing up — and this will not notice. A page view is bounded
	 * and the next one asks again, which is a better trade than minting
	 * impressions nobody saw a second version of.
	 */
	if ( servable < 2 ) {
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
		const { rendered, servable } = await fillSlot( root, rotations );

		/*
		 * The inventory can shrink underneath a rotating slot — a campaign
		 * ending, a budget running out, a frequency cap closing — and from
		 * that point every further rotation redraws the same advertisement.
		 * A failed request is not evidence of that and must not stop the
		 * timer, which is why this asks whether the fill succeeded first.
		 */
		if ( rendered && servable < 2 ) {
			window.clearInterval( timer );
		}

		busy = false;
	}, seconds * 1000 );
};
