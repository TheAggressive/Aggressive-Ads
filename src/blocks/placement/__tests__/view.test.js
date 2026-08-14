/* global afterEach, describe, expect, it, jest */

import { fillSlot } from '../view.js';

describe( 'placement fill sizing', () => {
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
} );
