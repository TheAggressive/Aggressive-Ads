/**
 * @jest-environment jsdom
 */

import { initProposedTitle } from '../shared/propose-title';

jest.mock( '../../admin/shared/navigate', () => ( {
	navigateSameOrigin: jest.fn( () => true ),
} ) );

function mount(
	step = 'details',
	summary = '<p class="aggr-changes__none">Nothing changed yet.</p>'
): HTMLElement {
	document.body.innerHTML = `
		<h1 id="aggr-campaign-title"
			data-aggr-propose="https://site.test/wp-admin/admin-post.php"
			data-aggr-action="aggr_request_campaign_changes"
			data-aggr-campaign="42"
			data-aggr-nonce="n0nce"
			data-aggr-step="${ step }"
			data-aggr-label-rename="Rename campaign"
			data-aggr-label-name="Campaign name"
			data-aggr-label-field="Campaign name"
			data-aggr-label-was="was %s"
			data-aggr-label-saved="New name added to your changes."
			data-aggr-label-error="The name could not be saved.">Spring launch</h1>
		<aside class="aggr-summary" data-aggr-changes-summary>${ summary }</aside>`;

	return document.getElementById( 'aggr-campaign-title' ) as HTMLElement;
}

function replying( body: unknown ): jest.Mock {
	return jest.fn( async () =>
		Promise.resolve( {
			ok: true,
			json: async () => Promise.resolve( body ),
		} as Response )
	);
}

async function rename( heading: HTMLElement, name: string ): Promise< void > {
	( heading.querySelector( 'button' ) as HTMLButtonElement ).click();

	const input = heading.querySelector( 'input' ) as HTMLInputElement;

	input.value = name;
	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter' } ) );

	// The save and what follows it.
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'renaming a running campaign from its heading', () => {
	it( 'stages the name through the edit form’s own handler and shows it as a change', async () => {
		const heading = mount();
		const notify = jest.fn();
		const fetcher = replying( { ok: true, redirect: '/x' } );

		expect( initProposedTitle( heading, notify, fetcher ) ).toBe( true );

		await rename( heading, 'Autumn launch' );

		const body = ( fetcher.mock.calls[ 0 ] as [ string, RequestInit ] )[ 1 ]
			.body as FormData;

		expect( fetcher.mock.calls[ 0 ][ 0 ] ).toBe(
			'https://site.test/wp-admin/admin-post.php'
		);
		expect( body.get( 'action' ) ).toBe( 'aggr_request_campaign_changes' );
		expect( body.get( '_wpnonce' ) ).toBe( 'n0nce' );
		expect( body.get( 'title' ) ).toBe( 'Autumn launch' );
		expect( body.get( 'aggr_async' ) ).toBe( '1' );

		expect( heading.textContent ).toBe( 'Autumn launch' );
		expect( notify ).toHaveBeenCalledWith(
			'New name added to your changes.',
			'success'
		);

		const row = document.querySelector(
			'[data-aggr-change="title"]'
		) as HTMLElement;

		expect( row.textContent ).toContain( 'Autumn launch' );
		expect( row.textContent ).toContain( 'was Spring launch' );
		expect( document.querySelector( '.aggr-changes__none' ) ).toBeNull();
	} );

	it( 'keeps the original name as the "was" when renamed twice', async () => {
		const heading = mount(
			'details',
			'<dl class="aggr-summary__rows"><div data-aggr-change="title"><dt>Campaign name</dt><dd>Autumn <span class="aggr-summary__sub" data-aggr-change-was="Spring launch">was Spring launch</span></dd></div></dl>'
		);

		initProposedTitle( heading, jest.fn(), replying( { ok: true } ) );
		await rename( heading, 'Winter launch' );

		const rows = document.querySelectorAll( '[data-aggr-change="title"]' );

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ]?.textContent ).toContain( 'was Spring launch' );
	} );

	it( 'keeps the old name and says why when the rules refuse it', async () => {
		const heading = mount();
		const notify = jest.fn();

		initProposedTitle(
			heading,
			notify,
			replying( { ok: false, notice: 'Use 200 characters or fewer.' } )
		);
		await rename( heading, 'x' );

		expect( heading.textContent ).toBe( 'Spring launch' );
		expect( notify ).toHaveBeenCalledWith(
			'Use 200 characters or fewer.',
			'error'
		);
		expect(
			document.querySelector( '[data-aggr-change="title"]' )
		).toBeNull();
	} );

	it( 'redraws review, where Submit depends on there being changes', async () => {
		const { navigateSameOrigin } = jest.requireMock(
			'../../admin/shared/navigate'
		) as { navigateSameOrigin: jest.Mock };
		const heading = mount( 'review' );

		initProposedTitle(
			heading,
			jest.fn(),
			replying( { ok: true, redirect: '/review' } )
		);
		await rename( heading, 'Autumn launch' );

		expect( navigateSameOrigin ).toHaveBeenCalledWith( '/review' );
	} );

	it( 'attaches once', () => {
		const heading = mount();

		expect( initProposedTitle( heading, jest.fn(), replying( {} ) ) ).toBe(
			true
		);
		expect( initProposedTitle( heading, jest.fn(), replying( {} ) ) ).toBe(
			false
		);
	} );
} );
