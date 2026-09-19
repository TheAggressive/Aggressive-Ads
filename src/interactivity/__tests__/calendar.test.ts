/**
 * @jest-environment jsdom
 */

import {
	applyPreset,
	daysInRange,
	endOf,
	initCalendar,
	keyTarget,
	lastOfMonth,
	monthWeeks,
	pick,
	shiftDays,
	shiftMonths,
	type Rules,
} from '../calendar';
import { followPlan } from '../shared/follow-plan';

const custom: Rules = { min: '2026-09-16', fixedDays: 0, startLocked: false };
const fixed: Rules = { ...custom, fixedDays: 30 };
const locked: Rules = { ...custom, startLocked: true };

describe( 'calendar date math', () => {
	/*
	 * Only UTC parts are read, so the runner's zone cannot matter. Jest fixes
	 * the zone before a test runs; this was checked by hand with
	 * `TZ=America/New_York`, whose clocks change on March 8 and November 1.
	 */
	it( 'steps over daylight-saving dates one day at a time', () => {
		expect( shiftDays( '2026-03-07', 1 ) ).toBe( '2026-03-08' );
		expect( shiftDays( '2026-03-08', 1 ) ).toBe( '2026-03-09' );
		expect( shiftDays( '2026-11-01', 1 ) ).toBe( '2026-11-02' );
		expect( shiftDays( '2026-11-02', -1 ) ).toBe( '2026-11-01' );
		expect( daysInRange( '2026-03-01', '2026-03-31' ) ).toBe( 31 );
		expect( daysInRange( '2026-10-25', '2026-11-07' ) ).toBe( 14 );
	} );

	it( 'holds a month move to the end of a shorter month', () => {
		expect( shiftMonths( '2027-01-31', 1 ) ).toBe( '2027-02-28' );
		expect( shiftMonths( '2028-01-31', 1 ) ).toBe( '2028-02-29' );
		expect( shiftMonths( '2026-12-15', 1 ) ).toBe( '2027-01-15' );
		expect( shiftMonths( '2026-01-15', -1 ) ).toBe( '2025-12-15' );
		expect( shiftMonths( '2026-02-30', 1 ) ).toBeNull();
		expect( lastOfMonth( '2026-02-10' ) ).toBe( '2026-02-28' );
	} );

	it( 'lays a month out under the site’s first weekday', () => {
		// September 1, 2026 is a Tuesday.
		const sunday = monthWeeks( '2026-09-20', 0 );
		const monday = monthWeeks( '2026-09-20', 1 );

		expect( sunday[ 0 ] ).toEqual( [
			'',
			'',
			'2026-09-01',
			'2026-09-02',
			'2026-09-03',
			'2026-09-04',
			'2026-09-05',
		] );
		expect( monday[ 0 ]?.[ 1 ] ).toBe( '2026-09-01' );
		expect( sunday.flat().filter( Boolean ) ).toHaveLength( 30 );
		sunday.forEach( ( week ) => expect( week ).toHaveLength( 7 ) );
	} );

	it( 'counts both ends, and nothing when out of order', () => {
		expect( daysInRange( '2026-09-18', '2026-09-18' ) ).toBe( 1 );
		expect( daysInRange( '2026-09-18', '2026-10-01' ) ).toBe( 14 );
		expect( daysInRange( '2026-10-01', '2026-09-18' ) ).toBe( 0 );
		expect( daysInRange( '', '2026-09-18' ) ).toBe( 0 );
	} );

	it( 'derives a fixed run’s end the way the server does', () => {
		expect( endOf( { start: '2026-09-18', end: '' }, fixed ) ).toBe(
			'2026-10-17'
		);
		expect( endOf( { start: '', end: '' }, fixed ) ).toBe( '' );
		expect(
			endOf( { start: '2026-09-18', end: '2026-09-20' }, custom )
		).toBe( '2026-09-20' );
	} );
} );

describe( 'choosing a range', () => {
	it( 'takes a start and then an end', () => {
		const first = pick(
			{ start: '', end: '' },
			'2026-09-20',
			'start',
			custom
		);

		expect( first ).toEqual( {
			range: { start: '2026-09-20', end: '' },
			awaiting: 'end',
		} );

		const second = pick(
			first.range,
			'2026-09-27',
			first.awaiting,
			custom
		);

		expect( second ).toEqual( {
			range: { start: '2026-09-20', end: '2026-09-27' },
			awaiting: 'start',
		} );
	} );

	it( 'moves the start when a day before it is pressed for the end', () => {
		expect(
			pick(
				{ start: '2026-09-20', end: '' },
				'2026-09-18',
				'end',
				custom
			).range
		).toEqual( {
			start: '2026-09-18',
			end: '',
		} );
	} );

	it( 'keeps an end still after a new start, and drops one before it', () => {
		const range = { start: '2026-09-20', end: '2026-09-30' };

		expect( pick( range, '2026-09-22', 'start', custom ).range.end ).toBe(
			'2026-09-30'
		);
		expect( pick( range, '2026-10-02', 'start', custom ).range.end ).toBe(
			''
		);
	} );

	it( 'refuses a day before the earliest allowed, changing nothing', () => {
		const range = { start: '2026-09-20', end: '' };

		expect( pick( range, '2026-09-15', 'start', custom ) ).toEqual( {
			range,
			awaiting: 'start',
		} );
	} );

	it( 'only takes a start for a fixed package', () => {
		expect(
			pick( { start: '2026-09-20', end: '' }, '2026-09-25', 'end', fixed )
		).toEqual( {
			range: { start: '2026-09-25', end: '' },
			awaiting: 'start',
		} );
	} );

	it( 'only moves the end once a live campaign has started', () => {
		const range = { start: '2026-09-01', end: '2026-09-30' };

		expect( pick( range, '2026-10-10', 'start', locked ).range ).toEqual( {
			start: '2026-09-01',
			end: '2026-10-10',
		} );
		expect( pick( range, '2026-09-10', 'start', locked ).range ).toEqual(
			range
		);
	} );
} );

describe( 'keys', () => {
	it( 'moves by day, week, month and year', () => {
		expect( keyTarget( '2026-09-30', 'ArrowRight', false, 0 ) ).toBe(
			'2026-10-01'
		);
		expect( keyTarget( '2026-09-01', 'ArrowUp', false, 0 ) ).toBe(
			'2026-08-25'
		);
		expect( keyTarget( '2026-09-16', 'PageDown', false, 0 ) ).toBe(
			'2026-10-16'
		);
		expect( keyTarget( '2026-09-16', 'PageUp', true, 0 ) ).toBe(
			'2025-09-16'
		);
		expect( keyTarget( '2026-09-16', 'Enter', false, 0 ) ).toBeNull();
	} );

	it( 'finds the ends of the week from the site’s first weekday', () => {
		// September 16, 2026 is a Wednesday.
		expect( keyTarget( '2026-09-16', 'Home', false, 0 ) ).toBe(
			'2026-09-13'
		);
		expect( keyTarget( '2026-09-16', 'End', false, 0 ) ).toBe(
			'2026-09-19'
		);
		expect( keyTarget( '2026-09-16', 'Home', false, 1 ) ).toBe(
			'2026-09-14'
		);
		expect( keyTarget( '2026-09-16', 'End', false, 1 ) ).toBe(
			'2026-09-20'
		);
	} );
} );

describe( 'quick picks', () => {
	const today = '2026-09-16';

	it( 'starts on the earliest allowed day, not before it', () => {
		expect(
			applyPreset( 'today', today, { start: '', end: '' }, custom )
		).toEqual( { start: today, end: '' } );
		expect(
			applyPreset(
				'today',
				today,
				{ start: '', end: '' },
				{ ...custom, min: '2026-09-18' }
			)?.start
		).toBe( '2026-09-18' );
	} );

	it( 'finds the next Monday, a week on when today is one', () => {
		expect(
			applyPreset( 'monday', today, { start: '', end: '' }, custom )
				?.start
		).toBe( '2026-09-21' );
		expect(
			applyPreset(
				'monday',
				'2026-09-21',
				{ start: '', end: '' },
				{ ...custom, min: '2026-09-21' }
			)?.start
		).toBe( '2026-09-28' );
	} );

	it( 'runs two weeks or to the month’s end from the chosen start', () => {
		const range = { start: '2026-09-20', end: '' };

		expect( applyPreset( 'two-weeks', today, range, custom ) ).toEqual( {
			start: '2026-09-20',
			end: '2026-10-03',
		} );
		expect( applyPreset( 'month', today, range, custom ) ).toEqual( {
			start: '2026-09-20',
			end: '2026-09-30',
		} );
	} );

	it( 'offers no end picks for a fixed package and no start picks once started', () => {
		const range = { start: '2026-09-20', end: '' };

		expect( applyPreset( 'two-weeks', today, range, fixed ) ).toBeNull();
		expect( applyPreset( 'month', today, range, fixed ) ).toBeNull();
		expect( applyPreset( 'today', today, range, locked ) ).toBeNull();
		expect( applyPreset( 'monday', today, range, locked ) ).toBeNull();
		expect( applyPreset( 'two-weeks', today, range, locked ) ).toEqual( {
			start: '2026-09-20',
			end: '2026-10-03',
		} );
	} );
} );

describe( 'initCalendar', () => {
	function mount(
		start = '',
		end = '',
		duration = '0',
		extra = ''
	): HTMLElement {
		document.body.innerHTML = `
			<form>
				<input type="radio" name="package_id" value="1" data-aggr-duration-days="${ duration }" checked>
				<input type="date" id="s" name="start_date" value="${ start }">
				<input type="date" id="e" name="end_date" value="${ end }" ${
					'0' === duration ? '' : 'disabled'
				}>
				<div data-aggr-calendar data-aggr-start="s" data-aggr-end="e" data-aggr-today="2026-09-16"
					data-aggr-min="2026-09-16" data-aggr-week-start="0" data-aggr-months="2" ${ extra }
					data-aggr-label-start="first day" data-aggr-label-end="last day" data-aggr-label-unavailable="not available"
					data-aggr-label-range="Runs from %1$s through %2$s." data-aggr-label-open="Starts %s."
					data-aggr-label-fixed="Starts %1$s and runs through %2$s."
					data-aggr-label-days-one="%s day selected" data-aggr-label-days-other="%s days selected">
					<button type="button" data-aggr-preset="two-weeks" disabled>2 weeks</button>
					<p data-aggr-calendar-span hidden></p>
					<button type="button" data-aggr-calendar-prev disabled>Previous</button>
					<button type="button" data-aggr-calendar-next disabled>Next</button>
					<p id="hint" data-aggr-calendar-hint>Arrow keys</p>
					<div data-aggr-calendar-grids aria-hidden="true"></div>
					<p data-aggr-calendar-status></p>
				</div>
			</form>`;

		return document.querySelector< HTMLElement >(
			'[data-aggr-calendar]'
		) as HTMLElement;
	}

	const day = ( date: string ) =>
		document.querySelector< HTMLButtonElement >(
			`[data-aggr-day="${ date }"]`
		) as HTMLButtonElement;
	const input = ( id: string ) =>
		document.getElementById( id ) as HTMLInputElement;

	it( 'draws two month grids with one tab stop, and enables its controls', () => {
		const root = mount( '2026-09-20', '2026-09-26' );

		initCalendar( root );

		expect( root.querySelectorAll( 'table[role="grid"]' ) ).toHaveLength(
			2
		);
		expect(
			root.querySelectorAll( '[data-aggr-day][tabindex="0"]' )
		).toHaveLength( 1 );
		expect( day( '2026-09-20' ).tabIndex ).toBe( 0 );
		expect(
			root
				.querySelector( '[data-aggr-calendar-grids]' )
				?.hasAttribute( 'aria-hidden' )
		).toBe( false );
		expect(
			root.querySelector< HTMLButtonElement >(
				'[data-aggr-calendar-next]'
			)?.disabled
		).toBe( false );
		// Nothing before the earliest month to go back to.
		expect(
			root.querySelector< HTMLButtonElement >(
				'[data-aggr-calendar-prev]'
			)?.disabled
		).toBe( true );
		expect( day( '2026-09-15' ).getAttribute( 'aria-disabled' ) ).toBe(
			'true'
		);
		expect(
			day( '2026-09-23' ).parentElement?.getAttribute( 'aria-selected' )
		).toBe( 'true' );
		expect(
			root.querySelector( '[data-aggr-calendar-span]' )?.textContent
		).toBe( '7 days selected' );
	} );

	it( 'writes a pressed range into the fields with the events autosave listens for', () => {
		const root = mount();
		const seen: string[] = [];

		initCalendar( root );
		root
			.closest( 'form' )
			?.addEventListener( 'change', ( event ) =>
				seen.push( ( event.target as HTMLInputElement ).name )
			);

		day( '2026-09-20' ).click();
		day( '2026-10-02' ).click();

		expect( input( 's' ).value ).toBe( '2026-09-20' );
		expect( input( 'e' ).value ).toBe( '2026-10-02' );
		expect( seen ).toEqual( [ 'start_date', 'end_date' ] );
		expect(
			root.querySelector( '[data-aggr-calendar-status]' )?.textContent
		).toMatch( /^Runs from .+ through .+\.$/ );
	} );

	it( 'ignores an unavailable day', () => {
		const root = mount();

		initCalendar( root );
		day( '2026-09-10' ).click();

		expect( input( 's' ).value ).toBe( '' );
	} );

	it( 'turns to later months with the button and follows the keyboard past the view', () => {
		const root = mount( '2026-09-20' );
		const handle = initCalendar( root );

		root
			.querySelector< HTMLButtonElement >( '[data-aggr-calendar-next]' )
			?.click();
		root
			.querySelector< HTMLButtonElement >( '[data-aggr-calendar-next]' )
			?.click();
		expect( handle?.view() ).toBe( '2026-11-01' );
		expect( root.querySelector( 'caption' )?.textContent ).toMatch(
			/November/
		);

		root
			.querySelector< HTMLButtonElement >( '[data-aggr-calendar-prev]' )
			?.click();
		root
			.querySelector< HTMLButtonElement >( '[data-aggr-calendar-prev]' )
			?.click();
		root
			.querySelector< HTMLButtonElement >( '[data-aggr-calendar-prev]' )
			?.click();
		expect( handle?.view() ).toBe( '2026-09-01' );

		day( '2026-09-20' ).focus();
		day( '2026-09-20' ).dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'PageDown', bubbles: true } )
		);
		day( '2026-10-20' ).dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'PageDown', bubbles: true } )
		);

		expect( handle?.view() ).toBe( '2026-10-01' );
		expect( document.activeElement ).toBe( day( '2026-11-20' ) );
		expect( day( '2026-11-20' ).tabIndex ).toBe( 0 );
	} );

	it( 'shows a fixed package’s derived end and never writes the end field', () => {
		const root = mount( '', '', '30' );

		initCalendar( root );
		day( '2026-09-20' ).click();

		expect( input( 's' ).value ).toBe( '2026-09-20' );
		expect( input( 'e' ).value ).toBe( '' );
		expect(
			day( '2026-10-19' ).classList.contains( 'aggr-calendar__day--edge' )
		).toBe( true );
		expect(
			root.querySelector< HTMLButtonElement >(
				'[data-aggr-preset="two-weeks"]'
			)?.hidden
		).toBe( true );
	} );

	it( 'applies a quick pick', () => {
		const root = mount( '2026-09-20' );

		initCalendar( root );
		root
			.querySelector< HTMLButtonElement >(
				'[data-aggr-preset="two-weeks"]'
			)
			?.click();

		expect( input( 'e' ).value ).toBe( '2026-10-03' );
	} );

	it( 'redraws when a field is edited by hand', () => {
		const root = mount( '2026-09-20' );
		const handle = initCalendar( root );

		input( 's' ).value = '2027-01-05';
		input( 's' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( handle?.range().start ).toBe( '2027-01-05' );
		expect( handle?.view() ).toBe( '2026-12-01' );
		expect(
			day( '2027-01-05' ).classList.contains( 'aggr-calendar__day--edge' )
		).toBe( true );
	} );

	it( 'leaves a started campaign’s start alone', () => {
		const root = mount(
			'2026-09-01',
			'2026-09-30',
			'0',
			'data-aggr-start-locked="1"'
		);

		initCalendar( root );
		day( '2026-10-05' ).click();

		expect( input( 's' ).value ).toBe( '2026-09-01' );
		expect( input( 'e' ).value ).toBe( '2026-10-05' );
	} );

	it( 'joins the chosen ends to the strip between them', () => {
		const root = mount( '2026-09-20', '2026-09-26' );

		initCalendar( root );

		expect( day( '2026-09-20' ).parentElement?.className ).toBe(
			'aggr-calendar__cell--from'
		);
		expect( day( '2026-09-26' ).parentElement?.className ).toBe(
			'aggr-calendar__cell--to'
		);
	} );

	it( 'shows one month on a narrow screen and still reaches the next', () => {
		const original = window.matchMedia;

		window.matchMedia = ( ( query: string ) => ( {
			matches: true,
			media: query,
			addEventListener: () => undefined,
		} ) ) as unknown as typeof window.matchMedia;

		try {
			const root = mount( '2026-09-20' );
			const handle = initCalendar( root );

			expect(
				root.querySelectorAll( 'table[role="grid"]' )
			).toHaveLength( 1 );

			day( '2026-09-30' ).focus();
			day( '2026-09-30' ).dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'ArrowRight',
					bubbles: true,
				} )
			);

			expect( handle?.view() ).toBe( '2026-10-01' );
			expect( document.activeElement ).toBe( day( '2026-10-01' ) );
		} finally {
			window.matchMedia = original;
		}
	} );

	it( 'keeps the end shading steady while the pointer crosses the gaps', () => {
		const root = mount();

		initCalendar( root );
		day( '2026-09-20' ).click();

		const over = ( target: Element ) =>
			target.dispatchEvent(
				new Event( 'pointerover', { bubbles: true } )
			);
		const shaded = () =>
			root.querySelectorAll( '.aggr-calendar__cell--preview' ).length;

		over( day( '2026-09-24' ) );
		expect( shaded() ).toBe( 4 );
		expect( day( '2026-09-20' ).parentElement?.classList ).toContain(
			'aggr-calendar__cell--preview-from'
		);

		// A row, a blank cell: between days, not away from them.
		over( day( '2026-09-24' ).closest( 'tr' ) as Element );
		over( root.querySelector( 'td:empty' ) as Element );
		expect( shaded() ).toBe( 4 );

		root
			.querySelector( '[data-aggr-calendar-grids]' )
			?.dispatchEvent( new Event( 'pointerleave' ) );
		expect( shaded() ).toBe( 0 );
	} );

	it( 'attaches once', () => {
		const root = mount();

		expect( initCalendar( root ) ).not.toBeNull();
		expect( initCalendar( root ) ).toBeNull();
	} );
} );

describe( 'the schedule follows the package', () => {
	function mount(): HTMLFormElement {
		document.documentElement.lang = 'en-US';
		document.body.innerHTML = `
			<form>
				<input type="radio" name="package_id" value="1" data-aggr-duration-days="0" checked>
				<input type="radio" name="package_id" value="2" data-aggr-duration-days="14">
				<input name="start_date" value="2026-10-01">
				<div data-aggr-end-field><input name="end_date" value="2026-10-31" required></div>
				<p data-aggr-run-through data-aggr-template="Runs through %s." hidden></p>
			</form>`;

		return document.querySelector( 'form' ) as HTMLFormElement;
	}

	const end = () =>
		document.querySelector< HTMLInputElement >(
			'input[name="end_date"]'
		) as HTMLInputElement;
	const through = () =>
		document.querySelector< HTMLElement >(
			'[data-aggr-run-through]'
		) as HTMLElement;
	const choose = ( value: string ) => {
		const radio = document.querySelector< HTMLInputElement >(
			`input[value="${ value }"]`
		) as HTMLInputElement;

		radio.checked = true;
		radio.dispatchEvent( new Event( 'change' ) );
	};

	it( 'derives the end of a fixed package, and hands a custom one back its field', () => {
		followPlan( mount() );

		choose( '2' );
		expect( end().disabled ).toBe( true );
		expect( end().required ).toBe( false );
		expect( through().hidden ).toBe( false );
		// The start counts as the first of the fourteen days.
		expect( through().textContent ).toBe(
			'Runs through October 14, 2026.'
		);

		choose( '1' );
		expect( end().disabled ).toBe( false );
		expect( end().required ).toBe( true );
		expect( through().hidden ).toBe( true );
	} );

	it( 'changes nothing until something is chosen, and attaches once', () => {
		const form = mount();

		followPlan( form );
		followPlan( form );
		expect( end().disabled ).toBe( false );
		expect( through().hidden ).toBe( true );

		const start = form.querySelector< HTMLInputElement >(
			'input[name="start_date"]'
		) as HTMLInputElement;

		choose( '2' );
		start.value = '2026-11-10';
		start.dispatchEvent( new Event( 'input' ) );
		expect( through().textContent ).toBe(
			'Runs through November 23, 2026.'
		);
	} );
} );
