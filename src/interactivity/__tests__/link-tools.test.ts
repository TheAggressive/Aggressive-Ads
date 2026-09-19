/**
 * @jest-environment jsdom
 */

import {
	existingTags,
	initLinkCheck,
	initTrackingTags,
	tidyTag,
	withTrackingTags,
	type TrackingTags,
} from '../link-tools';

const none: TrackingTags = {
	source: '',
	medium: '',
	campaign: '',
	content: '',
	term: '',
};

describe( 'tracking tags', () => {
	it( 'adds the tags that were filled in, in order', () => {
		expect(
			withTrackingTags( 'https://example.com/offer', {
				...none,
				source: 'newsletter',
				medium: 'display',
				// Tidied on the way in; see tidyTag.
				campaign: 'autumn sale',
			} )
		).toBe(
			'https://example.com/offer?utm_source=newsletter&utm_medium=display&utm_campaign=autumn-sale'
		);
	} );

	it( 'keeps a query string the advertiser already had', () => {
		expect(
			withTrackingTags( 'https://example.com/offer?ref=partner', {
				...none,
				source: 'aggressive',
			} )
		).toBe( 'https://example.com/offer?ref=partner&utm_source=aggressive' );
	} );

	it( 'never overwrites a tag already on the link', () => {
		const url = 'https://example.com/?utm_source=agency&utm_medium=email';

		expect(
			withTrackingTags( url, {
				...none,
				source: 'ours',
				medium: 'ours',
				campaign: 'autumn',
			} )
		).toBe(
			'https://example.com/?utm_source=agency&utm_medium=email&utm_campaign=autumn'
		);
		expect( existingTags( url ) ).toEqual( [ 'utm_source', 'utm_medium' ] );
	} );

	it( 'keeps the fragment at the end, where the browser needs it', () => {
		expect(
			withTrackingTags( 'https://example.com/page#details', {
				...none,
				source: 'x',
			} )
		).toBe( 'https://example.com/page?utm_source=x#details' );
	} );

	it( 'hands back anything that is not a URL, untouched', () => {
		// "https://exa" is a URL to a browser, host and all, so it is tagged.
		expect(
			withTrackingTags( 'https://exa', { ...none, source: 'x' } )
		).toBe( 'https://exa/?utm_source=x' );
		expect(
			withTrackingTags( 'not a url', { ...none, source: 'x' } )
		).toBe( 'not a url' );
		expect( withTrackingTags( '', { ...none, source: 'x' } ) ).toBe( '' );
		expect( existingTags( 'not a url' ) ).toEqual( [] );
	} );

	it( 'writes values the way analytics tools want them', () => {
		// "Autumn Sale" and "autumn sale" would otherwise be two sources.
		expect( tidyTag( '  Autumn Sale  ' ) ).toBe( 'autumn-sale' );
		expect( tidyTag( 'Spring_2027 Push' ) ).toBe( 'spring-2027-push' );
		expect( tidyTag( 'email/newsletter!' ) ).toBe( 'emailnewsletter' );
		expect( tidyTag( '--edges--' ) ).toBe( 'edges' );
		// A macro survives: the click hop fills it in.
		expect( tidyTag( '{creative_id}' ) ).toBe( '{creative_id}' );
		expect(
			withTrackingTags( 'https://example.com/', {
				...none,
				campaign: 'Autumn Sale',
				content: '{creative_id}',
			} )
		).toBe(
			'https://example.com/?utm_campaign=autumn-sale&utm_content=%7Bcreative_id%7D'
		);
	} );

	it( 'changes nothing when every field is empty', () => {
		expect( withTrackingTags( 'https://example.com/a?b=c', none ) ).toBe(
			'https://example.com/a?b=c'
		);
	} );
} );

describe( 'the tag fold', () => {
	function mount( value = 'https://example.com/offer' ): HTMLElement {
		document.body.innerHTML = `
			<form>
				<input type="url" id="link" name="default_click_url" value="${ value }">
				<details open>
					<summary>Add tracking tags</summary>
					<div data-aggr-tags data-aggr-for="link" data-aggr-label-kept="Kept as typed: %s">
						<button type="button" data-aggr-macro="creative_id">Which ad</button>
						<input data-aggr-tag="source">
						<input data-aggr-tag="medium">
						<input data-aggr-tag="campaign">
						<input data-aggr-tag="content">
						<input data-aggr-tag="term">
						<p data-aggr-tags-preview></p>
						<p data-aggr-tags-note hidden></p>
						<button type="button" data-aggr-tags-apply disabled>Apply</button>
					</div>
				</details>
			</form>`;

		return document.querySelector< HTMLElement >(
			'[data-aggr-tags]'
		) as HTMLElement;
	}

	const field = ( name: string ) =>
		document.querySelector< HTMLInputElement >(
			`[data-aggr-tag="${ name }"]`
		) as HTMLInputElement;
	const link = () => document.getElementById( 'link' ) as HTMLInputElement;
	const apply = () =>
		document.querySelector< HTMLButtonElement >(
			'[data-aggr-tags-apply]'
		) as HTMLButtonElement;
	const preview = () =>
		document.querySelector< HTMLElement >(
			'[data-aggr-tags-preview]'
		) as HTMLElement;

	function type( name: string, value: string ): void {
		field( name ).value = value;
		field( name ).dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	it( 'inserts a click-time value into the field last used', () => {
		const root = mount();

		initTrackingTags( root );
		field( 'campaign' ).focus();
		root
			.querySelector< HTMLButtonElement >(
				'[data-aggr-macro="creative_id"]'
			)
			?.click();

		expect( field( 'campaign' ).value ).toBe( '{creative_id}' );
		expect( preview().textContent ).toContain( '%7Bcreative_id%7D' );
	} );

	it( 'previews without touching the link until Apply', () => {
		initTrackingTags( mount() );

		expect( apply().disabled ).toBe( true );

		type( 'source', 'newsletter' );

		expect( preview().textContent ).toBe(
			'https://example.com/offer?utm_source=newsletter'
		);
		expect(
			preview().querySelector( '.aggr-tags__added' )?.textContent
		).toBe( '?utm_source=newsletter' );
		expect( link().value ).toBe( 'https://example.com/offer' );
		expect( apply().disabled ).toBe( false );
	} );

	it( 'writes the link with the events autosave listens for, then closes', () => {
		const root = mount();
		const seen: string[] = [];

		initTrackingTags( root );
		link().addEventListener( 'input', () => seen.push( 'input' ) );
		link().addEventListener( 'change', () => seen.push( 'change' ) );

		type( 'source', 'newsletter' );
		apply().click();

		expect( link().value ).toBe(
			'https://example.com/offer?utm_source=newsletter'
		);
		expect( seen ).toEqual( [ 'input', 'change' ] );
		expect(
			document.querySelector< HTMLDetailsElement >( 'details' )?.open
		).toBe( false );
		// Applied twice would double nothing, and the button says so.
		expect( apply().disabled ).toBe( true );
	} );

	it( 'says which tags it left as the advertiser typed them', () => {
		initTrackingTags( mount( 'https://example.com/?utm_source=agency' ) );
		type( 'source', 'ours' );

		const note = document.querySelector< HTMLElement >(
			'[data-aggr-tags-note]'
		) as HTMLElement;

		expect( note.hidden ).toBe( false );
		expect( note.textContent ).toBe( 'Kept as typed: utm_source' );
		expect( apply().disabled ).toBe( true );
	} );
} );

describe( 'the link check', () => {
	function mount(): HTMLElement {
		document.body.innerHTML = `
			<form data-aggr-link-check="https://site.test/wp-json/aggr/v1/campaigns/7/link-check"
				data-aggr-nonce="abc123"
				data-aggr-label-works="Link works"
				data-aggr-label-missing="Page not found"
				data-aggr-label-private="Could not be read"
				data-aggr-label-broken="The site returned an error"
				data-aggr-label-unreachable="No answer"
				data-aggr-label-checking="Checking…"
				data-aggr-label-failed="Could not check it">
				<span class="aggr-pill aggr-pill--neutral aggr-ads-link__status" data-aggr-link-chip>Valid link</span>
				<button type="button" data-aggr-link-check-button disabled>Check link</button>
			</form>`;

		return document.querySelector< HTMLElement >(
			'[data-aggr-link-check]'
		) as HTMLElement;
	}

	const chip = () =>
		document.querySelector< HTMLElement >(
			'[data-aggr-link-chip]'
		) as HTMLElement;

	function answer( body: unknown, ok = true ): jest.Mock {
		return jest.fn( async () =>
			Promise.resolve( {
				ok,
				status: ok ? 200 : 422,
				json: async () => Promise.resolve( body ),
			} as Response )
		);
	}

	it( 'asks the campaign’s own route, with the nonce and no URL', async () => {
		const fetcher = answer( { outcome: 'works', status: 200 } );
		const handle = initLinkCheck( mount(), fetcher );

		await handle?.check();

		expect( fetcher ).toHaveBeenCalledTimes( 1 );
		const [ url, init ] = fetcher.mock.calls[ 0 ] as [
			string,
			RequestInit,
		];
		expect( url ).toBe(
			'https://site.test/wp-json/aggr/v1/campaigns/7/link-check'
		);
		expect( init.method ).toBe( 'POST' );
		expect( init.body ).toBeUndefined();
		expect(
			( init.headers as Record< string, string > )[ 'X-WP-Nonce' ]
		).toBe( 'abc123' );
		expect( chip().textContent ).toBe( 'Link works' );
		expect( chip().className ).toContain( 'aggr-pill--live' );
	} );

	it( 'shows each outcome in its own colour', async () => {
		for ( const [ outcome, label, tone ] of [
			[ 'missing', 'Page not found', 'aggr-pill--danger' ],
			[ 'private', 'Could not be read', 'aggr-pill--pending' ],
			[ 'broken', 'The site returned an error', 'aggr-pill--danger' ],
			[ 'unreachable', 'No answer', 'aggr-pill--pending' ],
		] ) {
			const handle = initLinkCheck(
				mount(),
				answer( { outcome, status: 0 } )
			);

			await handle?.check();

			expect( chip().textContent ).toBe( label );
			expect( chip().className ).toContain( tone );
		}
	} );

	it( 'says so when the server refuses, and lets it be tried again', async () => {
		const handle = initLinkCheck( mount(), answer( {}, false ) );
		const button = document.querySelector< HTMLButtonElement >(
			'[data-aggr-link-check-button]'
		) as HTMLButtonElement;

		await handle?.check();

		expect( chip().textContent ).toBe( 'Could not check it' );
		expect( button.disabled ).toBe( false );
	} );

	it( 'attaches once, and enables the button it found disabled', () => {
		const root = mount();

		expect( initLinkCheck( root, answer( {} ) ) ).not.toBeNull();
		expect(
			document.querySelector< HTMLButtonElement >(
				'[data-aggr-link-check-button]'
			)?.disabled
		).toBe( false );
		expect( initLinkCheck( root, answer( {} ) ) ).toBeNull();
	} );
} );

describe( 'checking a running campaign’s proposed link', () => {
	function mount(): HTMLFormElement {
		document.body.innerHTML = `
			<form method="post" action="https://site.test/wp-admin/admin-post.php"
				data-aggr-stage="1"
				data-aggr-link-check="https://site.test/wp-json/aggr/v1/campaigns/7/link-check?proposed=1"
				data-aggr-nonce="abc123"
				data-aggr-label-works="Link works"
				data-aggr-label-failed="Could not check it"
				data-aggr-label-checking="Checking…">
				<input type="hidden" name="action" value="aggr_request_campaign_changes">
				<input type="hidden" name="_wpnonce" value="n0nce">
				<input name="default_click_url" value="https://example.com/new">
				<span data-aggr-link-chip>Valid link</span>
				<p data-aggr-link-status></p>
				<button type="button" data-aggr-link-check-button disabled>Check link</button>
			</form>`;

		return document.querySelector( 'form' ) as HTMLFormElement;
	}

	function replies( ...bodies: unknown[] ): jest.Mock {
		const queue = [ ...bodies ];

		return jest.fn( async () =>
			Promise.resolve( {
				ok: true,
				status: 200,
				json: async () => Promise.resolve( queue.shift() ),
			} as Response )
		);
	}

	it( 'stages the link through the form’s own handler, then checks what was staged', async () => {
		const fetcher = replies(
			{ ok: true },
			{ outcome: 'works', status: 200 }
		);

		await initLinkCheck( mount(), fetcher )?.check();

		expect( fetcher ).toHaveBeenCalledTimes( 2 );

		const [ stageUrl, stage ] = fetcher.mock.calls[ 0 ] as [
			string,
			RequestInit,
		];
		const body = stage.body as FormData;

		// The attribute, not the property an `<input name="action">` shadows.
		expect( stageUrl ).toBe( 'https://site.test/wp-admin/admin-post.php' );
		expect( body.get( 'aggr_async' ) ).toBe( '1' );
		expect( body.get( '_wpnonce' ) ).toBe( 'n0nce' );
		expect( body.get( 'default_click_url' ) ).toBe(
			'https://example.com/new'
		);

		const [ checkUrl, check ] = fetcher.mock.calls[ 1 ] as [
			string,
			RequestInit,
		];

		expect( checkUrl ).toContain( 'proposed=1' );
		expect( check.body ).toBeUndefined();
		expect(
			document.querySelector( '[data-aggr-link-chip]' )?.textContent
		).toBe( 'Link works' );
	} );

	it( 'never checks a link the rules refused, and says why', async () => {
		const fetcher = replies( { ok: false, notice: 'Enter a valid link.' } );

		await initLinkCheck( mount(), fetcher )?.check();

		expect( fetcher ).toHaveBeenCalledTimes( 1 );
		expect(
			document.querySelector( '[data-aggr-link-chip]' )?.textContent
		).toBe( 'Could not check it' );
		expect(
			document.querySelector( '[data-aggr-link-status]' )?.textContent
		).toBe( 'Enter a valid link.' );
		expect(
			document.querySelector< HTMLButtonElement >(
				'[data-aggr-link-check-button]'
			)?.disabled
		).toBe( false );
	} );
} );

describe( 'checking without being asked', () => {
	jest.useFakeTimers();

	function mount( link = 'https://example.com/offer' ): HTMLElement {
		document.body.innerHTML = `
			<form data-aggr-link-check="/wp-json/aggr/v1/campaigns/7/link-check"
				data-aggr-nonce="abc123"
				data-aggr-label-works="Link works"
				data-aggr-label-missing="Page not found"
				data-aggr-label-checking="Checking…"
				data-aggr-label-failed="Could not check it">
				<input type="url" name="default_click_url" value="${ link }">
				<span data-aggr-link-chip hidden></span>
				<button type="button" data-aggr-link-check-button disabled>Check link</button>
			</form>`;

		return document.querySelector< HTMLElement >(
			'[data-aggr-link-check]'
		) as HTMLElement;
	}

	const input = () =>
		document.querySelector< HTMLInputElement >(
			'input[name="default_click_url"]'
		) as HTMLInputElement;

	function answers( outcome = 'works' ): jest.Mock {
		return jest.fn( async () =>
			Promise.resolve( {
				ok: true,
				status: 200,
				json: async () => Promise.resolve( { outcome, status: 200 } ),
			} as Response )
		);
	}

	function change( value: string ): void {
		input().value = value;
		input().dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	it( 'checks a changed link once it settles, not on every keystroke', async () => {
		const fetcher = answers();

		initLinkCheck( mount(), fetcher, 1500 );

		change( 'https://example.com/one' );
		change( 'https://example.com/two' );
		change( 'https://example.com/three' );

		expect( fetcher ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1500 );
		await Promise.resolve();

		expect( fetcher ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'never checks an empty link, or the same one twice', async () => {
		const fetcher = answers();

		initLinkCheck( mount(), fetcher, 1500 );

		change( '' );
		jest.advanceTimersByTime( 1500 );
		await Promise.resolve();

		expect( fetcher ).not.toHaveBeenCalled();

		change( 'https://example.com/offer' );
		jest.advanceTimersByTime( 1500 );
		await Promise.resolve();

		expect( fetcher ).not.toHaveBeenCalled();
	} );
} );
