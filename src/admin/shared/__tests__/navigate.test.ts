import { sameOriginUrl } from '../navigate';

/**
 * The origin is read from jsdom rather than hardcoded, so these assert the
 * relationship between the target and the page rather than a literal host.
 */
describe( 'sameOriginUrl', () => {
	const origin = window.location.origin;

	it( 'resolves a relative path against this origin', () => {
		expect( sameOriginUrl( '/advertiser/campaigns/12/' ) ).toBe(
			`${ origin }/advertiser/campaigns/12/`
		);
	} );

	it( 'accepts an absolute URL on this origin', () => {
		expect( sameOriginUrl( `${ origin }/advertiser/` ) ).toBe(
			`${ origin }/advertiser/`
		);
	} );

	it( 'refuses a javascript: URL', () => {
		expect( sameOriginUrl( 'javascript:alert(1)' ) ).toBeNull();
	} );

	it( 'refuses a data: URL', () => {
		expect(
			sameOriginUrl( 'data:text/html,<script>alert(1)</script>' )
		).toBeNull();
	} );

	it( 'refuses another origin', () => {
		expect( sameOriginUrl( 'https://evil.test/steal' ) ).toBeNull();
	} );

	it( 'refuses a protocol-relative URL, which resolves off-origin', () => {
		expect( sameOriginUrl( '//evil.test/steal' ) ).toBeNull();
	} );
} );
