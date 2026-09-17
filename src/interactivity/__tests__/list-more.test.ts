/**
 * @jest-environment jsdom
 */

import { fill, initListMore } from '../list-more';

const labels =
	'data-aggr-label-more="Show more campaigns" data-aggr-label-loading="Loading…" data-aggr-label-error="Could not load more." data-aggr-label-count="Showing %1$s–%2$s of %3$s" data-aggr-label-loaded="%1$s more loaded. Showing %2$s of %3$s." data-aggr-label-done="All %s campaigns shown."';

function rowsHtml( ids: number[] ): string {
	return ids
		.map(
			( id ) =>
				`<tr data-aggr-row="${ id }"><td><a href="/c/${ id }">Campaign ${ id }</a></td></tr>`
		)
		.join( '' );
}

function page( ids: number[], next: string ): string {
	return `<table><tbody data-aggr-list-rows>${ rowsHtml(
		ids
	) }</tbody></table><div data-aggr-list-more data-aggr-next="${ next }"></div>`;
}

function mount( ids: number[], next: string, total: number ): HTMLElement {
	document.body.innerHTML = `<table><tbody data-aggr-list-rows>${ rowsHtml(
		ids
	) }</tbody></table><div data-aggr-list-more data-aggr-next="${ next }" data-aggr-total="${ total }" data-aggr-first="1" ${ labels }><span data-aggr-list-count>Showing 1–${
		ids.length
	} of ${ total }</span><nav class="aggr-pager"><a href="${ next }">Next</a></nav><p data-aggr-list-status></p></div>`;

	return document.querySelector( '[data-aggr-list-more]' ) as HTMLElement;
}

const ok = ( body: string ): Promise< Response > =>
	Promise.resolve( {
		ok: true,
		text: () => Promise.resolve( body ),
	} as Response );

describe( 'show more campaigns', () => {
	it( 'fills placeholders in order', () => {
		expect( fill( 'Showing %1$s–%2$s of %3$s', [ '1', '40', '63' ] ) ).toBe(
			'Showing 1–40 of 63'
		);
		expect( fill( 'All %s campaigns shown.', [ '63' ] ) ).toBe(
			'All 63 campaigns shown.'
		);
	} );

	it( 'does nothing when there is no next page', () => {
		const footer = mount( [ 1, 2 ], '', 2 );

		expect( initListMore( footer, jest.fn() ) ).toBeNull();
		expect( document.querySelector( 'button' ) ).toBeNull();
		expect(
			( document.querySelector( '.aggr-pager' ) as HTMLElement ).hidden
		).toBe( false );
	} );

	it( 'appends the next page, announces the count and focuses the first new campaign', async () => {
		const footer = mount( [ 1, 2 ], '/campaigns/?list_page=2', 5 );
		const fetcher = jest.fn( () =>
			ok( page( [ 3, 4 ], '/campaigns/?list_page=3' ) )
		);
		const list = initListMore( footer, fetcher );

		expect(
			( document.querySelector( '.aggr-pager' ) as HTMLElement ).hidden
		).toBe( true );

		await list?.load( true );

		expect( fetcher ).toHaveBeenCalledWith(
			'http://localhost/campaigns/?list_page=2'
		);
		expect( document.querySelectorAll( '[data-aggr-row]' ) ).toHaveLength(
			4
		);
		expect(
			document.querySelector( '[data-aggr-list-count]' )?.textContent
		).toBe( 'Showing 1–4 of 5' );
		expect(
			document.querySelector( '[data-aggr-list-status]' )?.textContent
		).toBe( '2 more loaded. Showing 4 of 5.' );
		expect( document.activeElement?.getAttribute( 'href' ) ).toBe( '/c/3' );
	} );

	it( 'does not move focus when a load was not asked for', async () => {
		const footer = mount( [ 1 ], '/campaigns/?list_page=2', 2 );
		const list = initListMore( footer, () => ok( page( [ 2 ], '' ) ) );
		const before = document.activeElement;

		await list?.load( false );

		expect( document.activeElement ).toBe( before );
	} );

	it( 'skips a row already on screen and finishes when the last page arrives', async () => {
		const footer = mount( [ 1, 2 ], '/campaigns/?list_page=2', 3 );
		const list = initListMore( footer, () => ok( page( [ 2, 3 ], '' ) ) );

		await list?.load( true );

		expect(
			Array.from(
				document.querySelectorAll< HTMLElement >( '[data-aggr-row]' )
			).map( ( row ) => row.dataset.aggrRow )
		).toEqual( [ '1', '2', '3' ] );
		expect(
			document.querySelector( '[data-aggr-list-status]' )?.textContent
		).toBe( 'All 3 campaigns shown.' );
		expect( document.querySelector( 'button' ) ).toBeNull();
	} );

	it( 'says so and brings the page links back when a load fails', async () => {
		const footer = mount( [ 1 ], '/campaigns/?list_page=2', 2 );
		const list = initListMore( footer, () =>
			Promise.resolve( { ok: false, status: 500 } as Response )
		);

		await list?.load( true );

		expect(
			document.querySelector( '[data-aggr-list-status]' )?.textContent
		).toBe( 'Could not load more.' );
		expect(
			( document.querySelector( '.aggr-pager' ) as HTMLElement ).hidden
		).toBe( false );
		expect(
			( document.querySelector( 'button' ) as HTMLButtonElement ).disabled
		).toBe( false );
		expect( document.querySelectorAll( '[data-aggr-row]' ) ).toHaveLength(
			1
		);
	} );

	it( 'never fetches another site', async () => {
		const footer = mount( [ 1 ], 'https://elsewhere.example/steal', 2 );
		const fetcher = jest.fn();
		const list = initListMore( footer, fetcher );

		await list?.load( true );

		expect( fetcher ).not.toHaveBeenCalled();
		expect(
			( document.querySelector( '.aggr-pager' ) as HTMLElement ).hidden
		).toBe( false );
	} );
} );
