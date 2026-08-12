/**
 * @jest-environment jsdom
 */

import { canRestoreFocus } from '../helpers';

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
