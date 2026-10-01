import { scheduleProgress } from '../schedule';

const DAY = 86400;
const start = 1_790_000_000;

describe( 'scheduleProgress', () => {
	it( 'counts the first day as day one', () => {
		expect(
			scheduleProgress( start, start + 28 * DAY, start + 60 )
		).toEqual( expect.objectContaining( { day: 1, total: 28 } ) );
	} );

	it( 'names the day part-way through', () => {
		const result = scheduleProgress(
			start,
			start + 28 * DAY,
			start + 7 * DAY + 3600
		);

		expect( result?.day ).toBe( 8 );
		expect( result?.fraction ).toBeCloseTo(
			( 7 * DAY + 3600 ) / ( 28 * DAY )
		);
	} );

	it( 'never names a day past the last', () => {
		expect(
			scheduleProgress( start, start + 28 * DAY, start + 28 * DAY )?.day
		).toBe( 28 );
	} );

	// The negatives: outside the schedule there is no day to name.
	it( 'answers nothing before the start, after the end, or for no schedule', () => {
		expect( scheduleProgress( start, start + DAY, start - 1 ) ).toBeNull();
		expect(
			scheduleProgress( start, start + DAY, start + DAY + 1 )
		).toBeNull();
		expect( scheduleProgress( 0, start, start ) ).toBeNull();
		expect( scheduleProgress( start, start, start ) ).toBeNull();
	} );
} );
