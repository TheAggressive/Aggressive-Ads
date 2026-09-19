/**
 * @jest-environment jsdom
 */

import type { BodyHandlers } from '../shared/upload-send';

// jsdom has no CSS.escape; the ids here need none.
( globalThis as unknown as { CSS: { escape: ( s: string ) => string } } ).CSS =
	{ escape: ( s: string ) => s };

jest.mock( '@aggr/logic', () => jest.requireActual( '../logic' ), {
	virtual: true,
} );

const mockSent: Array< { url: string; body: FormData; on: BodyHandlers } > = [];

jest.mock( '../shared/upload-send', () => ( {
	actionOf: () => 'https://site.test/wp-admin/admin-post.php',
	sendBody: ( url: string, body: FormData, on: BodyHandlers ) => {
		mockSent.push( { url, body, on } );

		return { cancel: () => undefined };
	},
} ) );

const mockNavigate = jest.fn( ( _target: string ) => true );

jest.mock( '../../admin/shared/navigate', () => ( {
	navigateSameOrigin: ( target: string ) => mockNavigate( target ),
} ) );

import { wireBulkUpload } from '../shared/bulk-upload';

const uploads = {
	'1': {
		expectedSize: '728x90',
		maxBytes: 150000,
		maxPixels: 25000000,
		allowedMime: [ 'image/png' ],
		sizeMessage: 'Too big.',
		name: 'Header',
		open: true,
	},
	'2': {
		expectedSize: '300x250',
		maxBytes: 150000,
		maxPixels: 25000000,
		allowedMime: [ 'image/png' ],
		sizeMessage: 'Too big.',
		name: 'Sidebar',
		open: true,
	},
};

const i18n: Record< string, string > = {
	bulkGoesTo: 'Goes to %s.',
	bulkWaitingLink: 'Goes to %s once the link is added.',
	bulkNeedsUrl: 'Add the link.',
	bulkSent: 'Uploaded.',
	bulkRefused: 'Not uploaded.',
	bulkSentUnknown: 'Sent. Checking the result…',
	bulkPartial: 'These files did not reach the server.',
	bulkLost: 'Lost: %s.',
	bulkDone: 'Uploaded %d.',
	uploadFailed: 'Failed.',
};

const sizes: Record< string, { width: number; height: number } > = {
	'header.png': { width: 728, height: 90 },
	'side.png': { width: 300, height: 250 },
};

function page( link: string ): HTMLElement {
	document.body.innerHTML = `
		<input id="aggr-campaign-link" type="url" value="${ link }">
		<div data-aggr-bulk>
			<button data-aggr-bulk-browse></button>
			<input type="file" data-aggr-bulk-input>
			<p data-aggr-bulk-status></p>
			<ul data-aggr-bulk-list hidden></ul>
		</div>
		<div id="aggr-uploads">
			<form data-aggr-upload="1"><input name="click_url" type="url" value="${ link }"></form>
			<form data-aggr-upload="2"><input name="click_url" type="url" value="${ link }"></form>
		</div>`;

	const zone = document.querySelector< HTMLElement >( '[data-aggr-bulk]' )!;

	wireBulkUpload( zone, {
		uploads: () => uploads,
		i18n: () => i18n,
		readImageSize: async ( file ) => sizes[ file.name ]!,
	} );

	return zone;
}

async function drop( zone: HTMLElement, names: string[] ): Promise< void > {
	const files = names.map(
		( name ) => new File( [ 'x' ], name, { type: 'image/png' } )
	);
	const event = new Event( 'drop' ) as Event & {
		dataTransfer: { files: File[] };
	};

	event.dataTransfer = { files };
	zone.dispatchEvent( event );

	// readImageSize is async, once per file.
	for ( let i = 0; i < names.length + 2; i++ ) {
		await Promise.resolve();
	}
}

function status( zone: HTMLElement ): string {
	return zone.querySelector( '[data-aggr-bulk-status]' )?.textContent ?? '';
}

beforeEach( () => {
	mockSent.length = 0;
	mockNavigate.mockClear();
	window.sessionStorage.clear();
} );

describe( 'the drop zone round', () => {
	it( 'reloads when an answer cannot be read, because the server has the file', async () => {
		const zone = page( 'https://example.com' );

		await drop( zone, [ 'header.png', 'side.png' ] );

		expect( mockSent ).toHaveLength( 1 );
		mockSent[ 0 ]!.on.done( '', '<p>Notice: something</p>{"ok":true}' );
		expect( mockSent ).toHaveLength( 2 );
		mockSent[ 1 ]!.on.done( '', '<html>not json</html>' );

		expect( mockNavigate ).toHaveBeenCalledTimes( 1 );
		expect( status( zone ) ).not.toContain( 'did not reach' );
	} );

	it( 'follows the server to its success page', async () => {
		const zone = page( 'https://example.com' );

		await drop( zone, [ 'header.png' ] );
		mockSent[ 0 ]!.on.done(
			'',
			JSON.stringify( { ok: true, redirect: '/campaigns/7/?done=1' } )
		);

		expect( mockNavigate ).toHaveBeenCalledWith( '/campaigns/7/?done=1' );
	} );

	it( 'stays, and says so, only when nothing reached the server', async () => {
		const zone = page( 'https://example.com' );

		await drop( zone, [ 'header.png' ] );
		mockSent[ 0 ]!.on.fail( 'error' );

		expect( mockNavigate ).not.toHaveBeenCalled();
		expect( status( zone ) ).toBe(
			'These files did not reach the server.'
		);
	} );

	it( 'names a lost file on the page it moves on to, when others landed', async () => {
		const zone = page( 'https://example.com' );

		await drop( zone, [ 'header.png', 'side.png' ] );
		mockSent[ 0 ]!.on.fail( 'error' );
		mockSent[ 1 ]!.on.done(
			'',
			JSON.stringify( { ok: true, redirect: '/c/7/' } )
		);

		expect( mockNavigate ).toHaveBeenCalledWith( '/c/7/' );
		expect(
			window.sessionStorage.getItem( 'aggr-bulk-notes:/c/7/' )
		).toContain( 'Lost: header.png.' );
	} );

	it( 'holds files until there is a link, then sends each once', async () => {
		const zone = page( '' );

		await drop( zone, [ 'header.png', 'side.png' ] );

		expect( mockSent ).toHaveLength( 0 );
		expect( status( zone ) ).toBe( 'Add the link.' );
		expect( zone.textContent ).toContain(
			'Goes to Header once the link is added.'
		);

		const link = document.getElementById(
			'aggr-campaign-link'
		) as HTMLInputElement;

		link.value = 'example.com/page';
		link.dispatchEvent( new Event( 'change' ) );
		link.dispatchEvent( new Event( 'blur' ) );

		expect( mockSent ).toHaveLength( 1 );
		expect( mockSent[ 0 ]!.body.get( 'click_url' ) ).toBe(
			'https://example.com/page'
		);
		expect( mockSent[ 0 ]!.body.get( 'aggr_async' ) ).toBe( '1' );

		mockSent[ 0 ]!.on.done(
			'',
			JSON.stringify( { ok: true, redirect: '/c/7/' } )
		);
		mockSent[ 1 ]!.on.done(
			'',
			JSON.stringify( { ok: true, redirect: '/c/7/' } )
		);

		expect( mockSent ).toHaveLength( 2 );
		expect( mockNavigate ).toHaveBeenCalledTimes( 1 );
	} );
} );
