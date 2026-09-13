/**
 * @jest-environment jsdom
 */

import { canRestoreFocus, endpointOf, setupFocusTrap } from '../helpers';

describe( 'canRestoreFocus', () => {
	it( 'rejects null and detached nodes', () => {
		expect( canRestoreFocus( null ) ).toBe( false );

		const orphan = document.createElement( 'button' );
		expect( canRestoreFocus( orphan ) ).toBe( false );
	} );

	it( 'accepts a connected non-body element', () => {
		const button = document.createElement( 'button' );
		document.body.appendChild( button );
		expect( canRestoreFocus( button ) ).toBe( true );
		button.remove();
	} );

	it( 'rejects document.body', () => {
		expect( canRestoreFocus( document.body ) ).toBe( false );
	} );
} );

describe( 'setupFocusTrap', () => {
	let container: HTMLElement;
	let cleanup: () => void;

	/**
	 * Builds a container holding the given markup and traps focus in it.
	 *
	 * @param html Container inner markup.
	 */
	function trap( html: string ): void {
		container = document.createElement( 'div' );
		container.innerHTML = html;
		document.body.appendChild( container );
		cleanup = setupFocusTrap( container );
	}

	/**
	 * Dispatches a Tab keypress from whatever currently holds focus.
	 *
	 * Dispatched on the active element so it bubbles to the container the way a
	 * real one does — dispatching on the container directly would pass even if
	 * the listener were attached somewhere focus never reaches.
	 *
	 * @param shiftKey Whether Shift is held.
	 * @return The dispatched event, so callers can assert on preventDefault.
	 */
	function pressTab( shiftKey = false ): KeyboardEvent {
		const event = new KeyboardEvent( 'keydown', {
			key: 'Tab',
			shiftKey,
			bubbles: true,
			cancelable: true,
		} );

		( document.activeElement ?? document.body ).dispatchEvent( event );

		return event;
	}

	afterEach( () => {
		cleanup?.();
		container?.remove();
	} );

	it( 'wraps forward from the last focusable to the first', () => {
		trap( '<button id="a">a</button><button id="b">b</button>' );

		document.getElementById( 'b' )?.focus();
		pressTab();

		expect( document.activeElement?.id ).toBe( 'a' );
	} );

	it( 'wraps backward from the first focusable to the last', () => {
		trap( '<button id="a">a</button><button id="b">b</button>' );

		document.getElementById( 'a' )?.focus();
		pressTab( true );

		expect( document.activeElement?.id ).toBe( 'b' );
	} );

	it( 'moves forward through the cycle without leaving it', () => {
		trap(
			'<button id="a">a</button><a href="#x" id="b">b</a>' +
				'<input id="c" /><textarea id="d"></textarea>'
		);

		document.getElementById( 'a' )?.focus();

		// One press per element, so the last one crosses the boundary.
		const seen = [ 'b', 'c', 'd', 'a' ].map( ( expected ) => {
			pressTab();
			return [ document.activeElement?.id, expected ];
		} );

		seen.forEach( ( [ actual, expected ] ) => {
			expect( actual ).toBe( expected );
		} );
	} );

	it( 'skips elements inside hidden or inert subtrees', () => {
		trap(
			'<button id="a">a</button>' +
				'<div hidden><button id="hidden-one">no</button></div>' +
				'<div inert><button id="inert-one">no</button></div>' +
				'<button id="b">b</button>'
		);

		document.getElementById( 'a' )?.focus();
		pressTab();

		expect( document.activeElement?.id ).toBe( 'b' );

		pressTab();

		expect( document.activeElement?.id ).toBe( 'a' );
	} );

	it( 'skips disabled controls and tabindex -1', () => {
		trap(
			'<button id="a">a</button><button id="off" disabled>off</button>' +
				'<div tabindex="-1" id="skipped">no</div><button id="b">b</button>'
		);

		document.getElementById( 'a' )?.focus();
		pressTab();

		expect( document.activeElement?.id ).toBe( 'b' );
	} );

	it( 'swallows Tab when the container holds nothing focusable', () => {
		// The real shape of an empty dialog: the panel carries tabindex="-1" and
		// is focused on open, and tabindex="-1" is excluded from the cycle. So
		// focus is inside the container while the cycle is empty, and Tab must
		// go nowhere rather than escaping to the page behind.
		trap( '<div id="panel" tabindex="-1"><p>Nothing to focus.</p></div>' );

		document.getElementById( 'panel' )?.focus();

		const event = pressTab();

		expect( event.defaultPrevented ).toBe( true );
		expect( document.activeElement?.id ).toBe( 'panel' );
	} );

	it( 'ignores keys that are not Tab', () => {
		trap( '<button id="a">a</button><button id="b">b</button>' );

		document.getElementById( 'a' )?.focus();

		const event = new KeyboardEvent( 'keydown', {
			key: 'Enter',
			bubbles: true,
			cancelable: true,
		} );
		document.activeElement?.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( false );
		expect( document.activeElement?.id ).toBe( 'a' );
	} );

	it( 'stops trapping once cleaned up', () => {
		trap( '<button id="a">a</button><button id="b">b</button>' );

		document.getElementById( 'b' )?.focus();
		cleanup();

		const event = pressTab();

		// Focus is left exactly where the browser would take over.
		expect( event.defaultPrevented ).toBe( false );
		expect( document.activeElement?.id ).toBe( 'b' );
	} );
} );

describe( 'endpointOf', () => {
	/**
	 * **The bug this exists for cost a browser to find.**
	 *
	 * Every WordPress `admin-post.php` form carries a hidden control named
	 * `action`, and named controls shadow properties on the form element — so
	 * `form.action` returns that input rather than the URL. `fetch()` then
	 * stringifies it to `[object HTMLInputElement]`, posts to a nonsense
	 * relative address, and the 404 that comes back is not JSON. What the
	 * reader saw was the page reloading as though none of it existed.
	 */
	it( 'reads the attribute even when a control named action shadows it', () => {
		const form = document.createElement( 'form' );
		form.setAttribute(
			'action',
			'https://example.test/wp-admin/admin-post.php'
		);

		const shadow = document.createElement( 'input' );
		shadow.type = 'hidden';
		shadow.name = 'action';
		shadow.value = 'aggr_set_creative_destination';
		form.appendChild( shadow );
		document.body.appendChild( form );

		/*
		 * **This test cannot catch the regression, and says so rather than
		 * implying otherwise.** jsdom returns the URL from `form.action` even
		 * with the control present, because it does not implement the
		 * `[LegacyOverrideBuiltIns]` behaviour that makes a real browser hand
		 * back the input. Reverting the helper to `form.action` would leave
		 * this passing. What it does pin is the contract — the attribute is
		 * what gets read — and `check-form-endpoints` fails the build if any
		 * module goes back to the property.
		 */
		expect( endpointOf( form ) ).toBe(
			'https://example.test/wp-admin/admin-post.php'
		);

		form.remove();
	} );

	it( 'falls back to the current address when no action is set', () => {
		const form = document.createElement( 'form' );
		document.body.appendChild( form );

		expect( endpointOf( form ) ).toBe( window.location.href );

		form.remove();
	} );
} );
