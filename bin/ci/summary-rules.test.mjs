import assert from 'node:assert/strict';
import test from 'node:test';

import {
	judge,
	QUALITY_LANES,
	SKIPPABLE_LANES,
	SYNC_SKIPPABLE_LANES,
} from './summary-rules.mjs';

/**
 * An ordinary green pull request: every lane passed, nothing published.
 *
 * @param {object} overrides Fields to change.
 * @return {object} A run description.
 */
function run( overrides = {} ) {
	const base = {
		syncOnly: false,
		proseOnly: false,
		publishRequested: false,
		onMaster: false,
		shouldRelease: false,
		RELEASE_PLAN: 'skipped',
		RELEASE: 'skipped',
		VERSION_SYNC: 'skipped',
	};

	for ( const lane of QUALITY_LANES ) {
		base[ lane ] = 'success';
	}

	return { ...base, ...overrides };
}

test( 'an ordinary green pull request passes', () => {
	assert.deepEqual( judge( run() ), { ok: true, problems: [] } );
} );

test( 'any failed lane fails the run', () => {
	for ( const lane of QUALITY_LANES ) {
		const verdict = judge( run( { [ lane ]: 'failure' } ) );

		assert.equal(
			verdict.ok,
			false,
			`${ lane } failing must fail the run`
		);
	}
} );

// The saving is only ever a skip. A version sync whose PHP lane *failed* is a
// broken repository, not a cheap run.
test( 'a failure is never excused, even for the shapes that may skip', () => {
	for ( const shape of [ 'syncOnly', 'proseOnly' ] ) {
		for ( const lane of SKIPPABLE_LANES ) {
			const verdict = judge(
				run( { [ shape ]: true, [ lane ]: 'failure' } )
			);

			assert.equal(
				verdict.ok,
				false,
				`${ lane } failing under ${ shape } must fail`
			);
		}
	}
} );

test( 'a prose diff may skip exactly the three lanes it cannot affect', () => {
	const skipped = Object.fromEntries(
		SKIPPABLE_LANES.map( ( l ) => [ l, 'skipped' ] )
	);

	assert.equal( judge( run( { proseOnly: true, ...skipped } ) ).ok, true );
} );

// The sync rewrites version strings and a catalog header, so no lane can say
// anything about it. The workflows still trigger, so the required checks still
// report — a workflow that never runs leaves its check pending forever, which
// would strand the very pull request this speeds up. Only the work inside them
// is skipped.
test( 'the version sync may skip every lane', () => {
	const skipped = Object.fromEntries(
		SYNC_SKIPPABLE_LANES.map( ( l ) => [ l, 'skipped' ] )
	);

	assert.equal( judge( run( { syncOnly: true, ...skipped } ) ).ok, true );
} );

// The allowance is per-shape, not global. A prose diff can still touch a
// docblock a linter reads, so it does not get the sync's blanket pass.
test( 'a prose diff may not skip the lanes only the sync may', () => {
	assert.equal(
		judge( run( { proseOnly: true, PHP: 'skipped' } ) ).ok,
		false
	);
	assert.equal(
		judge( run( { proseOnly: true, FRONTEND: 'skipped' } ) ).ok,
		false
	);
} );

test( 'an ordinary run may not skip any lane', () => {
	for ( const lane of QUALITY_LANES ) {
		assert.equal(
			judge( run( { [ lane ]: 'skipped' } ) ).ok,
			false,
			`${ lane } must not be skippable on an ordinary run`
		);
	}
} );

test( 'planning must run when publishing was requested on master', () => {
	const publishing = { publishRequested: true, onMaster: true };

	assert.equal(
		judge( run( { ...publishing, RELEASE_PLAN: 'success' } ) ).ok,
		true
	);
	assert.equal(
		judge( run( { ...publishing, RELEASE_PLAN: 'skipped' } ) ).ok,
		false
	);
} );

// The direction that matters most: publishing being deliberate means planning
// must NOT run on an ordinary merge. Asserting only the other direction is how
// that guarantee would quietly stop being true.
test( 'planning running on an ordinary merge fails the run', () => {
	assert.equal( judge( run( { RELEASE_PLAN: 'success' } ) ).ok, false );
} );

test( 'a dispatch on another ref does not count as publishing', () => {
	const verdict = judge(
		run( {
			publishRequested: true,
			onMaster: false,
			RELEASE_PLAN: 'success',
		} )
	);

	assert.equal( verdict.ok, false );
} );

test( 'release and sync must both succeed when a release is due', () => {
	const due = {
		shouldRelease: true,
		publishRequested: true,
		onMaster: true,
		RELEASE_PLAN: 'success',
	};

	assert.equal(
		judge( run( { ...due, RELEASE: 'success', VERSION_SYNC: 'success' } ) )
			.ok,
		true
	);
	assert.equal(
		judge( run( { ...due, RELEASE: 'success', VERSION_SYNC: 'failure' } ) )
			.ok,
		false
	);
	assert.equal(
		judge( run( { ...due, RELEASE: 'failure', VERSION_SYNC: 'success' } ) )
			.ok,
		false
	);
} );

// A sync that ran when no release happened means something published without
// being planned, which is worth failing over rather than shrugging at.
test( 'release and sync must both be skipped when no release is due', () => {
	assert.equal( judge( run( { RELEASE: 'success' } ) ).ok, false );
	assert.equal( judge( run( { VERSION_SYNC: 'success' } ) ).ok, false );
} );

test( 'every reason is reported, not just the first', () => {
	const verdict = judge(
		run( { SECURITY: 'failure', PHP: 'failure', RELEASE: 'success' } )
	);

	assert.equal( verdict.problems.length, 3 );
} );

// A lane the workflow forgot to pass through arrives as 'missing'. Treating
// that as anything but a failure would let a renamed job silently stop being
// checked.
test( 'a lane the workflow never reported is a failure', () => {
	assert.equal( judge( run( { E2E: 'missing' } ) ).ok, false );
} );
