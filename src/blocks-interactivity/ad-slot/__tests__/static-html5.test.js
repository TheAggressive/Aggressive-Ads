/* global afterEach, beforeEach, describe, expect, it, jest */

import { webcrypto, createHash } from 'node:crypto';
import { TextDecoder, TextEncoder } from 'node:util';
import { renderPayload } from '../fill.js';

const source =
	'<!doctype html><html><head></head><body>Reviewed static ad</body></html>';

const responseFor = ( envelope ) => {
	const reader = {
		read: jest
			.fn()
			.mockResolvedValueOnce( {
				done: false,
				value: new TextEncoder().encode( JSON.stringify( envelope ) ),
			} )
			.mockResolvedValue( { done: true } ),
		cancel: jest.fn().mockResolvedValue( undefined ),
	};
	return { ok: true, body: { getReader: () => reader } };
};

const payload = () => ( {
	creative: {
		format: 'html5-static',
		formatVersion: 1,
		image: '/reviewed-fallback.png',
		click: '/native-click',
		token: 'native-token',
		width: 728,
		height: 90,
		alt: 'Reviewed static ad',
		document: '/reviewed.json',
		documentHash: createHash( 'sha256' ).update( source ).digest( 'hex' ),
	},
	beacon: '/native-beacon',
} );

describe( 'static HTML5 refusal and cleanup', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		document.body.innerHTML =
			'<div id="slot"><div class="aggr-slot__canvas"><span>Previous ad</span></div></div>';
		window.HTMLImageElement.prototype.decode = () => Promise.resolve();
		window.TextDecoder = TextDecoder;
		Object.defineProperty( window.crypto, 'subtle', {
			configurable: true,
			value: webcrypto.subtle,
		} );
		window.matchMedia = () => ( { matches: false } );
		window.navigator.sendBeacon = jest.fn();
		window.fetch = jest.fn().mockResolvedValue(
			responseFor( {
				version: 1,
				html5: { version: 1, profile: 'static' },
				document: window.btoa( source ),
			} )
		);
	} );

	afterEach( () => {
		jest.useRealTimers();
		jest.restoreAllMocks();
		document.body.replaceChildren();
	} );

	it( 'authenticates a valid document before committing the native measured link', async () => {
		jest.useRealTimers();
		const root = document.getElementById( 'slot' );
		const pending = renderPayload( root, payload() );
		for (
			let attempt = 0;
			attempt < 20 && ! root.querySelector( 'iframe' );
			attempt++
		) {
			await new Promise( ( resolve ) => window.setTimeout( resolve, 5 ) );
		}
		const frame = root.querySelector( 'iframe' );
		expect( frame ).not.toBeNull();
		expect( frame.getAttribute( 'sandbox' ) ).toBe( '' );
		frame.dispatchEvent( new window.Event( 'load' ) );
		expect( ( await pending ).rendered ).toBe( true );
		expect( root.querySelector( 'img' ) ).toBeNull();
		expect( root.querySelector( 'a' ).getAttribute( 'href' ) ).toBe(
			'/native-click'
		);
		expect( window.navigator.sendBeacon ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'times out a stalled preparation, aborts it and measures only the reviewed fallback', async () => {
		window.fetch = jest
			.fn()
			.mockImplementation( () => new Promise( () => {} ) );
		const root = document.getElementById( 'slot' );
		const pending = renderPayload( root, payload() );
		await jest.advanceTimersByTimeAsync( 4500 );
		expect( await pending ).toEqual( { rendered: true, servable: 0 } );
		expect( window.fetch.mock.calls[ 0 ][ 1 ].signal.aborted ).toBe( true );
		expect( root.querySelector( 'iframe' ) ).toBeNull();
		expect( root.querySelector( 'img' ).getAttribute( 'src' ) ).toBe(
			'/reviewed-fallback.png'
		);
		expect( window.navigator.sendBeacon ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'rejects changed compiled bytes before creating a frame', async () => {
		window.fetch = jest.fn().mockResolvedValue(
			responseFor( {
				version: 1,
				html5: { version: 1, profile: 'static' },
				document: window.btoa( '<script>unreviewed()</script>' ),
			} )
		);
		const root = document.getElementById( 'slot' );
		const pending = renderPayload( root, payload() );
		await jest.advanceTimersByTimeAsync( 50 );
		expect( ( await pending ).rendered ).toBe( true );
		expect( root.querySelector( 'iframe' ) ).toBeNull();
		expect( window.navigator.sendBeacon ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [ 'html5', 'script', 'external' ] )(
		'refuses unqualified envelope profile %s',
		async ( profile ) => {
			window.fetch = jest.fn().mockResolvedValue(
				responseFor( {
					version: 1,
					html5: { version: 1, profile },
					document: window.btoa( source ),
				} )
			);
			const root = document.getElementById( 'slot' );
			expect( ( await renderPayload( root, payload() ) ).rendered ).toBe(
				true
			);
			expect( root.querySelector( 'iframe' ) ).toBeNull();
			expect( window.navigator.sendBeacon ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'does not fetch document resources when reduced motion requests the static fallback', async () => {
		window.matchMedia = () => ( { matches: true } );
		const root = document.getElementById( 'slot' );
		expect( ( await renderPayload( root, payload() ) ).rendered ).toBe(
			true
		);
		expect( window.fetch ).not.toHaveBeenCalled();
		expect( root.querySelector( 'iframe' ) ).toBeNull();
	} );

	it( 'never contacts a foreign document origin, even if a descriptor names one', async () => {
		const data = payload();
		data.creative.document = 'https://unreviewed.example/creative.json';
		expect(
			( await renderPayload( document.getElementById( 'slot' ), data ) )
				.rendered
		).toBe( true );
		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'aborts preparation on teardown and emits no success event for a detached slot', async () => {
		window.fetch = jest
			.fn()
			.mockImplementation( () => new Promise( () => {} ) );
		const root = document.getElementById( 'slot' );
		const pending = renderPayload( root, payload() );
		await jest.advanceTimersByTimeAsync( 1 );
		root.remove();
		await jest.advanceTimersByTimeAsync( 1 );
		expect( await pending ).toEqual( { rendered: false, servable: 0 } );
		expect( window.fetch.mock.calls[ 0 ][ 1 ].signal.aborted ).toBe( true );
		expect( window.navigator.sendBeacon ).not.toHaveBeenCalled();
	} );
} );
