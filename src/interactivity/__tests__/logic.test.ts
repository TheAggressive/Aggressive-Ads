/**
 * @jest-environment jsdom
 */

import {
	addDays,
	checkCreativeFile,
	debounce,
	isWizardStep,
	nextStep,
	matchFilesToSizes,
	nearMiss,
	normaliseLink,
	parsePixelSize,
	previousStep,
	runEndDate,
	stepIndex,
} from '../logic';

describe( 'destination links', () => {
	it( 'adds https to an address typed without a scheme', () => {
		expect( normaliseLink( 'example.com/page' ) ).toBe(
			'https://example.com/page'
		);
		expect( normaliseLink( '  https://example.com ' ) ).toBe(
			'https://example.com'
		);
		expect( normaliseLink( 'http://example.com' ) ).toBe(
			'http://example.com'
		);
	} );

	it( 'keeps empty as the way to clear the link', () => {
		expect( normaliseLink( '   ' ) ).toBe( '' );
	} );

	it( 'refuses what the server would refuse', () => {
		expect( normaliseLink( 'javascript:alert(1)' ) ).toBeNull();
		expect( normaliseLink( 'ftp://example.com' ) ).toBeNull();
		expect( normaliseLink( 'https://user:pass@example.com' ) ).toBeNull();
		expect( normaliseLink( 'https://' ) ).toBeNull();
	} );
} );

describe( 'wizard steps', () => {
	it( 'accepts only the three display steps', () => {
		expect( isWizardStep( 'details' ) ).toBe( true );
		expect( isWizardStep( 'review' ) ).toBe( true );
		// Folded into review and details. A stale link to either must not
		// address a step the wizard no longer renders.
		expect( isWizardStep( 'submit' ) ).toBe( false );
		expect( isWizardStep( 'destination' ) ).toBe( false );
		expect( isWizardStep( 'lap_draft' ) ).toBe( false );
		expect( isWizardStep( '' ) ).toBe( false );
		// The package step was folded into details. Still accepting it would
		// let a stale link or a stored resume point address a step the wizard
		// no longer renders.
		expect( isWizardStep( 'package' ) ).toBe( false );
	} );

	it( 'walks forward and back without wrapping', () => {
		expect( nextStep( 'details' ) ).toBe( 'creative' );
		expect( nextStep( 'creative' ) ).toBe( 'review' );
		expect( nextStep( 'review' ) ).toBeNull();
		expect( nextStep( 'nope' ) ).toBeNull();
		expect( previousStep( 'creative' ) ).toBe( 'details' );
		expect( previousStep( 'details' ) ).toBeNull();
		expect( stepIndex( 'creative' ) ).toBe( 1 );
	} );
} );

describe( 'run end dates', () => {
	it( 'counts the start day as the first day', () => {
		expect( runEndDate( '2027-10-01', 30 ) ).toBe( '2027-10-30' );
		expect( runEndDate( '2027-10-01', 1 ) ).toBe( '2027-10-01' );
	} );

	it( 'crosses months, years and leap days on the calendar', () => {
		expect( addDays( '2027-12-25', 10 ) ).toBe( '2028-01-04' );
		expect( addDays( '2028-02-28', 1 ) ).toBe( '2028-02-29' );
		expect( addDays( '2027-02-28', 1 ) ).toBe( '2027-03-01' );
		expect( addDays( '2027-03-10', -10 ) ).toBe( '2027-02-28' );
	} );

	it( 'refuses what is not a date or not a length', () => {
		expect( addDays( '2027-02-31', 1 ) ).toBeNull();
		expect( addDays( '', 1 ) ).toBeNull();
		expect( addDays( '2027-1-1', 1 ) ).toBeNull();
		expect( runEndDate( '2027-10-01', 0 ) ).toBeNull();
		expect( runEndDate( '2027-10-01', 2.5 ) ).toBeNull();
		expect( runEndDate( '', 30 ) ).toBeNull();
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

describe( 'matching dropped files to sizes', () => {
	const header = { id: '1', width: 728, height: 90, open: true };
	const breaker = { id: '2', width: 728, height: 90, open: true };
	const side = { id: '3', width: 300, height: 250, open: true };

	it( 'sends a file to the one open placement of its size', () => {
		expect(
			matchFilesToSizes(
				[ { width: 300, height: 250 } ],
				[ header, side ]
			)
		).toEqual( [ { kind: 'one', target: '3' } ] );
	} );

	it( 'asks when two open placements are the size, and claims neither', () => {
		expect(
			matchFilesToSizes(
				[
					{ width: 728, height: 90 },
					{ width: 300, height: 250 },
				],
				[ header, breaker, side ]
			)
		).toEqual( [
			{ kind: 'choose', targets: [ '1', '2' ] },
			{ kind: 'one', target: '3' },
		] );
	} );

	it( 'does not send two files of one size to the same placement', () => {
		const matches = matchFilesToSizes(
			[
				{ width: 300, height: 250 },
				{ width: 300, height: 250 },
			],
			[ side ]
		);

		expect( matches ).toEqual( [
			{ kind: 'one', target: '3' },
			{ kind: 'taken' },
		] );
	} );

	it( 'resolves a choice once the other placement is claimed', () => {
		expect(
			matchFilesToSizes(
				[ { width: 728, height: 90 } ],
				[ header, breaker ],
				new Set( [ '1' ] )
			)
		).toEqual( [ { kind: 'one', target: '2' } ] );
	} );

	it( 'says taken, not none, when the size exists but already has an ad', () => {
		expect(
			matchFilesToSizes(
				[ { width: 728, height: 90 } ],
				[ { ...header, open: false }, side ]
			)
		).toEqual( [ { kind: 'taken' } ] );
	} );

	it( 'says none for a size the package does not have', () => {
		expect(
			matchFilesToSizes( [ { width: 160, height: 600 } ], [ header ] )
		).toEqual( [ { kind: 'none' } ] );
	} );
} );

describe( 'files that nearly fit a size still waiting', () => {
	const header = { id: '1', width: 728, height: 90, open: true };
	const side = { id: '3', width: 300, height: 250, open: true };

	it( 'recognises a retina export at two, three and four times', () => {
		expect( nearMiss( { width: 1456, height: 180 }, [ header ] ) ).toEqual(
			{ kind: 'scaled', target: '1', factor: 2 }
		);
		expect( nearMiss( { width: 1200, height: 1000 }, [ side ] ) ).toEqual( {
			kind: 'scaled',
			target: '3',
			factor: 4,
		} );
	} );

	it( 'recognises a file a pixel or two out', () => {
		expect( nearMiss( { width: 728, height: 91 }, [ header ] ) ).toEqual( {
			kind: 'off',
			target: '1',
		} );
		expect( nearMiss( { width: 730, height: 88 }, [ header ] ) ).toEqual( {
			kind: 'off',
			target: '1',
		} );
	} );

	it( 'stays quiet about anything further off, or an exact fit', () => {
		expect( nearMiss( { width: 731, height: 90 }, [ header ] ) ).toBeNull();
		expect(
			nearMiss( { width: 1456, height: 181 }, [ header ] )
		).toBeNull();
		expect(
			nearMiss( { width: 160, height: 600 }, [ header, side ] )
		).toBeNull();
		expect( nearMiss( { width: 728, height: 90 }, [ header ] ) ).toBeNull();
	} );

	it( 'says nothing about a size that already has an ad', () => {
		expect(
			nearMiss( { width: 1456, height: 180 }, [
				{ ...header, open: false },
			] )
		).toBeNull();
	} );
} );
