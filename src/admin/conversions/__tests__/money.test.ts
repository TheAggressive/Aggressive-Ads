/**
 * The money field, which has been wrong twice in two different ways.
 */

import { amountToMicros, microsToAmount } from '../money';

describe( 'amountToMicros', () => {
	it( 'parses on the string rather than through a float', () => {
		// 4.99 * 1000000 is 4989999.999999999 in binary floating point, and
		// the naive `Math.round( parseFloat( x ) * 1e6 )` only survives it by
		// luck. These are the amounts where luck runs out.
		expect( amountToMicros( '4.99' ) ).toBe( 4990000 );
		expect( amountToMicros( '0.07' ) ).toBe( 70000 );
		expect( amountToMicros( '1.005' ) ).toBe( 1005000 );
		expect( amountToMicros( '8.61' ) ).toBe( 8610000 );
	} );

	it( 'keeps the sub-cent precision the column exists for', () => {
		expect( amountToMicros( '0.000001' ) ).toBe( 1 );
		expect( amountToMicros( '0.0025' ) ).toBe( 2500 );
	} );

	it( 'reads a comma as a decimal separator', () => {
		expect( amountToMicros( '4,99' ) ).toBe( 4990000 );
	} );

	it( 'answers zero for everything that is not a positive amount', () => {
		expect( amountToMicros( '' ) ).toBe( 0 );
		expect( amountToMicros( '   ' ) ).toBe( 0 );
		expect( amountToMicros( 'free' ) ).toBe( 0 );
		expect( amountToMicros( '-5' ) ).toBe( 0 );
		expect( amountToMicros( '1e6' ) ).toBe( 0 );
	} );

	it( 'takes a partial amount as it is typed', () => {
		// The field converts on every keystroke, so "4." has to mean four
		// rather than nothing — otherwise the currency control disappears
		// between two keys.
		expect( amountToMicros( '4' ) ).toBe( 4000000 );
		expect( amountToMicros( '4.' ) ).toBe( 4000000 );
		expect( amountToMicros( '4.9' ) ).toBe( 4900000 );
	} );
} );

describe( 'microsToAmount', () => {
	it( 'renders at least the cent and no more than it needs', () => {
		expect( microsToAmount( 4990000 ) ).toBe( '4.99' );
		expect( microsToAmount( 4000000 ) ).toBe( '4.00' );
		expect( microsToAmount( 1005000 ) ).toBe( '1.005' );
		expect( microsToAmount( 1 ) ).toBe( '0.000001' );
	} );

	it( 'says nothing at all for no value', () => {
		expect( microsToAmount( 0 ) ).toBe( '' );
		expect( microsToAmount( -1 ) ).toBe( '' );
	} );

	it( 'round-trips what a person typed', () => {
		for ( const typed of [ '49.90', '0.07', '1.005', '4.99' ] ) {
			expect( microsToAmount( amountToMicros( typed ) ) ).toBe(
				typed.replace( /^(\d+\.\d\d)0+$/, '$1' )
			);
		}
	} );
} );
