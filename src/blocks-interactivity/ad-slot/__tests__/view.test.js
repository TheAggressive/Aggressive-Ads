/* global afterEach, describe, expect, it, jest */

import { fillSlot } from '../fill.js';

describe( 'ad slot fill', () => {
	afterEach( () => {
		document.body.replaceChildren();
		delete window.fetch;
		jest.restoreAllMocks();
	} );

	it( 'renders the creative inside the native-size canvas', async () => {
		document.body.innerHTML = `
			<div data-aggr-slot="leaderboard" data-aggr-fill="/fill">
				<div class="aggr-slot__canvas" style="width:728px;max-width:100%;aspect-ratio:728/90"></div>
			</div>
		`;
		const root = document.querySelector( '[data-aggr-slot]' );
		const canvas = root.querySelector( '.aggr-slot__canvas' );

		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( {
				creative: {
					image: 'https://example.test/leaderboard.png',
					click: 'https://example.test/destination',
					alt: 'Season announcement',
					width: 728,
					height: 90,
				},
			} ),
		} );

		await fillSlot( root );

		const image = canvas.querySelector( 'img' );
		expect( image ).not.toBeNull();
		expect( image.width ).toBe( 728 );
		expect( image.height ).toBe( 90 );
		expect( image.style.width ).toBe( '100%' );
		expect( image.style.height ).toBe( 'auto' );
		expect( root.querySelector( ':scope > a' ) ).toBeNull();
	} );

	it( 'replaces the previous ad instead of stacking a second one', async () => {
		document.body.innerHTML = `
			<div data-aggr-slot="leaderboard" data-aggr-fill="/fill">
				<div class="aggr-slot__canvas"></div>
			</div>
		`;
		const root = document.querySelector( '[data-aggr-slot]' );
		const canvas = root.querySelector( '.aggr-slot__canvas' );

		let nth = 0;

		window.fetch = jest.fn().mockImplementation( async () => ( {
			ok: true,
			json: async () => {
				nth += 1;

				return {
					creative: {
						image: `https://example.test/ad-${ nth }.png`,
						click: 'https://example.test/destination',
						alt: `Ad ${ nth }`,
					},
				};
			},
		} ) );

		expect( await fillSlot( root ) ).toBe( true );
		expect( await fillSlot( root ) ).toBe( true );

		// Rotation swaps the ad; it must never stack them, or the slot grows
		// taller on every interval and the page reflows under the reader.
		expect( canvas.querySelectorAll( 'img' ) ).toHaveLength( 1 );
		expect( canvas.querySelector( 'img' ).alt ).toBe( 'Ad 2' );
	} );

	it( 'reports no fill when the slot has nothing to show', async () => {
		document.body.innerHTML = `
			<div data-aggr-slot="leaderboard" data-aggr-fill="/fill">
				<div class="aggr-slot__canvas"></div>
			</div>
		`;
		const root = document.querySelector( '[data-aggr-slot]' );

		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( { creative: null, house: null } ),
		} );

		// The store uses this to decide not to start rotating a slot that has
		// nothing to rotate to.
		expect( await fillSlot( root ) ).toBe( false );
	} );
} );
