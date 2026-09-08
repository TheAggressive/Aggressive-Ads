/* global afterEach, describe, expect, it, jest */

import {
	batchableSlugs,
	contextFrom,
	pageDecisions,
	requestPageDecisions,
	resetPageDecisions,
} from '../batch.js';

/**
 * A page carrying the given slots.
 *
 * @param {Array<{slug: string, fill?: string}>} slots Slots to render.
 */
const page = ( slots ) => {
	document.body.innerHTML = slots
		.map(
			( { slug, fill = '/fill/' + slug } ) =>
				`<div data-aggr-slot="${ slug }" data-aggr-fill="${ fill }" data-aggr-decisions="/wp-json/aggr/v1/decisions"></div>`
		)
		.join( '' );
};

describe( 'page decisions', () => {
	afterEach( () => {
		document.body.replaceChildren();
		resetPageDecisions();
		jest.restoreAllMocks();
	} );

	it( 'asks the server about every slot on the page at once', async () => {
		page( [ { slug: 'leaderboard' }, { slug: 'sidebar' } ] );

		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( {
				decisions: {
					leaderboard: { slot: 'leaderboard' },
					sidebar: { slot: 'sidebar' },
				},
			} ),
		} );

		const decisions = await requestPageDecisions( { fetch: fetchMock } );

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );

		const [ endpoint, options ] = fetchMock.mock.calls[ 0 ];

		expect( endpoint ).toBe( '/wp-json/aggr/v1/decisions' );
		expect( options.method ).toBe( 'POST' );

		const body = JSON.parse( options.body );

		expect( body.slots ).toEqual( [ 'leaderboard', 'sidebar' ] );
		expect( typeof body.w ).toBe( 'number' );

		expect( decisions.get( 'leaderboard' ) ).toEqual( {
			slot: 'leaderboard',
		} );
		expect( decisions.get( 'sidebar' ) ).toEqual( { slot: 'sidebar' } );
	} );

	/*
	 * The batch route decides a page. One slot is not a page, and every page
	 * rule compares a candidate against what another slot already took, so a
	 * batch here would spend a request to reach the answer the per-slot route
	 * already gives.
	 */
	it( 'does not batch a page with a single slot', async () => {
		page( [ { slug: 'leaderboard' } ] );

		const fetchMock = jest.fn();

		expect(
			( await requestPageDecisions( { fetch: fetchMock } ) ).size
		).toBe( 0 );
		expect( fetchMock ).not.toHaveBeenCalled();
	} );

	/*
	 * Two slots sharing a slug get one payload from the route, and a payload
	 * carries the measurement token minted for it. Handing one token to two
	 * slots makes the second impression a replay of the first, which the
	 * server's uniqueness guard correctly refuses to count: two boxes, one
	 * recorded view.
	 */
	it( 'leaves a repeated slug to the per-slot path', async () => {
		page( [
			{ slug: 'leaderboard' },
			{ slug: 'leaderboard' },
			{ slug: 'sidebar' },
			{ slug: 'footer' },
		] );

		expect(
			batchableSlugs(
				Array.from( document.querySelectorAll( '[data-aggr-slot]' ) )
			)
		).toEqual( [ 'sidebar', 'footer' ] );
	} );

	it( 'carries the page context the server baked into the fill URL', () => {
		page( [ { slug: 'a', fill: '/fill/a?p=42' } ] );

		expect(
			contextFrom( document.querySelector( '[data-aggr-slot]' ) )
		).toEqual( { p: 42 } );

		page( [ { slug: 'b', fill: '/fill/b?t=7' } ] );

		expect(
			contextFrom( document.querySelector( '[data-aggr-slot]' ) )
		).toEqual( { t: 7 } );

		page( [ { slug: 'c' } ] );

		expect(
			contextFrom( document.querySelector( '[data-aggr-slot]' ) )
		).toEqual( {} );
	} );

	it( 'posts the page context alongside the slots', async () => {
		page( [
			{ slug: 'leaderboard', fill: '/fill/leaderboard?p=99' },
			{ slug: 'sidebar', fill: '/fill/sidebar?p=99' },
		] );

		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( { decisions: {} } ),
		} );

		await requestPageDecisions( { fetch: fetchMock } );

		expect( JSON.parse( fetchMock.mock.calls[ 0 ][ 1 ].body ).p ).toBe(
			99
		);
	} );

	/*
	 * A page showing unrelated ads is worse than one showing coordinated ads
	 * and far better than one showing none, so a refused or broken batch is an
	 * empty answer rather than an error — every slot then falls back.
	 */
	it( 'answers empty when the route refuses, so slots fall back', async () => {
		page( [ { slug: 'leaderboard' }, { slug: 'sidebar' } ] );

		expect(
			(
				await requestPageDecisions( {
					fetch: jest.fn().mockResolvedValue( { ok: false } ),
				} )
			).size
		).toBe( 0 );
	} );

	it( 'answers empty when the request throws', async () => {
		page( [ { slug: 'leaderboard' }, { slug: 'sidebar' } ] );

		expect(
			(
				await requestPageDecisions( {
					fetch: jest
						.fn()
						.mockRejectedValue( new Error( 'offline' ) ),
				} )
			).size
		).toBe( 0 );
	} );

	/*
	 * Every slot initialises independently and each asks for the page's
	 * decisions. Without memoisation that is one batch request per slot, which
	 * is strictly worse than the per-slot path it replaces.
	 */
	it( 'asks once however many slots want the answer', async () => {
		page( [
			{ slug: 'leaderboard' },
			{ slug: 'sidebar' },
			{ slug: 'footer' },
		] );

		const fetchMock = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( { decisions: {} } ),
		} );

		await Promise.all( [
			pageDecisions( { fetch: fetchMock } ),
			pageDecisions( { fetch: fetchMock } ),
			pageDecisions( { fetch: fetchMock } ),
		] );

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	} );
} );
