/**
 * Turns a delivery policy into fields, and back into the object the engine stores.
 *
 * The three blobs stay the shapes Targeting_Rules, Frequency_Rules and
 * Schedule_Rules already validate. A policy those fields cannot show — a nested
 * OR, a custom frequency window, a second daypart — stays as text, so saving
 * the form cannot flatten a rule it did not understand.
 */

export type FrequencyWindow = 'session' | 'hour' | 'day';
export type FrequencyLevel = 'campaign' | 'line_item' | 'creative';

export type FrequencyDraft = {
	enabled: boolean;
	max: string;
	window: FrequencyWindow;
	level: FrequencyLevel;
};

export type ScheduleDraft = {
	limited: boolean;
	days: number[];
	start: string;
	end: string;
	timezone: string;
};

export type Dimension = 'post_type' | 'categories' | 'terms' | 'size';
export type TargetingOperator = 'eq' | 'neq' | 'contains' | 'not_contains';

export type TargetingRow = {
	dimension: Dimension;
	operator: TargetingOperator;
	value: string;
};

export type FieldView< T > =
	| { mode: 'form'; value: T }
	| { mode: 'raw'; text: string };

export type Compiled =
	| { ok: true; value: Record< string, unknown > }
	| { ok: false; error: string };

const FREQUENCY_KEYS = [ 'enabled', 'max_impressions', 'window', 'level' ];
const WINDOWS: FrequencyWindow[] = [ 'session', 'hour', 'day' ];
const LEVELS: FrequencyLevel[] = [ 'campaign', 'line_item', 'creative' ];
const DIMENSIONS: Dimension[] = [ 'post_type', 'categories', 'terms', 'size' ];
const OPERATORS: TargetingOperator[] = [
	'eq',
	'neq',
	'contains',
	'not_contains',
];

/** Renders a stored object as the text a raw box edits. */
export function asText( value: Record< string, unknown > ): string {
	if ( Object.keys( value ).length === 0 ) {
		return '';
	}

	return JSON.stringify( value, null, 2 );
}

/**
 * Parses one raw box.
 *
 * An empty box is "no policy". An array is refused: every blob the engine
 * accepts is an object.
 */
export function parseObject( raw: string ): Compiled {
	const trimmed = raw.trim();

	if ( '' === trimmed ) {
		return { ok: true, value: {} };
	}

	try {
		const parsed: unknown = JSON.parse( trimmed );

		if ( ! isRecord( parsed ) ) {
			return { ok: false, error: 'deliveryPolicyNotJson' };
		}

		return { ok: true, value: parsed };
	} catch {
		return { ok: false, error: 'deliveryPolicyNotJson' };
	}
}

/** Frequency policy as fields, or the original text when the form would drop a key. */
export function readFrequency(
	policy: Record< string, unknown >
): FieldView< FrequencyDraft > {
	if ( ! onlyKeys( policy, FREQUENCY_KEYS ) ) {
		return { mode: 'raw', text: asText( policy ) };
	}

	const window = policy.window ?? 'day';
	const level = policy.level ?? 'line_item';

	if ( ! isWindow( window ) || ! isLevel( level ) ) {
		return { mode: 'raw', text: asText( policy ) };
	}

	const max = policy.max_impressions;

	if ( undefined !== max && 'number' !== typeof max ) {
		return { mode: 'raw', text: asText( policy ) };
	}

	return {
		mode: 'form',
		value: {
			enabled: true === policy.enabled || 1 === policy.enabled,
			max: 'number' === typeof max ? String( max ) : '',
			window,
			level,
		},
	};
}

/** Fields back into the frequency object. An unchecked box stores no cap. */
export function compileFrequency( draft: FrequencyDraft ): Compiled {
	if ( ! draft.enabled ) {
		return { ok: true, value: {} };
	}

	if ( ! /^[1-9]\d*$/.test( draft.max.trim() ) ) {
		return { ok: false, error: 'frequencyMaxInvalid' };
	}

	return {
		ok: true,
		value: {
			enabled: true,
			max_impressions: Number( draft.max.trim() ),
			window: draft.window,
			level: draft.level,
		},
	};
}

/** One daypart and a timezone as fields. A second window stays text. */
export function readSchedule(
	settings: Record< string, unknown >
): FieldView< ScheduleDraft > {
	if ( ! onlyKeys( settings, [ 'dayparts', 'timezone' ] ) ) {
		return { mode: 'raw', text: asText( settings ) };
	}

	const timezone = settings.timezone ?? '';

	if ( 'string' !== typeof timezone ) {
		return { mode: 'raw', text: asText( settings ) };
	}

	if ( undefined === settings.dayparts ) {
		return {
			mode: 'form',
			value: {
				limited: '' !== timezone,
				days: [],
				start: '',
				end: '',
				timezone,
			},
		};
	}

	if (
		! Array.isArray( settings.dayparts ) ||
		1 !== settings.dayparts.length
	) {
		return { mode: 'raw', text: asText( settings ) };
	}

	const rule: unknown = settings.dayparts[ 0 ];

	if (
		! isRecord( rule ) ||
		! onlyKeys( rule, [ 'days', 'start_minute', 'end_minute' ] )
	) {
		return { mode: 'raw', text: asText( settings ) };
	}

	const days = readDays( rule.days );

	if ( null === days ) {
		return { mode: 'raw', text: asText( settings ) };
	}

	const start = readMinute( rule.start_minute, false );
	const end = readMinute( rule.end_minute, true );

	if ( null === start || null === end ) {
		return { mode: 'raw', text: asText( settings ) };
	}

	return {
		mode: 'form',
		value: {
			limited: true,
			days,
			start,
			end,
			timezone,
		},
	};
}

/** Fields back into delivery settings. An unchecked box stores no schedule. */
export function compileSchedule( draft: ScheduleDraft ): Compiled {
	if ( ! draft.limited ) {
		return { ok: true, value: {} };
	}

	const start = '' === draft.start ? null : toMinutes( draft.start );
	const end = '' === draft.end ? null : toMinutes( draft.end );

	if (
		( '' !== draft.start && null === start ) ||
		( '' !== draft.end && null === end )
	) {
		return { ok: false, error: 'hoursInvalid' };
	}

	if ( null !== start && null !== end && start === end ) {
		return { ok: false, error: 'hoursSame' };
	}

	const rule: Record< string, unknown > = {};

	if ( draft.days.length > 0 ) {
		rule.days = [ ...draft.days ].sort( ( a, b ) => a - b );
	}

	if ( null !== start ) {
		rule.start_minute = start;
	}

	if ( null !== end ) {
		rule.end_minute = end;
	}

	const value: Record< string, unknown > = {};

	if ( Object.keys( rule ).length > 0 ) {
		value.dayparts = [ rule ];
	}

	const timezone = draft.timezone.trim();

	if ( '' !== timezone ) {
		value.timezone = timezone;
	}

	return { ok: true, value };
}

/**
 * A flat "all of these" list as fields.
 *
 * One leaf, or an AND group of leaves, on the facts a page actually reports.
 * OR, NOT, nesting and any other dimension stay as text.
 */
export function readTargeting(
	tree: Record< string, unknown >
): FieldView< TargetingRow[] > {
	if ( Object.keys( tree ).length === 0 ) {
		return { mode: 'form', value: [] };
	}

	if ( 'dimension' in tree ) {
		const row = readLeaf( tree );

		return null === row
			? { mode: 'raw', text: asText( tree ) }
			: { mode: 'form', value: [ row ] };
	}

	if ( ! onlyKeys( tree, [ 'operator', 'rules' ] ) ) {
		return { mode: 'raw', text: asText( tree ) };
	}

	const operator = String( tree.operator ?? 'AND' ).toUpperCase();

	if ( 'AND' !== operator || ! Array.isArray( tree.rules ) ) {
		return { mode: 'raw', text: asText( tree ) };
	}

	const rows: TargetingRow[] = [];

	for ( const child of tree.rules ) {
		if ( ! isRecord( child ) ) {
			return { mode: 'raw', text: asText( tree ) };
		}

		const row = readLeaf( child );

		if ( null === row ) {
			return { mode: 'raw', text: asText( tree ) };
		}

		rows.push( row );
	}

	return { mode: 'form', value: rows };
}

/** Rows back into a targeting tree. No rows stores no targeting. */
export function compileTargeting( rows: TargetingRow[] ): Compiled {
	const leaves: Record< string, unknown >[] = [];

	for ( const row of rows ) {
		if ( '' === row.value.trim() ) {
			return { ok: false, error: 'targetingValueMissing' };
		}

		leaves.push( {
			dimension: row.dimension,
			operator: row.operator,
			value: row.value.trim(),
		} );
	}

	if ( 0 === leaves.length ) {
		return { ok: true, value: {} };
	}

	const only = leaves[ 0 ];

	if ( 1 === leaves.length && only ) {
		return { ok: true, value: only };
	}

	return { ok: true, value: { operator: 'AND', rules: leaves } };
}

/** Positive match, whichever operator the stored leaf used. */
export function isPositive( operator: TargetingOperator ): boolean {
	return 'eq' === operator || 'contains' === operator;
}

/** The operator a match choice should store for this fact. */
export function operatorFor(
	dimension: Dimension,
	positive: boolean
): TargetingOperator {
	const list = 'categories' === dimension || 'terms' === dimension;

	if ( list ) {
		return positive ? 'contains' : 'not_contains';
	}

	return positive ? 'eq' : 'neq';
}

/**
 * Switching to a page type or a size cannot keep a list operator.
 *
 * `contains` on a category means "this slug is one of the page's categories".
 * On a page type it means "the type contains this text", which is a different rule.
 */
export function operatorForDimension(
	dimension: Dimension,
	operator: TargetingOperator
): TargetingOperator {
	const scalar = 'post_type' === dimension || 'size' === dimension;

	if ( ! scalar ) {
		return operator;
	}

	if ( 'contains' === operator ) {
		return 'eq';
	}

	if ( 'not_contains' === operator ) {
		return 'neq';
	}

	return operator;
}

function readLeaf( node: Record< string, unknown > ): TargetingRow | null {
	if ( ! onlyKeys( node, [ 'dimension', 'operator', 'value' ] ) ) {
		return null;
	}

	const dimension = node.dimension;
	const operator = node.operator ?? 'eq';

	if (
		! isDimension( dimension ) ||
		! isOperator( operator ) ||
		'string' !== typeof node.value
	) {
		return null;
	}

	const scalar = 'post_type' === dimension || 'size' === dimension;

	if (
		scalar &&
		( 'contains' === operator || 'not_contains' === operator )
	) {
		return null;
	}

	return { dimension, operator, value: node.value };
}

function readDays( value: unknown ): number[] | null {
	if ( undefined === value ) {
		return [];
	}

	if ( ! Array.isArray( value ) ) {
		return null;
	}

	const days = new Set< number >();

	for ( const day of value ) {
		if ( 'number' !== typeof day || ! Number.isInteger( day ) ) {
			return null;
		}

		// Sunday is 0 in one calendar and 7 in the other. The checkbox is 7.
		const iso = 0 === day ? 7 : day;

		if ( iso < 1 || iso > 7 ) {
			return null;
		}

		days.add( iso );
	}

	return [ ...days ].sort( ( a, b ) => a - b );
}

/**
 * A minute of the day as HH:MM.
 *
 * 1440 is "through the end of the day", which an empty end field already means,
 * so it comes back blank rather than as 24:00, which a time input cannot hold.
 */
function readMinute( value: unknown, end: boolean ): string | null {
	if ( undefined === value ) {
		return '';
	}

	if ( 'number' !== typeof value || ! Number.isInteger( value ) ) {
		return null;
	}

	if ( end && 1440 === value ) {
		return '';
	}

	if ( value < 0 || value > 1439 ) {
		return null;
	}

	const hour = Math.floor( value / 60 );
	const minute = value % 60;

	return `${ String( hour ).padStart( 2, '0' ) }:${ String( minute ).padStart(
		2,
		'0'
	) }`;
}

function toMinutes( value: string ): number | null {
	const match = /^(\d{2}):(\d{2})$/.exec( value );

	if ( ! match ) {
		return null;
	}

	const hour = Number( match[ 1 ] );
	const minute = Number( match[ 2 ] );

	if ( hour > 23 || minute > 59 ) {
		return null;
	}

	return hour * 60 + minute;
}

function onlyKeys(
	value: Record< string, unknown >,
	allowed: string[]
): boolean {
	return Object.keys( value ).every( ( key ) => allowed.includes( key ) );
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return !! value && 'object' === typeof value && ! Array.isArray( value );
}

function isWindow( value: unknown ): value is FrequencyWindow {
	return WINDOWS.includes( value as FrequencyWindow );
}

function isLevel( value: unknown ): value is FrequencyLevel {
	return LEVELS.includes( value as FrequencyLevel );
}

function isDimension( value: unknown ): value is Dimension {
	return DIMENSIONS.includes( value as Dimension );
}

function isOperator( value: unknown ): value is TargetingOperator {
	return OPERATORS.includes( value as TargetingOperator );
}
