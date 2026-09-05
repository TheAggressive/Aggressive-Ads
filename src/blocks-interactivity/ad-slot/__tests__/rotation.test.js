/* global describe, expect, it */

import { MAX_ROTATIONS, rotationCap } from '../rotation.js';

describe( 'rotation cap', () => {
	it( 'honours the publisher number and will not exceed the hard stop', () => {
		expect( rotationCap( 6 ) ).toBe( 6 );
		expect( rotationCap( 0 ) ).toBe( 0 );
		expect( rotationCap( 400 ) ).toBe( MAX_ROTATIONS );
	} );

	it( 'falls back to the hard stop when the key is absent or unreadable', () => {
		expect( rotationCap( undefined ) ).toBe( MAX_ROTATIONS );
		expect( rotationCap( 'often' ) ).toBe( MAX_ROTATIONS );
	} );
} );
