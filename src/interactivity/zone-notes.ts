/**
 * Date fields' time notes, true to the date in the field.
 *
 * Module: @aggr/zone-notes
 *
 * A zone's short name belongs to a date — Los Angeles is "PDT" in September
 * and "PST" in December — so the note beside a date field is restated whenever
 * the field changes, in the page's language (the WordPress user's locale) and
 * always on a 12-hour clock. The server's note, written for the saved date, is
 * what a page without script or with an unformattable zone keeps.
 *
 * Markup: an element with `data-aggr-zone-note`, `data-aggr-for` (the input's
 * id), optional `data-aggr-fallback` (an input read when that one is empty),
 * `data-aggr-edge` ("start" or "end") and `data-aggr-zone`
 * (`wp_timezone_string()`).
 */

/**
 * Midnight UTC of a `YYYY-MM-DD` day in milliseconds, or null for one that
 * is not a real date.
 *
 * @param day `YYYY-MM-DD`.
 */
function dayStart( day: string ): number | null {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( day );

	if ( ! match ) {
		return null;
	}

	const moment = new Date(
		Date.UTC(
			Number( match[ 1 ] ),
			Number( match[ 2 ] ) - 1,
			Number( match[ 3 ] )
		)
	);

	// `Date` rolls 2027-02-31 over to March rather than refusing it.
	return moment.toISOString().slice( 0, 10 ) === day
		? moment.getTime()
		: null;
}

/**
 * How far a zone's clock is ahead of UTC at an instant, in minutes.
 *
 * @param instant Milliseconds since the epoch.
 * @param zone    IANA identifier or offset, as `wp_timezone_string()` gives.
 */
function zoneOffset( instant: number, zone: string ): number {
	const parts = new Intl.DateTimeFormat( 'en-US', {
		timeZone: zone,
		hourCycle: 'h23',
		year: 'numeric',
		month: 'numeric',
		day: 'numeric',
		hour: 'numeric',
		minute: 'numeric',
		second: 'numeric',
	} ).formatToParts( new Date( instant ) );
	const read = ( type: string ): number =>
		Number( parts.find( ( part ) => part.type === type )?.value );
	const wall = Date.UTC(
		read( 'year' ),
		read( 'month' ) - 1,
		read( 'day' ),
		read( 'hour' ) % 24,
		read( 'minute' ),
		read( 'second' )
	);

	return Math.round( ( wall - instant ) / 60000 );
}

/**
 * The instant a wall-clock time happens on a day in a zone.
 *
 * Two passes, because the offset to subtract is the one in force at the
 * answer, not at the guess; one pass is an hour out on the day the clocks
 * change.
 *
 * @param day    `YYYY-MM-DD`.
 * @param minute Minutes after midnight.
 * @param zone   IANA identifier or offset.
 */
export function zonedInstant(
	day: string,
	minute: number,
	zone: string
): number | null {
	const moment = dayStart( day );

	if ( null === moment ) {
		return null;
	}

	const wall = moment + minute * 60000;
	const guess = wall - zoneOffset( wall, zone ) * 60000;

	return wall - zoneOffset( guess, zone ) * 60000;
}

/**
 * When a campaign day begins or ends, in the reader's language, with the
 * zone's name on that day: "12:00 AM PDT", "12:00 AM PST" in winter, "12:00
 * AM GMT-7" in German. A 12-hour clock whatever the language, as everywhere
 * the portal gives a time.
 *
 * @param day    `YYYY-MM-DD`.
 * @param edge   The day's start or its last minute.
 * @param zone   IANA identifier or offset.
 * @param locale BCP 47 tag, or empty for the browser's.
 * @return The label, or null where the browser cannot format that zone.
 */
export function edgeLabel(
	day: string,
	edge: 'start' | 'end',
	zone: string,
	locale: string
): string | null {
	try {
		const instant = zonedInstant( day, 'end' === edge ? 1439 : 0, zone );

		if ( null === instant ) {
			return null;
		}

		return new Intl.DateTimeFormat( locale || undefined, {
			hour: 'numeric',
			minute: '2-digit',
			hour12: true,
			timeZone: zone,
			timeZoneName: 'short',
		} ).format( new Date( instant ) );
	} catch {
		// An offset zone an older browser rejects: the server's text stays.
		return null;
	}
}

/**
 * Keeps each date field's time note true to the date in it.
 *
 * The server wrote the note in the site's language for the saved date; this
 * restates it in the reader's and follows the field, so moving a start from
 * October to November turns "PDT" into "PST". The input's end padding follows
 * the note's width, because "12:00 a. m. GMT-8" is longer than "12:00 AM PDT"
 * and a fixed padding put the note on top of the browser's calendar button.
 *
 * @param scope Where to look for notes.
 */
export function initZoneNotes( scope: ParentNode ): void {
	const locale =
		document.documentElement.lang || window.navigator.language || '';

	scope
		.querySelectorAll< HTMLElement >( '[data-aggr-zone-note]' )
		.forEach( ( note ) => {
			const input = document.getElementById( note.dataset.aggrFor ?? '' );
			const fallback = document.getElementById(
				note.dataset.aggrFallback ?? ''
			);
			const zone = note.dataset.aggrZone ?? '';

			if (
				! ( input instanceof HTMLInputElement ) ||
				'' === zone ||
				'1' === note.dataset.aggrZoneReady
			) {
				return;
			}

			note.dataset.aggrZoneReady = '1';

			const edge = 'end' === note.dataset.aggrEdge ? 'end' : 'start';
			const sources = [ input ];

			if ( fallback instanceof HTMLInputElement ) {
				sources.push( fallback );
			}

			const update = (): void => {
				const day = sources
					.map( ( source ) => source.value )
					.find( ( value ) => null !== dayStart( value ) );
				const label =
					undefined === day
						? null
						: edgeLabel( day, edge, zone, locale );

				if ( null !== label ) {
					note.textContent = label;
				}

				input.style.paddingInlineEnd = `calc(${ note.offsetWidth }px + 1.5rem)`;
			};

			sources.forEach( ( source ) => {
				source.addEventListener( 'input', update );
				source.addEventListener( 'change', update );
			} );
			update();
		} );
}

if ( typeof document !== 'undefined' ) {
	initZoneNotes( document );
}
