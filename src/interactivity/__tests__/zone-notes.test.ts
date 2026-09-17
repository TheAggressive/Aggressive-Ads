/**
 * @jest-environment jsdom
 */

import { edgeLabel, initZoneNotes, zonedInstant } from '../zone-notes';

describe( 'a day’s start and end in the site zone', () => {
	it( 'finds midnight in the zone, on the days the clocks change too', () => {
		const iso = ( day: string, minute: number, zone: string ) =>
			new Date(
				zonedInstant( day, minute, zone ) as number
			).toISOString();

		expect( iso( '2026-09-17', 0, 'America/Los_Angeles' ) ).toBe(
			'2026-09-17T07:00:00.000Z'
		);
		expect( iso( '2026-12-17', 0, 'America/Los_Angeles' ) ).toBe(
			'2026-12-17T08:00:00.000Z'
		);
		// Clocks go forward at 2 AM on March 8: midnight is still standard time.
		expect( iso( '2026-03-08', 0, 'America/New_York' ) ).toBe(
			'2026-03-08T05:00:00.000Z'
		);
		expect( iso( '2026-03-08', 1439, 'America/New_York' ) ).toBe(
			'2026-03-09T03:59:00.000Z'
		);
		// And back at 2 AM on November 1: midnight is still daylight time.
		expect( iso( '2026-11-01', 0, 'America/New_York' ) ).toBe(
			'2026-11-01T04:00:00.000Z'
		);
		expect( iso( '2026-11-01', 1439, 'America/New_York' ) ).toBe(
			'2026-11-02T04:59:00.000Z'
		);
		expect( iso( '2026-09-17', 0, '+05:30' ) ).toBe(
			'2026-09-16T18:30:00.000Z'
		);
		expect( zonedInstant( '2026-02-30', 0, 'UTC' ) ).toBeNull();
	} );

	it( 'names the zone for that date, in the reader’s language', () => {
		const zone = 'America/Los_Angeles';

		expect( edgeLabel( '2026-09-17', 'start', zone, 'en-US' ) ).toBe(
			'12:00 AM PDT'
		);
		expect( edgeLabel( '2026-12-17', 'start', zone, 'en-US' ) ).toBe(
			'12:00 AM PST'
		);
		expect( edgeLabel( '2026-12-17', 'end', zone, 'en-US' ) ).toBe(
			'11:59 PM PST'
		);
		expect( edgeLabel( '2026-09-17', 'start', zone, 'de-DE' ) ).toMatch(
			/GMT-7$/
		);
		expect(
			edgeLabel( '2026-12-17', 'start', 'Europe/Berlin', 'de-DE' )
		).toMatch( /MEZ$/ );
	} );

	it( 'gives way to the server’s text for a zone it cannot format', () => {
		expect(
			edgeLabel( '2026-09-17', 'start', 'Not/AZone', 'en-US' )
		).toBeNull();
	} );
} );

describe( 'initZoneNotes', () => {
	it( 'follows the field, falling back to the start for an empty end', () => {
		document.documentElement.lang = 'en-US';
		document.body.innerHTML = `
			<input type="date" id="s" value="2026-09-17">
			<input type="date" id="e" value="">
			<span id="n" data-aggr-zone-note data-aggr-edge="end" data-aggr-for="e" data-aggr-fallback="s" data-aggr-zone="America/Los_Angeles">server</span>`;

		initZoneNotes( document );

		const note = document.getElementById( 'n' ) as HTMLElement;
		const end = document.getElementById( 'e' ) as HTMLInputElement;

		expect( note.textContent ).toBe( '11:59 PM PDT' );

		end.value = '2026-12-01';
		end.dispatchEvent( new Event( 'change' ) );
		expect( note.textContent ).toBe( '11:59 PM PST' );
		expect( end.style.paddingInlineEnd ).toMatch( /^calc\(/ );
	} );

	it( 'keeps the server’s text when the zone cannot be formatted', () => {
		document.body.innerHTML = `
			<input type="date" id="s" value="2026-09-17">
			<span id="n" data-aggr-zone-note data-aggr-edge="start" data-aggr-for="s" data-aggr-zone="Not/AZone">12:00 AM PDT</span>`;

		initZoneNotes( document );

		expect( document.getElementById( 'n' )?.textContent ).toBe(
			'12:00 AM PDT'
		);
	} );
} );
