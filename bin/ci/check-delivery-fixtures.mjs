/**
 * Keeps the two artefacts that prove delivery works from arranging their own
 * answer.
 *
 * `candidates_for_placement()` selects on `status = 'live'` and reads
 * `attachment_id` off the assignment row, because a fill must be one indexed
 * read rather than a join back to the campaign and the creative. That
 * denormalization is only correct while something refreshes it, and for a long
 * time nothing did: `Assignment_Rules::status_for_campaign()` existed, was
 * correct, and had exactly one production caller — a one-time backfill. Every
 * campaign that went live afterwards kept its assignments at `draft`, matched
 * no candidate, and served nothing.
 *
 * **Every test in the suite was green throughout**, because each one wrote
 * `'status' => Assignment_Rules::LIVE` into its own fixture. Twelve PHP tests
 * and the browser fixture alike, so the one test that watches a real
 * advertisement in a real browser was only ever testing the renderer.
 *
 * The fix was not to ban that write everywhere. A test of the decision pipeline
 * is entitled to hand it a candidate row, which is exactly what
 * `DecisionPolicyInputsTest` exists to keep honest. The fix was that *some*
 * test has to obtain a live assignment the way production does, and these are
 * the two that do:
 *
 *   - `AssignmentProjectionTest` drives a real campaign transition and asserts
 *     the row that comes out of it.
 *   - `seed-live-ad.php` starts the campaign one legal edge short of live,
 *     drives the transition, and throws if the fixture does not end up serving.
 *
 * Both currently use `Assignment_Rules::LIVE` only to *assert*. This lane keeps
 * it that way. The failure it prevents is somebody debugging a flake in one of
 * them, adding `'status' => Assignment_Rules::LIVE` to the fixture to make it
 * pass, and quietly restoring the exact blind spot that hid a dead delivery
 * path through twelve green tests.
 */

import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const ROOT = path.resolve( import.meta.dirname, '../..' );

/*
 * Overridable only so this lane's own tests can point it at fixtures, the same
 * reason check-navigation.mjs takes AGGR_NAVIGATION_SCAN_DIR. A guard nobody
 * exercises rots into one that permits everything, silently.
 */
const SCAN_ROOT = process.env.AGGR_DELIVERY_SCAN_DIR ?? ROOT;

/**
 * The files whose whole purpose is to prove production sets the status.
 *
 * Named explicitly rather than discovered. A pattern like "every delivery test"
 * would have to decide what one is, and the honest answer is that most delivery
 * tests *should* build a candidate row by hand — only these two are making the
 * claim that something else built it.
 */
const PROTECTED = [
	'tests/php/Integration/AssignmentProjectionTest.php',
	'tests/e2e/seed-live-ad.php',
];

/**
 * A live assignment status being *written*, rather than asserted.
 *
 * Anchored on the `status` key, because that is what separates the two uses.
 * `assertSame( Assignment_Rules::LIVE, $row['status'] )` reads the constant and
 * is the point of these files; `'status' => Assignment_Rules::LIVE` supplies
 * it, and is the thing that hid the defect.
 */
const WRITES_LIVE =
	/(['"])status\1\s*=>\s*(Assignment_Rules::LIVE|(['"])live\3)/g;

/**
 * Absolute path for one protected file.
 *
 * @param {string} relative Repository-relative path.
 * @return {string} Absolute path under the scan root.
 */
function resolve( relative ) {
	return path.join( SCAN_ROOT, relative );
}

/**
 * Line numbers that are prose rather than code.
 *
 * Both protected files *document* the rule, and `AssignmentProjectionTest`
 * quotes the forbidden line verbatim to explain what must not appear. Reading
 * that as a violation is the guard firing on the thing it is protecting, so
 * comments are excluded before matching rather than the file being exempted.
 *
 * Deliberately line-oriented instead of stripping comment text out of code
 * lines: a naive `//` strip truncates any line containing a URL, and a silent
 * false negative in a guard is worse than a noisy false positive.
 *
 * @param {string[]} lines File split on newlines.
 * @return {Set<number>} Zero-based indices to ignore.
 */
function commentLines( lines ) {
	const skip = new Set();
	let inBlock = false;

	lines.forEach( ( line, index ) => {
		const trimmed = line.trim();

		if ( inBlock ) {
			skip.add( index );

			if ( trimmed.includes( '*/' ) ) {
				inBlock = false;
			}

			return;
		}

		if ( trimmed.startsWith( '//' ) || trimmed.startsWith( '#' ) ) {
			skip.add( index );

			return;
		}

		if ( trimmed.startsWith( '/*' ) ) {
			skip.add( index );
			inBlock = ! trimmed.includes( '*/' );
		}
	} );

	return skip;
}

/**
 * Runs the check.
 *
 * @return {Promise<void>} Resolves when the lane has reported.
 */
async function main() {
	const problems = [];
	let scanned = 0;

	for ( const relative of PROTECTED ) {
		const absolute = resolve( relative );

		/*
		 * A missing file is a failure, not a skip. This lane's entire value is
		 * that it is reading the two files it names, and a rename would
		 * otherwise turn it into a check that passes over nothing at all —
		 * which is how most of the guards in this directory were first found
		 * not to work.
		 */
		try {
			await stat( absolute );
		} catch {
			problems.push(
				`check-delivery-fixtures: ${ relative } does not exist, so this ` +
					'lane is protecting nothing. Update PROTECTED when the file moves.'
			);

			continue;
		}

		const contents = await readFile( absolute, 'utf8' );
		const lines = contents.split( '\n' );
		const prose = commentLines( lines );

		scanned += 1;

		lines.forEach( ( line, index ) => {
			WRITES_LIVE.lastIndex = 0;

			if ( ! prose.has( index ) && WRITES_LIVE.test( line ) ) {
				problems.push(
					`${ relative }:${ index + 1 }: assigns a live assignment ` +
						'status. This file exists to prove production sets it, ' +
						'so setting it here asserts nothing.\n' +
						`    ${ line.trim() }`
				);
			}
		} );
	}

	if ( problems.length > 0 ) {
		console.error( problems.join( '\n' ) );
		console.error(
			'\nA delivery fixture that arranges its own live status tests the ' +
				'renderer, not the pipeline. See docs/open-work.md.'
		);
		process.exit( 1 );
	}

	console.log(
		`check-delivery-fixtures: ok (${ scanned } files, ${ PROTECTED.length } protected)`
	);
}

await main();
