/**
 * A range calendar that fills the date fields beside it.
 *
 * Module: @aggr/calendar
 *
 * **The date fields stay the truth.** They are what the form posts, what
 * autosave reads and what a browser without script uses. The calendar writes
 * into them and dispatches the same `input` and `change` events a person
 * typing would, so everything already listening — autosave, the "Runs
 * through" line — follows without knowing the calendar exists. A field edited
 * by hand redraws the calendar the other way.
 *
 * **One grid per month, one tab stop for all of them.** Days are buttons with
 * a roving tabindex, following the APG date grid: arrows move by day and
 * week, Home and End to the ends of the week, Page Up and Page Down by month
 * (with Shift, by year). Moving past the months on show scrolls the view with
 * the focus, so the keyboard can reach any month the mouse can. Days that
 * cannot be chosen stay focusable and say so, rather than being skipped
 * silently.
 *
 * **Dates are `YYYY-MM-DD` strings, and the arithmetic is UTC.** A calendar
 * day has no zone. Doing the sums on UTC parts means the visitor's own
 * daylight-saving change can never move a date by one; the server derives the
 * real timestamps in the site timezone.
 */

export type Awaiting = 'start' | 'end';

export interface Range {
	start: string;
	end: string;
}

export interface Rules {
	/** The earliest day that may be chosen at all. */
	min: string;
	/** Days a fixed package runs, or zero for a custom schedule. */
	fixedDays: number;
	/** A live campaign that has started: only its end may move. */
	startLocked: boolean;
}

export type Preset = 'today' | 'monday' | 'two-weeks' | 'month' | 'open';

const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

function utc( day: string ): Date | null {
	const match = DAY.exec( day );

	if ( ! match ) {
		return null;
	}

	const year = Number( match[ 1 ] );
	const month = Number( match[ 2 ] ) - 1;
	const date = Number( match[ 3 ] );
	const moment = new Date( Date.UTC( year, month, date ) );

	// `Date` rolls 2027-02-31 over to March rather than refusing it.
	if (
		moment.getUTCFullYear() !== year ||
		moment.getUTCMonth() !== month ||
		moment.getUTCDate() !== date
	) {
		return null;
	}

	return moment;
}

function format( moment: Date ): string {
	return moment.toISOString().slice( 0, 10 );
}

export function isDay( value: string ): boolean {
	return null !== utc( value );
}

export function shiftDays( day: string, days: number ): string | null {
	const moment = utc( day );

	if ( null === moment ) {
		return null;
	}

	moment.setUTCDate( moment.getUTCDate() + days );

	return format( moment );
}

/** The first of the month `day` falls in. */
export function monthOf( day: string ): string {
	return day.slice( 0, 8 ) + '01';
}

/**
 * The same day of the month, `months` away, held to the last day of a shorter
 * month: January 31 plus one month is February 28, not March 3.
 *
 * @param day    `YYYY-MM-DD`.
 * @param months Whole months; negative moves back.
 */
export function shiftMonths( day: string, months: number ): string | null {
	const moment = utc( day );

	if ( null === moment ) {
		return null;
	}

	const target = new Date(
		Date.UTC( moment.getUTCFullYear(), moment.getUTCMonth() + months, 1 )
	);
	const last = new Date(
		Date.UTC( target.getUTCFullYear(), target.getUTCMonth() + 1, 0 )
	).getUTCDate();

	target.setUTCDate( Math.min( moment.getUTCDate(), last ) );

	return format( target );
}

export function lastOfMonth( day: string ): string | null {
	const first = shiftMonths( monthOf( day ), 1 );

	return null === first ? null : shiftDays( first, -1 );
}

/** 0 for Sunday through 6 for Saturday. */
export function weekday( day: string ): number {
	return utc( day )?.getUTCDay() ?? 0;
}

/** Days from `from` to `to`, counting both. Zero when out of order. */
export function daysInRange( from: string, to: string ): number {
	const a = utc( from );
	const b = utc( to );

	if ( null === a || null === b || b < a ) {
		return 0;
	}

	return Math.round( ( b.getTime() - a.getTime() ) / 86400000 ) + 1;
}

/**
 * A month as rows of seven, blanks first so the 1st lands under its weekday.
 *
 * @param month     Any day in the month.
 * @param weekStart 0 for Sunday, 1 for Monday, as `start_of_week` stores it.
 */
export function monthWeeks( month: string, weekStart: number ): string[][] {
	const first = monthOf( month );
	const last = lastOfMonth( first );
	const weeks: string[][] = [];

	if ( null === last ) {
		return weeks;
	}

	let row: string[] = Array.from(
		{ length: ( weekday( first ) - weekStart + 7 ) % 7 },
		() => ''
	);

	for (
		let day: string | null = first;
		null !== day && day <= last;
		day = shiftDays( day, 1 )
	) {
		row.push( day );

		if ( 7 === row.length ) {
			weeks.push( row );
			row = [];
		}
	}

	if ( row.length > 0 ) {
		weeks.push( [ ...row, ...Array( 7 - row.length ).fill( '' ) ] );
	}

	return weeks;
}

/** The end the campaign will have: derived for a fixed package, else chosen. */
export function endOf( range: Range, rules: Rules ): string {
	if ( rules.fixedDays > 0 ) {
		return '' === range.start
			? ''
			: shiftDays( range.start, rules.fixedDays - 1 ) ?? '';
	}

	return range.end;
}

/**
 * Whether a day may be pressed next.
 *
 * `YYYY-MM-DD` strings order correctly as strings, so no dates are built.
 *
 * @param day      `YYYY-MM-DD`.
 * @param rules    What the schedule allows.
 * @param range    The dates held now.
 */
export function selectable( day: string, rules: Rules, range: Range ): boolean {
	if ( day < rules.min ) {
		return false;
	}

	return ! rules.startLocked || '' === range.start || day >= range.start;
}

/**
 * The dates after a day is pressed, and which end the next press sets.
 *
 * A fixed package only ever takes a start. A custom schedule takes a start
 * and then an end; pressing a day before the start while an end is awaited
 * moves the start instead, which is what somebody doing that means.
 *
 * @param range    The dates held now.
 * @param day      The day pressed.
 * @param awaiting Which end this press sets.
 * @param rules    What the schedule allows.
 */
export function pick(
	range: Range,
	day: string,
	awaiting: Awaiting,
	rules: Rules
): { range: Range; awaiting: Awaiting } {
	if ( ! selectable( day, rules, range ) ) {
		return { range, awaiting };
	}

	if ( rules.startLocked ) {
		return { range: { ...range, end: day }, awaiting: 'end' };
	}

	if ( rules.fixedDays > 0 ) {
		return { range: { start: day, end: '' }, awaiting: 'start' };
	}

	if ( 'end' === awaiting && '' !== range.start && day >= range.start ) {
		return { range: { ...range, end: day }, awaiting: 'start' };
	}

	return {
		range: {
			start: day,
			end: '' !== range.end && range.end >= day ? range.end : '',
		},
		awaiting: 'end',
	};
}

/**
 * Where a key sends the focus, or null for a key the grid does not use.
 *
 * @param day       The focused day.
 * @param key       `KeyboardEvent.key`.
 * @param shift     Whether Shift is held.
 * @param weekStart First column's weekday.
 */
export function keyTarget(
	day: string,
	key: string,
	shift: boolean,
	weekStart: number
): string | null {
	const column = ( weekday( day ) - weekStart + 7 ) % 7;

	switch ( key ) {
		case 'ArrowLeft':
			return shiftDays( day, -1 );
		case 'ArrowRight':
			return shiftDays( day, 1 );
		case 'ArrowUp':
			return shiftDays( day, -7 );
		case 'ArrowDown':
			return shiftDays( day, 7 );
		case 'Home':
			return shiftDays( day, -column );
		case 'End':
			return shiftDays( day, 6 - column );
		case 'PageUp':
			return shiftMonths( day, shift ? -12 : -1 );
		case 'PageDown':
			return shiftMonths( day, shift ? 12 : 1 );
		default:
			return null;
	}
}

/**
 * The dates a quick pick sets, or null where it does not apply.
 *
 * Starts are held to the earliest allowed day, so "Starts today" on a site
 * whose day has not begun yet still offers the first day that can be saved.
 *
 * @param preset Which pick.
 * @param today  The site's today.
 * @param range  The dates held now.
 * @param rules  What the schedule allows.
 */
export function applyPreset(
	preset: Preset,
	today: string,
	range: Range,
	rules: Rules
): Range | null {
	const earliest = today > rules.min ? today : rules.min;
	const fixed = rules.fixedDays > 0;
	const keepEnd = ( start: string ): string =>
		! fixed && '' !== range.end && range.end >= start ? range.end : '';

	if ( rules.startLocked && ( 'today' === preset || 'monday' === preset ) ) {
		return null;
	}

	if (
		fixed &&
		( 'two-weeks' === preset || 'month' === preset || 'open' === preset )
	) {
		return null;
	}

	switch ( preset ) {
		case 'today':
			return { start: earliest, end: keepEnd( earliest ) };
		case 'monday': {
			const ahead = ( 8 - weekday( today ) ) % 7 || 7;
			let start = shiftDays( today, ahead ) ?? earliest;

			while ( start < earliest ) {
				start = shiftDays( start, 7 ) ?? earliest;
			}

			return { start, end: keepEnd( start ) };
		}
		case 'two-weeks': {
			const start = '' !== range.start ? range.start : earliest;
			const end = shiftDays( start, 13 ) ?? '';

			return { start, end: end < earliest ? '' : end };
		}
		case 'month': {
			const start = '' !== range.start ? range.start : earliest;
			const end = lastOfMonth( start ) ?? '';

			return { start, end: end < earliest ? '' : end };
		}
		case 'open':
			return { ...range, end: '' };
	}

	return null;
}

/**
 * `%1$s`-style placeholders filled in order, the way the server's strings are.
 *
 * @param template Translated sentence.
 * @param values   Replacements, in placeholder order.
 */
function fill( template: string, values: string[] ): string {
	return template.replace(
		/%(?:(\d+)\$)?s/g,
		( match: string, position?: string ) =>
			values[ position ? Number( position ) - 1 : 0 ] ?? match
	);
}

interface Labels {
	start: string;
	end: string;
	range: string;
	fixed: string;
	open: string;
	unavailable: string;
	daysOne: string;
	daysOther: string;
}

/**
 * Wires one calendar. Returns a handle for tests, or null when the markup it
 * needs is missing, in which case the date fields carry on alone.
 *
 * @param root The element carrying `data-aggr-calendar`.
 */
export function initCalendar( root: HTMLElement ): {
	range: () => Range;
	view: () => string;
} | null {
	const data = root.dataset;
	const start = document.getElementById( data.aggrStart ?? '' );
	const end = document.getElementById( data.aggrEnd ?? '' );
	const grids = root.querySelector< HTMLElement >(
		'[data-aggr-calendar-grids]'
	);
	const status = root.querySelector< HTMLElement >(
		'[data-aggr-calendar-status]'
	);
	const span = root.querySelector< HTMLElement >(
		'[data-aggr-calendar-span]'
	);
	const previous = root.querySelector< HTMLButtonElement >(
		'[data-aggr-calendar-prev]'
	);
	const next = root.querySelector< HTMLButtonElement >(
		'[data-aggr-calendar-next]'
	);
	const hint = root.querySelector< HTMLElement >(
		'[data-aggr-calendar-hint]'
	);
	const today = data.aggrToday ?? '';

	if (
		! ( start instanceof HTMLInputElement ) ||
		! ( end instanceof HTMLInputElement ) ||
		! grids ||
		! status ||
		! previous ||
		! next ||
		! isDay( today ) ||
		'1' === data.aggrCalendarReady
	) {
		return null;
	}

	data.aggrCalendarReady = '1';

	const form = start.form;
	const weekStart = Math.min(
		6,
		Math.max( 0, Number( data.aggrWeekStart ) || 0 )
	);
	const wide = Math.max( 1, Number( data.aggrMonths ) || 2 );
	/*
	 * One month on a phone, where two stacked made a calendar taller than the
	 * screen. The query is the stylesheet's, which hides the picture's second
	 * month at the same width, so attaching changes nothing that is on show.
	 */
	const narrow =
		typeof window.matchMedia === 'function'
			? window.matchMedia( '(max-width: 40rem)' )
			: null;
	const shown = (): number => ( narrow?.matches ? 1 : wide );
	const locale = document.documentElement.lang || undefined;
	const labels: Labels = {
		start: data.aggrLabelStart ?? '',
		end: data.aggrLabelEnd ?? '',
		range: data.aggrLabelRange ?? '',
		fixed: data.aggrLabelFixed ?? '',
		open: data.aggrLabelOpen ?? '',
		unavailable: data.aggrLabelUnavailable ?? '',
		daysOne: data.aggrLabelDaysOne ?? '',
		daysOther: data.aggrLabelDaysOther ?? '',
	};
	const longDate = new Intl.DateTimeFormat( locale, {
		weekday: 'long',
		year: 'numeric',
		month: 'long',
		day: 'numeric',
		timeZone: 'UTC',
	} );
	const monthName = new Intl.DateTimeFormat( locale, {
		month: 'long',
		year: 'numeric',
		timeZone: 'UTC',
	} );
	const weekdayNames = ( style: 'narrow' | 'long' ) =>
		Array.from( { length: 7 }, ( _, index ) =>
			new Intl.DateTimeFormat( locale, {
				weekday: style,
				timeZone: 'UTC',
			} ).format(
				// 2026-01-04 is a Sunday.
				new Date(
					Date.UTC( 2026, 0, 4 + ( ( index + weekStart ) % 7 ) )
				)
			)
		);
	const initials = weekdayNames( 'narrow' );
	const long = weekdayNames( 'long' );
	const plural = new Intl.PluralRules( locale );
	const numbers = new Intl.NumberFormat( locale );
	const speak = ( day: string ): string => {
		const moment = utc( day );

		return null === moment ? day : longDate.format( moment );
	};

	const rules = (): Rules => {
		const chosen = form?.querySelector< HTMLInputElement >(
			'input[type="radio"][name="package_id"]:checked'
		);

		return {
			min: isDay( data.aggrMin ?? '' )
				? ( data.aggrMin as string )
				: today,
			fixedDays: Math.max(
				0,
				Number( chosen?.dataset.aggrDurationDays ) || 0
			),
			startLocked: '1' === data.aggrStartLocked,
		};
	};

	let range: Range = { start: start.value, end: end.value };
	let awaiting: Awaiting = rules().startLocked ? 'end' : 'start';
	let view = monthOf( isDay( range.start ) ? range.start : rules().min );
	let active = isDay( range.start ) ? range.start : rules().min;
	let writing = false;
	// The last day the end shading was drawn to; see `shade` below.
	let previewed = '';

	const lastShown = (): string => shiftMonths( view, shown() - 1 ) ?? view;

	const reveal = ( day: string ): void => {
		if ( day < view ) {
			view = monthOf( day );
		} else if ( monthOf( day ) > lastShown() ) {
			view = shiftMonths( monthOf( day ), 1 - shown() ) ?? view;
		}
	};

	const cell = ( day: string, current: Rules ): HTMLTableCellElement => {
		const td = document.createElement( 'td' );

		if ( '' === day ) {
			return td;
		}

		const last = endOf( range, current );
		const button = document.createElement( 'button' );
		const edge = day === range.start || day === last;
		const inside =
			'' !== range.start &&
			'' !== last &&
			day > range.start &&
			day < last;
		const allowed = selectable( day, current, range );
		let name = speak( day );

		button.type = 'button';
		button.className = 'aggr-calendar__day';
		button.dataset.aggrDay = day;
		button.textContent = numbers.format( Number( day.slice( 8 ) ) );
		button.tabIndex = day === active ? 0 : -1;

		if ( day === range.start ) {
			name += ', ' + labels.start;
		} else if ( day === last ) {
			name += ', ' + labels.end;
		}

		if ( ! allowed ) {
			name += ', ' + labels.unavailable;
			button.setAttribute( 'aria-disabled', 'true' );
		}

		button.setAttribute( 'aria-label', name );
		td.setAttribute( 'aria-selected', String( edge || inside ) );

		if ( day === today ) {
			button.setAttribute( 'aria-current', 'date' );
		}

		if ( edge ) {
			button.classList.add( 'aggr-calendar__day--edge' );
		} else if ( inside ) {
			td.classList.add( 'aggr-calendar__cell--in' );
		}

		// Half-shaded ends, so the chosen days join the strip between them.
		if ( '' !== last && last > range.start ) {
			if ( day === range.start ) {
				td.classList.add( 'aggr-calendar__cell--from' );
			} else if ( day === last ) {
				td.classList.add( 'aggr-calendar__cell--to' );
			}
		}

		td.append( button );

		return td;
	};

	const render = (): void => {
		const current = rules();
		const focused = root.contains( document.activeElement )
			? ( document.activeElement as HTMLElement ).dataset?.aggrDay
			: undefined;
		const months: HTMLElement[] = [];

		// The tab stop has to be a day on show, or the grid has none.
		if ( active < view || monthOf( active ) > lastShown() ) {
			active = view;
		}

		for ( let index = 0; index < shown(); index++ ) {
			const month = shiftMonths( view, index ) ?? view;
			const wrap = document.createElement( 'div' );
			const table = document.createElement( 'table' );
			const caption = document.createElement( 'caption' );
			const head = document.createElement( 'tr' );
			const body = document.createElement( 'tbody' );

			wrap.className = 'aggr-calendar__month';
			table.className = 'aggr-calendar__table';
			table.setAttribute( 'role', 'grid' );

			if ( hint?.id ) {
				table.setAttribute( 'aria-describedby', hint.id );
			}
			caption.className = 'aggr-calendar__name';
			caption.textContent = monthName.format( utc( month ) as Date );

			initials.forEach( ( initial, column ) => {
				const th = document.createElement( 'th' );
				const abbr = document.createElement( 'abbr' );

				th.scope = 'col';
				th.className = 'aggr-calendar__weekday';
				abbr.title = long[ column ] ?? '';
				abbr.textContent = initial;
				th.append( abbr );
				head.append( th );
			} );

			monthWeeks( month, weekStart ).forEach( ( week ) => {
				const row = document.createElement( 'tr' );

				week.forEach( ( day ) => row.append( cell( day, current ) ) );
				body.append( row );
			} );

			const thead = document.createElement( 'thead' );

			thead.append( head );
			table.append( caption, thead, body );
			wrap.append( table );
			months.push( wrap );
		}

		grids.replaceChildren( ...months );
		previewed = '';
		grids.removeAttribute( 'aria-hidden' );

		previous.disabled = view <= monthOf( current.min );
		next.disabled = false;

		root.querySelectorAll< HTMLButtonElement >(
			'[data-aggr-preset]'
		).forEach( ( button ) => {
			button.disabled = false;
			button.hidden =
				null ===
				applyPreset(
					button.dataset.aggrPreset as Preset,
					today,
					range,
					current
				);
		} );

		if ( span ) {
			const count = daysInRange( range.start, endOf( range, current ) );

			span.hidden = 0 === count;
			span.textContent = fill(
				'one' === plural.select( count )
					? labels.daysOne
					: labels.daysOther,
				[ numbers.format( count ) ]
			);
		}

		if ( undefined !== focused ) {
			grids
				.querySelector< HTMLElement >( `[data-aggr-day="${ active }"]` )
				?.focus();
		}
	};

	const announce = (): void => {
		const current = rules();
		const last = endOf( range, current );
		const count = numbers.format( daysInRange( range.start, last ) );

		if ( '' === range.start ) {
			status.textContent = '';
		} else if ( current.fixedDays > 0 ) {
			status.textContent = fill( labels.fixed, [
				speak( range.start ),
				speak( last ),
			] );
		} else if ( '' === last ) {
			status.textContent = fill( labels.open, [ speak( range.start ) ] );
		} else {
			status.textContent = fill( labels.range, [
				speak( range.start ),
				speak( last ),
				count,
			] );
		}
	};

	const write = ( input: HTMLInputElement, value: string ): void => {
		if ( input.value === value || input.disabled ) {
			return;
		}

		writing = true;
		input.value = value;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		writing = false;
	};

	const commit = ( chosen: Range ): void => {
		const current = rules();

		range = {
			start: chosen.start,
			end: current.fixedDays > 0 ? end.value : chosen.end,
		};

		if ( ! current.startLocked ) {
			write( start, range.start );
		}

		write( end, range.end );
		render();
		announce();
	};

	const move = ( months: number ): void => {
		view = shiftMonths( view, months ) ?? view;

		const floor = monthOf( rules().min );

		if ( view < floor ) {
			view = floor;
		}

		active = shiftMonths( active, months ) ?? view;
		render();
	};

	grids.addEventListener( 'click', ( event ) => {
		const button = ( event.target as HTMLElement ).closest< HTMLElement >(
			'[data-aggr-day]'
		);
		const day = button?.dataset.aggrDay ?? '';

		if (
			'' === day ||
			'true' === button?.getAttribute( 'aria-disabled' )
		) {
			return;
		}

		const result = pick( range, day, awaiting, rules() );

		awaiting = result.awaiting;
		active = day;
		commit( result.range );
	} );

	grids.addEventListener( 'keydown', ( event ) => {
		const day = ( event.target as HTMLElement ).dataset?.aggrDay ?? '';
		const target =
			'' === day
				? null
				: keyTarget( day, event.key, event.shiftKey, weekStart );

		if ( null === target ) {
			return;
		}

		event.preventDefault();
		active = target;
		reveal( target );
		render();
		grids
			.querySelector< HTMLElement >( `[data-aggr-day="${ target }"]` )
			?.focus();
	} );

	/*
	 * Shading the days an end would cover, before it is pressed.
	 *
	 * Only a day changes it. The gaps between rows and the blank cells are
	 * part of the grid too, and treating them as "nowhere" cleared the shading
	 * and drew it again every time the pointer crossed a row, which flickered.
	 * It clears when the pointer leaves the grids, and cells are only touched
	 * when the day under the pointer actually changes.
	 */
	const shade = ( until: string ): void => {
		if ( until === previewed ) {
			return;
		}

		previewed = until;
		grids
			.querySelectorAll< HTMLElement >( '[data-aggr-day]' )
			.forEach( ( button ) => {
				const at = button.dataset.aggrDay ?? '';

				button.parentElement?.classList.toggle(
					'aggr-calendar__cell--preview',
					'' !== until && at > range.start && at <= until
				);
				// The start's half-fill, so the shading joins it.
				button.parentElement?.classList.toggle(
					'aggr-calendar__cell--preview-from',
					'' !== until && at === range.start
				);
			} );
	};

	const preview = ( event: Event ): void => {
		const day = ( event.target as HTMLElement ).closest< HTMLElement >(
			'[data-aggr-day]'
		)?.dataset.aggrDay;

		if ( undefined === day ) {
			return;
		}

		const current = rules();

		shade(
			'end' === awaiting &&
				0 === current.fixedDays &&
				! current.startLocked &&
				'' !== range.start &&
				day > range.start
				? day
				: ''
		);
	};

	grids.addEventListener( 'pointerover', preview );
	grids.addEventListener( 'focusin', preview );
	grids.addEventListener( 'pointerleave', () => shade( '' ) );

	previous.addEventListener( 'click', () => move( -1 ) );
	next.addEventListener( 'click', () => move( 1 ) );

	// A horizontal swipe turns the month, as it does in every phone calendar.
	let touchX: number | null = null;
	let touchY = 0;

	grids.addEventListener( 'pointerdown', ( event ) => {
		touchX = 'touch' === event.pointerType ? event.clientX : null;
		touchY = event.clientY;
	} );

	grids.addEventListener( 'pointerup', ( event ) => {
		if ( null === touchX ) {
			return;
		}

		const dx = event.clientX - touchX;

		touchX = null;

		if (
			Math.abs( dx ) > 48 &&
			Math.abs( dx ) > Math.abs( event.clientY - touchY )
		) {
			move( dx < 0 ? 1 : -1 );
		}
	} );

	root.querySelectorAll< HTMLButtonElement >( '[data-aggr-preset]' ).forEach(
		( button ) => {
			button.addEventListener( 'click', () => {
				const chosen = applyPreset(
					button.dataset.aggrPreset as Preset,
					today,
					range,
					rules()
				);

				if ( null === chosen ) {
					return;
				}

				awaiting = 'start';
				active = chosen.start;
				reveal( chosen.start );
				commit( chosen );
			} );
		}
	);

	const follow = (): void => {
		if ( writing ) {
			return;
		}

		range = { start: start.value, end: end.value };

		if ( isDay( range.start ) ) {
			active = range.start;
			reveal( range.start );
		}

		render();
	};

	start.addEventListener( 'change', follow );
	end.addEventListener( 'change', follow );
	form
		?.querySelectorAll< HTMLInputElement >( 'input[name="package_id"]' )
		.forEach( ( radio ) =>
			radio.addEventListener( 'change', () => {
				render();
				announce();
			} )
		);

	narrow?.addEventListener( 'change', render );

	render();

	return { range: () => range, view: () => view };
}

if ( typeof document !== 'undefined' ) {
	document
		.querySelectorAll< HTMLElement >( '[data-aggr-calendar]' )
		.forEach( ( root ) => {
			initCalendar( root );
		} );
}
