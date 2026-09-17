/**
 * UTC moments on the page, restated in the viewer's own time zone.
 *
 * Module: @aggr/local-time
 *
 * Reports count days in UTC, and a day is a bucket of counts that cannot be
 * re-cut into somebody's local day without hourly data. What can be stated
 * exactly is when those days begin: midnight UTC is 5:00 PM the day before in
 * Los Angeles. The server writes the UTC sentence, which is true without
 * script; this rewrites it with the viewer's clock when that clock is not UTC.
 *
 * Markup: an element with `data-aggr-local` ("clock" or "moment"), an ISO
 * `data-aggr-datetime`, and a translated `data-aggr-local-format` holding `%s`.
 */

export type LocalKind = 'clock' | 'moment';

/**
 * A UTC instant as the viewer would say it.
 *
 * @param kind     "clock" for a time of day, "moment" for a date and time.
 * @param iso      The instant, e.g. 2026-09-17T00:00:00Z.
 * @param locale   BCP 47 language tag.
 * @param timeZone IANA zone; the browser's own when omitted.
 * @return The formatted value, or null when the instant does not parse.
 */
export function formatLocal(
	kind: LocalKind,
	iso: string,
	locale: string,
	timeZone?: string
): string | null {
	const instant = new Date( iso );

	if ( Number.isNaN( instant.getTime() ) ) {
		return null;
	}

	const options: Intl.DateTimeFormatOptions =
		'clock' === kind
			? { hour: 'numeric', minute: '2-digit', timeZone }
			: {
					month: 'short',
					day: 'numeric',
					hour: 'numeric',
					minute: '2-digit',
					timeZone,
			  };

	return new Intl.DateTimeFormat( locale || undefined, options ).format(
		instant
	);
}

/**
 * Whether a zone keeps UTC's clock at a given instant, where restating the
 * sentence would only repeat it.
 *
 * @param iso      The instant.
 * @param timeZone IANA zone; the browser's own when omitted.
 */
export function isUtcClock( iso: string, timeZone?: string ): boolean {
	const instant = new Date( iso );
	const parts = new Intl.DateTimeFormat( 'en-US', {
		hour: 'numeric',
		minute: 'numeric',
		hourCycle: 'h23',
		day: 'numeric',
		timeZone,
	} ).formatToParts( instant );
	const read = ( type: string ): number =>
		Number( parts.find( ( part ) => part.type === type )?.value );

	return (
		read( 'hour' ) === instant.getUTCHours() &&
		read( 'minute' ) === instant.getUTCMinutes() &&
		read( 'day' ) === instant.getUTCDate()
	);
}

/**
 * Rewrites every marked sentence under a root.
 *
 * @param root     Where to look.
 * @param timeZone IANA zone; the browser's own when omitted.
 */
export function localize( root: ParentNode, timeZone?: string ): void {
	const locale = document.documentElement.lang || window.navigator.language;

	root.querySelectorAll< HTMLElement >( '[data-aggr-local]' ).forEach(
		( element ) => {
			const kind = element.dataset.aggrLocal;
			const iso = element.dataset.aggrDatetime ?? '';
			const format = element.dataset.aggrLocalFormat ?? '';

			if (
				( 'clock' !== kind && 'moment' !== kind ) ||
				! format.includes( '%s' ) ||
				isUtcClock( iso, timeZone )
			) {
				return;
			}

			const value = formatLocal( kind, iso, locale, timeZone );

			if ( null === value ) {
				return;
			}

			// The UTC wording stays available on hover, for anyone checking.
			element.title = element.textContent?.trim() ?? '';
			element.textContent = format.replace( '%s', value );
		}
	);
}

if ( typeof document !== 'undefined' ) {
	localize( document );
}
