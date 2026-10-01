import {
	compileFrequency,
	compileSchedule,
	compileTargeting,
	operatorForDimension,
	readFrequency,
	readSchedule,
	readTargeting,
	policyFacts,
} from '../policy';

describe( 'delivery policy fields', () => {
	it( 'shows an empty policy as no limits', () => {
		expect( readFrequency( {} ) ).toMatchObject( {
			mode: 'form',
			value: { enabled: false },
		} );
		expect( readSchedule( {} ) ).toMatchObject( {
			mode: 'form',
			value: { limited: false },
		} );
		expect( readTargeting( {} ) ).toEqual( { mode: 'form', value: [] } );
	} );

	it( 'round-trips a frequency cap', () => {
		const read = readFrequency( {
			enabled: true,
			max_impressions: 3,
			window: 'day',
			level: 'campaign',
		} );

		expect( read.mode ).toBe( 'form' );

		if ( 'form' !== read.mode ) {
			return;
		}

		expect( compileFrequency( read.value ) ).toEqual( {
			ok: true,
			value: {
				enabled: true,
				max_impressions: 3,
				window: 'day',
				level: 'campaign',
			},
		} );
	} );

	it( 'stores nothing when the frequency box is unchecked', () => {
		expect(
			compileFrequency( {
				enabled: false,
				max: '3',
				window: 'day',
				level: 'campaign',
			} )
		).toEqual( { ok: true, value: {} } );
	} );

	it( 'refuses a cap with no count', () => {
		expect(
			compileFrequency( {
				enabled: true,
				max: '',
				window: 'hour',
				level: 'line_item',
			} ).ok
		).toBe( false );
	} );

	it( 'leaves a custom frequency window as text', () => {
		const policy = {
			enabled: true,
			max_impressions: 1,
			window: 'custom',
			window_seconds: 90,
			level: 'creative',
		};

		expect( readFrequency( policy ).mode ).toBe( 'raw' );
	} );

	it( 'round-trips weekday hours and keeps an untouched category match', () => {
		const schedule = readSchedule( {
			timezone: 'America/Los_Angeles',
			dayparts: [
				{
					days: [ 1, 2, 3, 4, 5 ],
					start_minute: 540,
					end_minute: 1020,
				},
			],
		} );

		expect( schedule ).toMatchObject( {
			mode: 'form',
			value: {
				limited: true,
				days: [ 1, 2, 3, 4, 5 ],
				start: '09:00',
				end: '17:00',
				timezone: 'America/Los_Angeles',
			},
		} );

		if ( 'form' !== schedule.mode ) {
			return;
		}

		expect( compileSchedule( schedule.value ) ).toEqual( {
			ok: true,
			value: {
				timezone: 'America/Los_Angeles',
				dayparts: [
					{
						days: [ 1, 2, 3, 4, 5 ],
						start_minute: 540,
						end_minute: 1020,
					},
				],
			},
		} );
	} );

	it( 'treats Sunday stored as 0 and an end of 1440 as the checkbox and a blank end', () => {
		const schedule = readSchedule( {
			dayparts: [ { days: [ 0 ], end_minute: 1440 } ],
		} );

		expect( schedule ).toMatchObject( {
			mode: 'form',
			value: { days: [ 7 ], end: '' },
		} );
	} );

	it( 'leaves a second daypart as text', () => {
		expect(
			readSchedule( {
				dayparts: [ { start_minute: 0 }, { start_minute: 720 } ],
			} ).mode
		).toBe( 'raw' );
	} );

	it( 'refuses a start and end that are the same minute', () => {
		expect(
			compileSchedule( {
				limited: true,
				days: [ 1 ],
				start: '09:00',
				end: '09:00',
				timezone: '',
			} )
		).toMatchObject( { ok: false, error: 'hoursSame' } );
	} );

	it( 'round-trips one page-type rule without wrapping it in a group', () => {
		const read = readTargeting( {
			dimension: 'post_type',
			operator: 'eq',
			value: 'post',
		} );

		expect( read ).toEqual( {
			mode: 'form',
			value: [
				{ dimension: 'post_type', operator: 'eq', value: 'post' },
			],
		} );

		if ( 'form' !== read.mode ) {
			return;
		}

		expect( compileTargeting( read.value ) ).toEqual( {
			ok: true,
			value: {
				dimension: 'post_type',
				operator: 'eq',
				value: 'post',
			},
		} );
	} );

	it( 'keeps an equality match on a category instead of rewriting it', () => {
		const read = readTargeting( {
			operator: 'AND',
			rules: [
				{
					dimension: 'categories',
					operator: 'eq',
					value: 'news',
				},
			],
		} );

		expect( read ).toMatchObject( {
			mode: 'form',
			value: [
				{ dimension: 'categories', operator: 'eq', value: 'news' },
			],
		} );
	} );

	it( 'leaves an OR group as text', () => {
		expect(
			readTargeting( {
				operator: 'OR',
				rules: [
					{
						dimension: 'post_type',
						operator: 'eq',
						value: 'post',
					},
				],
			} ).mode
		).toBe( 'raw' );
	} );

	it( 'changes a list operator when the fact becomes a page type', () => {
		expect( operatorForDimension( 'post_type', 'contains' ) ).toBe( 'eq' );
		expect( operatorForDimension( 'categories', 'contains' ) ).toBe(
			'contains'
		);
	} );
} );

describe( 'policyFacts', () => {
	const base = {
		priority: 100,
		pacing_mode: 'even',
		daily_cap: 0,
		lifetime_cap: 0,
		frequency_policy: {},
		delivery_settings: {},
		targeting_rules: {},
	};

	it( 'reads an untouched policy as no limits and everyone', () => {
		expect( policyFacts( base ) ).toEqual( {
			priority: 100,
			pacing: 'even',
			dailyCap: 0,
			lifetimeCap: 0,
			frequency: { kind: 'none' },
			hours: 'any',
			targeting: { kind: 'everyone' },
		} );
	} );

	it( 'reads the shapes the fields write', () => {
		const facts = policyFacts( {
			...base,
			pacing_mode: 'asap',
			frequency_policy: {
				enabled: true,
				max_impressions: 3,
				window: 'day',
				level: 'line_item',
			},
			delivery_settings: {
				dayparts: [ { days: [ 1, 2 ], start_minute: 540 } ],
			},
			targeting_rules: {
				operator: 'AND',
				rules: [
					{ dimension: 'post_type', operator: 'eq', value: 'post' },
					{ dimension: 'size', operator: 'eq', value: '300x250' },
				],
			},
		} );

		expect( facts.pacing ).toBe( 'asap' );
		expect( facts.frequency ).toEqual( {
			kind: 'limit',
			max: 3,
			window: 'day',
		} );
		expect( facts.hours ).toBe( 'limited' );
		expect( facts.targeting ).toEqual( { kind: 'conditions', count: 2 } );
	} );

	// The negative that matters: a rule the fields cannot show must not be
	// summarised as something simpler than it is.
	it( 'calls a shape the fields cannot show custom, not simpler than it is', () => {
		const facts = policyFacts( {
			...base,
			frequency_policy: {
				enabled: true,
				max_impressions: 3,
				window: 'week',
			},
			delivery_settings: {
				dayparts: [ { days: [ 1 ] }, { days: [ 2 ] } ],
			},
			targeting_rules: {
				operator: 'OR',
				rules: [
					{ dimension: 'post_type', operator: 'eq', value: 'post' },
				],
			},
		} );

		expect( facts.frequency ).toEqual( { kind: 'custom' } );
		expect( facts.hours ).toBe( 'custom' );
		expect( facts.targeting ).toEqual( { kind: 'custom' } );
	} );

	it( 'does not report a limit that is switched off', () => {
		expect(
			policyFacts( {
				...base,
				frequency_policy: { enabled: false, max_impressions: 3 },
			} ).frequency
		).toEqual( { kind: 'none' } );
	} );
} );
