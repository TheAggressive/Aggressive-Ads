/**
 * @jest-environment jsdom
 */

import { formatLocal, isUtcClock, localize } from '../local-time';

describe( 'local time', () => {
	it( 'uses a 12-hour clock where the locale would not', () => {
		expect(
			formatLocal( 'clock', '2026-09-17T14:05:00Z', 'en-GB', 'UTC' )
		).toMatch( /^2:05\s?pm$/i );
		expect(
			formatLocal( 'moment', '2026-09-17T14:05:00Z', 'en-GB', 'UTC' )
		).not.toContain( '14:05' );
	} );

	it( 'states midnight UTC as the viewer’s clock', () => {
		expect(
			formatLocal(
				'clock',
				'2026-09-17T00:00:00Z',
				'en-US',
				'America/Los_Angeles'
			)
		).toBe( '5:00 PM' );
		expect(
			formatLocal(
				'moment',
				'2026-09-17T00:00:00Z',
				'en-US',
				'America/Los_Angeles'
			)
		).toBe( 'Sep 16, 5:00 PM' );
	} );

	it( 'refuses an instant it cannot read', () => {
		expect(
			formatLocal( 'clock', 'not a date', 'en-US', 'UTC' )
		).toBeNull();
	} );

	it( 'knows when a zone already keeps UTC’s clock', () => {
		expect( isUtcClock( '2026-09-17T00:00:00Z', 'UTC' ) ).toBe( true );
		expect( isUtcClock( '2026-09-17T00:00:00Z', 'Europe/London' ) ).toBe(
			false
		);
		expect(
			isUtcClock( '2026-09-17T00:00:00Z', 'America/Los_Angeles' )
		).toBe( false );
	} );

	it( 'rewrites a marked sentence and keeps the UTC one on hover', () => {
		document.documentElement.lang = 'en-US';
		document.body.innerHTML =
			'<p><span data-aggr-local="moment" data-aggr-datetime="2026-09-17T00:00:00Z" data-aggr-local-format="Figures since %s (your time) are still coming in.">Figures from September 17 (UTC) onward are still coming in.</span></p>';

		localize( document, 'America/Los_Angeles' );

		const sentence = document.querySelector( 'span' );

		expect( sentence?.textContent ).toBe(
			'Figures since Sep 16, 5:00 PM (your time) are still coming in.'
		);
		expect( sentence?.title ).toBe(
			'Figures from September 17 (UTC) onward are still coming in.'
		);
	} );

	it( 'leaves the UTC sentence alone for a viewer on UTC', () => {
		document.body.innerHTML =
			'<span data-aggr-local="clock" data-aggr-datetime="2026-09-17T00:00:00Z" data-aggr-local-format="Each day starts at %s your time.">Each day runs midnight to midnight UTC.</span>';

		localize( document, 'UTC' );

		expect( document.querySelector( 'span' )?.textContent ).toBe(
			'Each day runs midnight to midnight UTC.'
		);
	} );
} );
