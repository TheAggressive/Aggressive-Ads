/**
 * What the pipeline must look like for a run to count as passing.
 *
 * Extracted from a shell block in `ci.yml` that had grown to fifty-three lines
 * of conditional assertion. It is the highest-consequence logic in the
 * repository — a mistake here either blocks every pull request or, far worse,
 * passes every one — and it was the only guard with no tests, because it grew a
 * clause at a time rather than being written once.
 *
 * Pure, so every branch can be stated rather than inferred from a CI run.
 */

/**
 * Lanes that must succeed on an ordinary run.
 */
export const QUALITY_LANES = Object.freeze( [
	'SECURITY',
	'STRUCTURE',
	'I18N',
	'FRONTEND',
	'PHP',
	'COVERAGE',
	'WORDPRESS',
	'BUILD',
	'E2E',
	'PACKAGE',
] );

/**
 * The three a version string or a Markdown edit cannot affect.
 *
 * Skipping these is a deliberate saving, not a gap: running a browser suite
 * against a version bump was never coverage.
 */
export const SKIPPABLE_LANES = Object.freeze( [
	'WORDPRESS',
	'E2E',
	'PACKAGE',
] );

/**
 * What the machine version sync may skip: everything.
 *
 * It rewrites version strings and a catalog header, so no lane can say anything
 * about it. The workflows still trigger, so the required checks still report —
 * a workflow that never runs leaves its check pending forever, which would
 * strand the very pull request this exists to speed up. Only the work inside
 * them is skipped.
 */
export const SYNC_SKIPPABLE_LANES = QUALITY_LANES;

/**
 * Judges one run.
 *
 * @param {object} run                   Job results, keyed by lane name.
 * @param {boolean} run.syncOnly         Whether this is the machine version sync.
 * @param {boolean} run.proseOnly        Whether the diff is Markdown only.
 * @param {boolean} run.publishRequested Whether a release was asked for.
 * @param {boolean} run.onMaster         Whether the ref is master.
 * @param {boolean} run.shouldRelease    Whether planning found a release due.
 * @return {{ok: boolean, problems: Array<string>}} Verdict and every reason.
 */
export function judge( run ) {
	const problems = [];
	const skipAllowed = run.syncOnly === true || run.proseOnly === true;

	for ( const lane of QUALITY_LANES ) {
		const result = run[ lane ];

		if ( 'success' === result ) {
			continue;
		}

		// Only skipped, and only for the shapes that earn it. A failure is
		// still a failure however the run was classified, and a prose diff gets
		// the narrow allowance rather than the sync's blanket one: prose can
		// still touch a docblock a linter reads.
		const allowedHere =
			run.syncOnly === true ? SYNC_SKIPPABLE_LANES : SKIPPABLE_LANES;

		if (
			skipAllowed &&
			'skipped' === result &&
			allowedHere.includes( lane )
		) {
			continue;
		}

		problems.push( `${ lane } is ${ result }` );
	}

	// Both directions, deliberately. Asserting only that planning succeeded
	// when expected would let it run on an ordinary merge unnoticed, which is
	// how "publishing is deliberate" would quietly stop being true.
	const planningExpected =
		run.publishRequested === true && run.onMaster === true;
	const planning = run.RELEASE_PLAN;

	if ( planningExpected && 'success' !== planning ) {
		problems.push(
			`RELEASE_PLAN is ${ planning }, but publishing was requested`
		);
	}

	if ( ! planningExpected && 'skipped' !== planning ) {
		problems.push(
			`RELEASE_PLAN is ${ planning }, but publishing was not requested`
		);
	}

	// A failed sync is a failed run: delivery is best-effort by construction,
	// so the only thing keeping the repository honest is that nobody can miss
	// the failure.
	const expected = run.shouldRelease === true ? 'success' : 'skipped';

	for ( const lane of [ 'RELEASE', 'VERSION_SYNC' ] ) {
		if ( expected !== run[ lane ] ) {
			problems.push(
				`${ lane } is ${ run[ lane ] }, expected ${ expected }`
			);
		}
	}

	return { ok: 0 === problems.length, problems };
}
