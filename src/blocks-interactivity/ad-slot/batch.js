/**
 * One page decision for every slot on the page.
 *
 * **JavaScript coordinates requests; PHP decides.** Nothing here knows what a
 * roadblock is, or how competitive separation works, or which creative wins.
 * The only thing this file adds is that the server is asked about the whole
 * page at once, because a rule about a page cannot be applied by an endpoint
 * that is only ever shown one slot.
 *
 * That was the gap: `Page_Decision_Coordinator` had been built, tested and
 * reachable at `POST /aggr/v1/decisions` while every real visitor filled slots
 * one at a time, so competitive separation, roadblocks and category
 * exclusivity never ran outside the test suite.
 */

/** Slots that have not yet been decided, in document order. */
const slotsOnPage = () =>
	Array.from(
		document.querySelectorAll( '[data-aggr-slot][data-aggr-fill]' )
	);

/**
 * The slugs one batch may speak for.
 *
 * A slug appearing twice on a page is excluded and left to the per-slot path.
 * The batch route answers one payload per slug, and a payload carries the
 * measurement token minted for it — handing one token to two slots would make
 * the second impression a replay of the first, which the server's uniqueness
 * guard would then correctly refuse to count. Two boxes, one recorded view.
 *
 * @param {HTMLElement[]} slots Slots on the page.
 * @return {string[]} Slugs that appear exactly once.
 */
export const batchableSlugs = ( slots ) => {
	const seen = new Map();

	for ( const slot of slots ) {
		const slug = slot.dataset.aggrSlot;

		if ( ! slug ) {
			continue;
		}

		seen.set( slug, ( seen.get( slug ) ?? 0 ) + 1 );
	}

	return Array.from( seen.entries() )
		.filter( ( [ , count ] ) => 1 === count )
		.map( ( [ slug ] ) => slug );
};

/**
 * The page context the server baked into a slot's fill URL.
 *
 * Read back off the URL rather than sent by the browser as a fact of its own.
 * `Placement_Slot` put `p` or `t` there at render time because the server knew
 * the page then, and it travels through the page cache correctly for exactly
 * that reason. The batch route has no URL of its own to carry it, so it is
 * lifted from a slot and posted — the same value, from the same origin, not a
 * new claim the browser is inventing.
 *
 * @param {HTMLElement} slot Any slot on the page.
 * @return {{p?: number, t?: number}} Page context, empty when the page had none.
 */
export const contextFrom = ( slot ) => {
	const raw = slot?.dataset?.aggrFill;

	if ( ! raw ) {
		return {};
	}

	let url;

	try {
		url = new URL( raw, window.location.href );
	} catch {
		return {};
	}

	const context = {};

	for ( const key of [ 'p', 't' ] ) {
		const value = Number( url.searchParams.get( key ) );

		if ( Number.isFinite( value ) && value > 0 ) {
			context[ key ] = Math.floor( value );
		}
	}

	return context;
};

/**
 * Asks the server to decide every slot on this page at once.
 *
 * Resolves to a map of slug to payload. An empty map is a complete answer: it
 * means the batch could not be used, and every slot falls back to its own
 * request rather than rendering nothing. A page that shows unrelated ads is
 * worse than one that shows coordinated ads and better than one that shows
 * none.
 *
 * @param {object}   options       Injection seam for tests.
 * @param {Function} options.fetch Fetch implementation.
 * @return {Promise<Map<string, object>>} Payloads by slot slug.
 */
export const requestPageDecisions = async ( {
	fetch: doFetch = fetch,
} = {} ) => {
	const empty = new Map();
	const slots = slotsOnPage();

	if ( 2 > slots.length ) {
		/*
		 * A single slot is not a page decision. Every page rule compares one
		 * candidate against what another slot already took, so with nothing to
		 * compare against the batch would cost a request to reach the same
		 * answer the per-slot route gives.
		 */
		return empty;
	}

	const endpoint = slots[ 0 ].dataset.aggrDecisions;
	const slugs = batchableSlugs( slots );

	if ( ! endpoint || 2 > slugs.length ) {
		return empty;
	}

	const context = contextFrom( slots[ 0 ] );

	/*
	 * Named rather than spread. Both keys are written out so a reader — human
	 * or `check-client-contract.mjs` — can see that this client supplies every
	 * parameter the route declares. A spread satisfies the server and hides
	 * which parameters it satisfies.
	 *
	 * Zero is the route's own default and means "no page reported", which is
	 * what a page with neither a post nor a term archive genuinely has.
	 */
	const body = {
		slots: slugs,
		w: viewportWidth(),
		p: context.p ?? 0,
		t: context.t ?? 0,
	};

	try {
		const response = await doFetch( endpoint, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			body: JSON.stringify( body ),
		} );

		if ( ! response.ok ) {
			return empty;
		}

		const payload = await response.json();
		const decisions = payload?.decisions;

		if ( ! decisions || 'object' !== typeof decisions ) {
			return empty;
		}

		return new Map( Object.entries( decisions ) );
	} catch {
		return empty;
	}
};

/**
 * The viewport the slots are being rendered into.
 *
 * Same measure and same reason as the per-slot path: `clientWidth` excludes the
 * scrollbar, so it matches what CSS media queries and the slot's own box see. A
 * pixel of disagreement puts the decision on the other side of a breakpoint
 * from the box it fills.
 *
 * @return {number} Width in CSS pixels.
 */
export const viewportWidth = () => {
	const width = document.documentElement?.clientWidth ?? window.innerWidth;

	return Number.isFinite( width ) && width > 0 ? Math.floor( width ) : 0;
};

/**
 * The page's decisions, requested once however many slots ask for them.
 *
 * Memoised on the module rather than in store state: every slot initialises
 * independently and they must not each issue a batch, which would be worse than
 * the per-slot path it replaces.
 *
 * @type {Promise<Map<string, object>>|null}
 */
let pending = null;

/**
 * @param {object} options Passed through to {@see requestPageDecisions}.
 * @return {Promise<Map<string, object>>} Payloads by slot slug.
 */
export const pageDecisions = ( options ) => {
	pending = pending ?? requestPageDecisions( options );

	return pending;
};

/** Test seam: forgets the memoised batch. */
export const resetPageDecisions = () => {
	pending = null;
};
