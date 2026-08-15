/**
 * @jest-environment jsdom
 */

import {
	canVisitStep,
	checkCreativeFile,
	debounce,
	isWizardStep,
	nextStep,
	parsePixelSize,
	previousStep,
	stepIndex,
} from '../logic';

describe( 'wizard steps', () => {
	it( 'accepts only the six display steps', () => {
		expect( isWizardStep( 'details' ) ).toBe( true );
		expect( isWizardStep( 'submit' ) ).toBe( true );
		expect( isWizardStep( 'lap_draft' ) ).toBe( false );
		expect( isWizardStep( '' ) ).toBe( false );
	} );

	it( 'walks forward and back without wrapping', () => {
		expect( nextStep( 'details' ) ).toBe( 'package' );
		expect( nextStep( 'review' ) ).toBe( 'submit' );
		expect( nextStep( 'submit' ) ).toBeNull();
		expect( nextStep( 'nope' ) ).toBeNull();
		expect( previousStep( 'package' ) ).toBe( 'details' );
		expect( previousStep( 'details' ) ).toBeNull();
		expect( stepIndex( 'creative' ) ).toBe( 2 );
	} );

	it( 'gates only the submit step', () => {
		expect( canVisitStep( 'details', false ) ).toBe( true );
		expect( canVisitStep( 'review', false ) ).toBe( true );
		expect( canVisitStep( 'submit', false ) ).toBe( false );
		expect( canVisitStep( 'submit', true ) ).toBe( true );
		expect( canVisitStep( 'unknown', true ) ).toBe( false );
	} );
} );

describe( 'parsePixelSize', () => {
	it( 'reads WxH and multiplication-sign sizes', () => {
		expect( parsePixelSize( '728x90' ) ).toEqual( {
			width: 728,
			height: 90,
		} );
		expect( parsePixelSize( ' 300 × 250 ' ) ).toEqual( {
			width: 300,
			height: 250,
		} );
	} );

	it( 'rejects junk', () => {
		expect( parsePixelSize( '' ) ).toBeNull();
		expect( parsePixelSize( '728' ) ).toBeNull();
		expect( parsePixelSize( '0x90' ) ).toBeNull();
	} );
} );

describe( 'checkCreativeFile', () => {
	const base = {
		mime: 'image/png',
		bytes: 1024,
		width: 300,
		height: 250,
		expectedWidth: 300,
		expectedHeight: 250,
		maxBytes: 2097152,
		maxPixels: 25000000,
		allowedMime: [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
	};

	it( 'accepts an exact-size allowed image', () => {
		expect( checkCreativeFile( base ) ).toEqual( { ok: true } );
	} );

	it( 'rejects empty, disallowed type, oversized bytes, bombs, and wrong size', () => {
		expect( checkCreativeFile( { ...base, bytes: 0 } ) ).toEqual( {
			ok: false,
			code: 'empty',
		} );
		expect(
			checkCreativeFile( { ...base, mime: 'image/svg+xml' } )
		).toEqual( { ok: false, code: 'type' } );
		expect( checkCreativeFile( { ...base, bytes: 2097153 } ) ).toEqual( {
			ok: false,
			code: 'size',
		} );
		expect(
			checkCreativeFile( { ...base, width: 5000, height: 5001 } )
		).toEqual( { ok: false, code: 'pixels' } );
		expect(
			checkCreativeFile( { ...base, width: 728, height: 90 } )
		).toEqual( { ok: false, code: 'dimensions' } );
	} );
} );

describe( 'debounce', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'fires once after the wait, and cancel prevents it', () => {
		const fn = jest.fn();
		const delayed = debounce( fn, 200 );

		delayed();
		delayed();
		jest.advanceTimersByTime( 199 );
		expect( fn ).not.toHaveBeenCalled();
		jest.advanceTimersByTime( 1 );
		expect( fn ).toHaveBeenCalledTimes( 1 );

		delayed();
		delayed.cancel();
		jest.advanceTimersByTime( 500 );
		expect( fn ).toHaveBeenCalledTimes( 1 );
	} );
} );
