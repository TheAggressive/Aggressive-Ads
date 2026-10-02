/**
 * How far through its schedule a campaign is, as "day N of M".
 *
 * Durations between two server timestamps, never a calendar date built in the
 * browser: the difference between two instants is the same in every timezone,
 * where "which day is it" is not. Outside the schedule there is no day to
 * name, and the answer is null rather than "day 0" or "day 31 of 30".
 */

const DAY = 86400;

export type ScheduleProgress = {
	day: number;
	total: number;
	fraction: number;
};

export function scheduleProgress(
	start: number,
	end: number,
	now: number
): ScheduleProgress | null {
	if ( start <= 0 || end <= start || now < start || now > end ) {
		return null;
	}

	const total = Math.max( 1, Math.round( ( end - start ) / DAY ) );
	const day = Math.min( total, Math.floor( ( now - start ) / DAY ) + 1 );

	return {
		day,
		total,
		fraction: Math.min(
			1,
			Math.max( 0, ( now - start ) / ( end - start ) )
		),
	};
}
