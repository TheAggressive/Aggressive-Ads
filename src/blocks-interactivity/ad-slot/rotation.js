/**
 * Numbers the timer is allowed to use.
 *
 * Split out of the store so the cap and the floor can be tested without
 * booting `@wordpress/interactivity`. The store owns *when* a slot rotates;
 * this owns *how far* it may.
 */

/**
 * The shortest rotation this will honour, in seconds.
 *
 * One second, by product decision — the publisher wants rotation to be visible
 * rather than paced. **Every rotation is still a new impression**, so a slot at
 * this floor records sixty an hour per minute of viewing, which is the kind of
 * volume an exchange classifies as invalid traffic. The publisher's policy is
 * what prevents that; this floor only stops a zero or a negative from becoming
 * an interval of no length at all.
 */
export const MIN_ROTATE_SECONDS = 1;

/**
 * The most times one slot will rotate for a single page view.
 *
 * A last-resort ceiling, not the publisher's policy. The policy arrives as
 * `context.maxRefreshes` and is usually much smaller. This is what a tab left
 * open overnight hits if the context key is missing — a page cached before
 * the key existed — and what the migration hands existing placements so an
 * upgrade does not silently stop rotation.
 */
export const MAX_ROTATIONS = 100;

/**
 * Seconds an attribute asks for, clamped to something honest.
 *
 * @param {unknown} seconds Requested interval.
 * @return {number} Interval to use.
 */
export const rotationInterval = ( seconds ) => {
	const requested = Number( seconds );

	if ( ! Number.isFinite( requested ) ) {
		return MIN_ROTATE_SECONDS;
	}

	return Math.max( MIN_ROTATE_SECONDS, Math.floor( requested ) );
};

/**
 * Refreshes one slot will perform, floored at zero and capped at the hard stop.
 *
 * The publisher's number arrives as `context.maxRefreshes`. An absent or
 * unreadable value — a page cached before the key existed — falls back to
 * `MAX_ROTATIONS`, which is what that page already permitted.
 *
 * @param {unknown} requested Publisher cap from slot context.
 * @return {number} Cap to apply.
 */
export const rotationCap = ( requested ) => {
	const cap = Number( requested );

	if ( ! Number.isFinite( cap ) ) {
		return MAX_ROTATIONS;
	}

	return Math.min( MAX_ROTATIONS, Math.max( 0, Math.floor( cap ) ) );
};
