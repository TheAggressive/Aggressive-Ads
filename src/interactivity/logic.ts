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
 * One placement a dropped file could go to.
 *
 * `open` is whether it is waiting for a file: active, and with no ad yet. A
 * placement that already has one is still listed, so a file of its size can
 * be told why it was not used rather than that nothing was that size.
 */
export interface SizeTarget {
	id: string;
	width: number;
	height: number;
	open: boolean;
}

/**
 * Where one dropped file goes.
 *
 * - `one`: exactly one open placement of its size; it goes there.
 * - `choose`: several are open; the advertiser picks, or picks all of them.
 * - `taken`: placements of its size exist, and each has an ad or a file.
 * - `none`: no placement is its size.
 */
export type FileMatch =
	| { kind: 'one'; target: string }
	| { kind: 'choose'; targets: string[] }
	| { kind: 'taken' }
	| { kind: 'none' };

/**
 * Matches dropped files to placements by their pixel dimensions.
 *
 * In order, and a placement a file has been matched to is not offered to the
 * next one: two 300×250 files on a package with two open 300×250 placements
 * would otherwise both be sent to the first. Files that have to ask claim
 * nothing yet, because what they will be given is not known until they are
 * answered — the caller re-runs this after each answer, passing the answers
 * as `claimed`.
 *
 * @param files   Each file's measured size.
 * @param targets The campaign's placements.
 * @param claimed Placements already spoken for.
 * @return One match per file, in the files' order.
 */
export function matchFilesToSizes(
	files: ReadonlyArray< { width: number; height: number } >,
	targets: readonly SizeTarget[],
	claimed: ReadonlySet< string > = new Set()
): FileMatch[] {
	const taken = new Set( claimed );

	return files.map( ( file ): FileMatch => {
		const sameSize = targets.filter(
			( target ) =>
				target.width === file.width && target.height === file.height
		);

		if ( sameSize.length === 0 ) {
			return { kind: 'none' };
		}

		const free = sameSize
			.filter( ( target ) => target.open && ! taken.has( target.id ) )
			.map( ( target ) => target.id );
		const [ only ] = free;

		if ( only === undefined ) {
			return { kind: 'taken' };
		}

		if ( free.length === 1 ) {
			taken.add( only );

			return { kind: 'one', target: only };
		}

		return { kind: 'choose', targets: free };
	} );
}

/**
 * The sizes still waiting for a file, each once, for telling someone what a
 * file that matched nothing should have been.
 *
 * @param targets The campaign's placements.
 * @return Sizes such as `728 × 90`, in placement order.
 */
export function openSizes( targets: readonly SizeTarget[] ): string[] {
	const sizes = targets
		.filter( ( target ) => target.open )
		.map( ( target ) => `${ target.width } × ${ target.height }` );

	return [ ...new Set( sizes ) ];
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
