import { initialsOf } from '../initials';

describe( 'initialsOf', () => {
	it( 'takes the first and last word, not the first two', () => {
		expect( initialsOf( 'Bright Angle Media' ) ).toBe( 'BM' );
		expect( initialsOf( 'Dana Okonkwo' ) ).toBe( 'DO' );
	} );

	it( 'uses one letter for one word', () => {
		expect( initialsOf( 'acme' ) ).toBe( 'A' );
	} );

	it( 'ignores surrounding and repeated whitespace', () => {
		expect( initialsOf( '  dana   okonkwo ' ) ).toBe( 'DO' );
	} );

	it( 'returns nothing for no name rather than throwing', () => {
		expect( initialsOf( '' ) ).toBe( '' );
		expect( initialsOf( '   ' ) ).toBe( '' );
	} );

	// Indexing a string would return half of a surrogate pair here, which
	// renders as a replacement box.
	it( 'keeps a character outside the basic plane whole', () => {
		expect( initialsOf( '𝒜lpha Beta' ) ).toBe( '𝒜B' );
	} );
} );
