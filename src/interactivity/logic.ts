/**
 * Decidable portal logic. Imports nothing from @wordpress/interactivity
 * so Jest can exercise it without a runtime mock.
 *
 * Limits and copy are hydrated from PHP. This file only decides.
 */

export const DISPLAY_STEPS = [ 'details', 'creative', 'review' ] as const;

export type WizardStep = ( typeof DISPLAY_STEPS )[ number ];

export type FileCheckCode = 'type' | 'size' | 'pixels' | 'dimensions' | 'empty';

export type FileCheck = { ok: true } | { ok: false; code: FileCheckCode };

export function isWizardStep( value: string ): value is WizardStep {
	return ( DISPLAY_STEPS as readonly string[] ).includes( value );
}

export function stepIndex( step: string ): number {
	return DISPLAY_STEPS.indexOf( step as WizardStep );
}

export function nextStep( current: string ): WizardStep | null {
	const index = stepIndex( current );
	if ( index < 0 || index >= DISPLAY_STEPS.length - 1 ) {
		return null;
	}
	return DISPLAY_STEPS[ index + 1 ] ?? null;
}

export function previousStep( current: string ): WizardStep | null {
	const index = stepIndex( current );
	if ( index <= 0 ) {
		return null;
	}
	return DISPLAY_STEPS[ index - 1 ] ?? null;
}

/**
 * A `YYYY-MM-DD` date moved by whole days, as the same kind of string.
 *
 * Arithmetic on the date's own parts in UTC, so the visitor's timezone and its
 * daylight-saving changes cannot move the answer by a day. The server derives
 * the real end date; this only previews it beside the field.
 *
 * @param date `YYYY-MM-DD`.
 * @param days Whole days to add; negative moves back.
 * @return The moved date, or null when `date` is not a real calendar date.
 */
export function addDays( date: string, days: number ): string | null {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( date );
	if ( ! match || ! Number.isInteger( days ) ) {
		return null;
	}

	const year = Number( match[ 1 ] );
	const month = Number( match[ 2 ] ) - 1;
	const day = Number( match[ 3 ] );
	const moment = new Date( Date.UTC( year, month, day ) );

	// `Date` rolls 2027-02-31 over to March rather than refusing it.
	if (
		moment.getUTCFullYear() !== year ||
		moment.getUTCMonth() !== month ||
		moment.getUTCDate() !== day
	) {
		return null;
	}

	moment.setUTCDate( moment.getUTCDate() + days );

	return moment.toISOString().slice( 0, 10 );
}

/**
 * The last day of a fixed run. The start day is the first of its days, the
 * same count `Campaign_Rules::fixed_end_ts()` makes on the server.
 *
 * @param start        `YYYY-MM-DD` start date.
 * @param durationDays Days the package runs.
 * @return The last day, or null when either input is unusable.
 */
export function runEndDate(
	start: string,
	durationDays: number
): string | null {
	if ( ! Number.isInteger( durationDays ) || durationDays < 1 ) {
		return null;
	}

	return addDays( start, durationDays - 1 );
}

export function parsePixelSize(
	value: string
): { width: number; height: number } | null {
	const match = /^(\d+)\s*[x×]\s*(\d+)$/i.exec( value.trim() );
	if ( ! match ) {
		return null;
	}
	const width = Number( match[ 1 ] );
	const height = Number( match[ 2 ] );
	if ( ! Number.isInteger( width ) || ! Number.isInteger( height ) ) {
		return null;
	}
	if ( width < 1 || height < 1 ) {
		return null;
	}
	return { width, height };
}

export function checkCreativeFile( input: {
	mime: string;
	bytes: number;
	width: number;
	height: number;
	expectedWidth: number;
	expectedHeight: number;
	maxBytes: number;
	maxPixels: number;
	allowedMime: readonly string[];
} ): FileCheck {
	if ( input.bytes <= 0 ) {
		return { ok: false, code: 'empty' };
	}

	const mime = input.mime.toLowerCase().trim();
	if ( ! input.allowedMime.includes( mime ) ) {
		return { ok: false, code: 'type' };
	}

	if ( input.bytes > input.maxBytes ) {
		return { ok: false, code: 'size' };
	}

	if (
		input.width < 1 ||
		input.height < 1 ||
		input.width * input.height > input.maxPixels
	) {
		return { ok: false, code: 'pixels' };
	}

	if (
		input.width !== input.expectedWidth ||
		input.height !== input.expectedHeight
	) {
		return { ok: false, code: 'dimensions' };
	}

	return { ok: true };
}

/**
 * A destination link as it will be saved, or null when it cannot be one.
 *
 * People type `example.com/page`, which a URL input calls invalid and which
 * never left the browser: the field looked saved and was not. A missing scheme
 * becomes https. Empty stays empty, because empty clears the link.
 *
 * @param value What was typed.
 * @return The link to send, '' to clear it, or null when it is not a link.
 */
export function normaliseLink( value: string ): string | null {
	const trimmed = value.trim();

	if ( '' === trimmed ) {
		return '';
	}

	const candidate = /^[a-z][a-z0-9+.-]*:/i.test( trimmed )
		? trimmed
		: `https://${ trimmed }`;

	try {
		const url = new URL( candidate );

		if (
			( 'http:' !== url.protocol && 'https:' !== url.protocol ) ||
			'' === url.hostname ||
			'' !== url.username ||
			'' !== url.password
		) {
			return null;
		}
	} catch {
		return null;
	}

	return candidate;
}

export function debounce< T extends ( ...args: never[] ) => void >(
	fn: T,
	ms: number
): T & { cancel: () => void } {
	let timer: ReturnType< typeof setTimeout > | null = null;

	const wrapped = ( ( ...args: never[] ) => {
		if ( timer !== null ) {
			clearTimeout( timer );
		}
		timer = setTimeout( () => {
			timer = null;
			fn( ...args );
		}, ms );
	} ) as T & { cancel: () => void };

	wrapped.cancel = () => {
		if ( timer !== null ) {
			clearTimeout( timer );
			timer = null;
		}
	};

	return wrapped;
}
