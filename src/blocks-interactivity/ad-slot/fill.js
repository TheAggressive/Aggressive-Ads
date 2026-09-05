/**
 * Fetching one ad and putting it on the page.
 *
 * Separated from the Interactivity store so the part with the interesting
 * behaviour — what gets rendered, and what happens when a fill returns nothing
 * — is reachable from a unit test without a store, a directive, or a DOM
 * lifecycle. `view.js` owns *when* this runs; this owns *what* it does.
 */

import { observeViewability } from './viewability.js';

/**
 * Builds the anchor and image for one creative.
 *
 * @param {Object} creative Creative payload from the fill endpoint.
 * @return {HTMLAnchorElement} The link to insert.
 */
const buildAd = ( creative ) => {
	const link = document.createElement( 'a' );
	link.href = creative.click;
	link.rel = 'noopener noreferrer';
	link.style.display = 'block';
	link.style.width = '100%';

	const image = document.createElement( 'img' );
	image.src = creative.image;
	image.alt = creative.alt ?? '';
	image.decoding = 'async';
	image.style.display = 'block';
	image.style.width = '100%';
	image.style.height = 'auto';

	if ( Number.isInteger( creative.width ) && creative.width > 0 ) {
		image.width = creative.width;
	}

	if ( Number.isInteger( creative.height ) && creative.height > 0 ) {
		image.height = creative.height;
	}

	link.appendChild( image );

	return link;
};

/**
 * Fills one slot once, replacing whatever is in its canvas.
 *
 * **Replaces rather than appends**, which is what makes rotation possible at
 * all: the outer shell keeps its block supports — borders, spacing, alignment —
 * and only the ad inside changes, so nothing reflows around it when a rotation
 * swaps one creative for another.
 *
 * Returns whether an ad was rendered, so the caller can decide whether a
 * rotation is worth continuing. A slot that answered `no_fill` is a slot with
 * nothing to rotate to.
 *
 * The sequence is which fill this is within the page view, zero-based. The
 * server cannot infer it — the endpoint is stateless and sits behind a page
 * cache — so the client says. A request that omits it is a page opportunity,
 * which is what every fill from a cached page predating this parameter is.
 *
 * @param {HTMLElement} root     Slot wrapper.
 * @param {number}      sequence Fill number within the page view, zero-based.
 * @return {Promise<boolean>} Whether an ad is now on the page.
 */
export const fillSlot = async ( root, sequence = 0 ) => {
	const url = root.dataset.aggrFill;

	if ( ! url ) {
		return false;
	}

	const declared = Number( sequence );
	const n =
		Number.isFinite( declared ) && declared > 0
			? Math.floor( declared )
			: 0;

	const endpoint = new URL( url, window.location.href );
	endpoint.searchParams.set( 'n', String( n ) );

	try {
		const response = await fetch( endpoint.href, {
			credentials: 'omit',
			headers: { Accept: 'application/json' },
		} );

		if ( ! response.ok ) {
			return false;
		}

		const payload = await response.json();
		const creative = payload.creative ?? payload.house;

		if ( ! creative?.image || ! creative.click ) {
			return false;
		}

		const canvas =
			root.querySelector( ':scope > .aggr-slot__canvas' ) ?? root;

		/*
		 * The previous ad's observer is dropped with the element it watched.
		 * `observeViewability` disconnects on its own once it has reported, and
		 * an observer whose target has left the document reports nothing — so a
		 * rotation cannot let one creative's dwell time count toward the next.
		 */
		canvas.replaceChildren( buildAd( creative ) );

		if ( payload.beacon && creative.token ) {
			window.navigator.sendBeacon?.(
				payload.beacon,
				new URLSearchParams( { token: creative.token } )
			);

			// Observation starts after the ad is in the document, so the first
			// measurement describes something a person could actually see.
			if ( payload.viewability ) {
				observeViewability( canvas, {
					ratio: payload.viewability.ratio,
					dwellMs: payload.viewability.dwell_ms,
					beacon: payload.beacon,
					token: creative.token,
				} );
			}
		}

		return true;
	} catch {
		return false;
	}
};
