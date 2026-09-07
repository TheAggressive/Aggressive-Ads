#!/usr/bin/env node
/**
 * Public methods in `inc/` that nothing calls.
 *
 * This lane exists because the defect it catches has shipped repeatedly, and
 * every time it looked like a working feature from the outside:
 *
 * - `Creative_Repository::opens_in_new_window()` was read by nothing, so no
 *   advertisement could be made to open in a new tab while the setting sat in
 *   the database being carried across every creative revision.
 * - `Creative_Repository::set_provider_ad_id()` described a publishing loop the
 *   superseded adapter used to run.
 * - `Campaign_Repository::pending_edits_at()` and `pending_edits_by()` read two
 *   meta keys that were faithfully written and cleared on live paths and read
 *   by nobody.
 * - `Settings_Schema::structural_edit_keys()` named a product rule that a
 *   hardcoded field check enforced separately, so the two could disagree.
 * - `Decision_Engine::no_fill_reason()` wrapped a constant every caller used
 *   directly.
 *
 * Dead code is not merely untidy here. A method with no caller is a claim that
 * a behaviour exists, and the next person to need that behaviour finds it,
 * believes it, and ships on top of it.
 *
 * **A self-reference counts.** Hook and REST callbacks are registered by name
 * — `add_action( 'x', array( $this, 'method' ) )` — so a method named only
 * inside its own file is wired up, not dead. Requiring a reference from
 * elsewhere would fail on most of `inc/Portal/` and `inc/Admin/`, and a gate
 * that fires on correct code is a defect of its own.
 */

import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const ROOT = path.resolve( import.meta.dirname, '../..' );
const SCAN_ROOT = process.env.AGGR_DEAD_CODE_SCAN_DIR ?? ROOT;

/** Directories that hold no first-party source. */
const SKIP = new Set( [
	'node_modules',
	'vendor',
	'dist',
	'.git',
	'.cache',
	'wp',
	'coverage',
	'languages',
	'.playwright-results',
] );

/** Extensions that can reference a PHP method name. */
const REFERENCING = /\.(php|mjs|js|jsx|ts|tsx)$/;

/**
 * Every first-party source file under a directory.
 *
 * @param {string}   dir   Directory to walk.
 * @param {string[]} found Accumulator.
 * @return {Promise<string[]>} Absolute paths.
 */
async function walk( dir, found = [] ) {
	let entries = [];

	try {
		entries = await readdir( dir, { withFileTypes: true } );
	} catch {
		return found;
	}

	for ( const entry of entries ) {
		if ( SKIP.has( entry.name ) ) {
			continue;
		}

		const full = path.join( dir, entry.name );

		if ( entry.isDirectory() ) {
			await walk( full, found );
		} else if ( REFERENCING.test( entry.name ) ) {
			found.push( full );
		}
	}

	return found;
}

async function main() {
	const files = await walk( SCAN_ROOT );
	const sources = new Map();

	for ( const file of files ) {
		sources.set( file, await readFile( file, 'utf8' ) );
	}

	const declaring = files.filter(
		( file ) =>
			file.endsWith( '.php' ) &&
			file.includes( `${ path.sep }inc${ path.sep }` )
	);

	const problems = [];
	let scanned = 0;

	for ( const file of declaring ) {
		const source = sources.get( file );

		for ( const match of source.matchAll(
			/^\tpublic (?:static )?function ([a-z_][a-z0-9_]*)\s*\(/gm
		) ) {
			const method = match[ 1 ];

			// Magic methods are called by the language, never by name.
			if ( method.startsWith( '__' ) ) {
				continue;
			}

			scanned += 1;

			const named = new RegExp(
				`(?<![A-Za-z0-9_])${ method }(?![A-Za-z0-9_])`,
				'g'
			);
			let references = 0;

			for ( const [ candidate, text ] of sources ) {
				const hits = ( text.match( named ) ?? [] ).length;

				// Its own declaration is not a use of it.
				references +=
					candidate === file ? Math.max( 0, hits - 1 ) : hits;
			}

			if ( 0 === references ) {
				problems.push(
					`check-dead-code: ${ path.relative(
						SCAN_ROOT,
						file
					) } declares ${ method }() and nothing calls it. A method with ` +
						'no caller is a claim that a behaviour exists — delete it, ' +
						'or wire up the half that is missing.'
				);
			}
		}
	}

	/*
	 * A scan that matched nothing would report success over every file in the
	 * plugin, which is the failure mode most of `bin/ci/` has had at least
	 * once. The floor is deliberately far below the real count so a
	 * reorganisation does not trip it, and far above zero so a broken pattern
	 * does.
	 */
	if ( scanned < 200 ) {
		problems.push(
			`check-dead-code: only ${ scanned } public methods were found, so this ` +
				'lane is protecting nothing. Fix the pattern rather than the floor.'
		);
	}

	if ( problems.length > 0 ) {
		console.error( problems.join( '\n' ) );
		process.exit( 1 );
	}

	console.log(
		`check-dead-code: ok (${ scanned } public methods, ${ files.length } files searched)`
	);
}

await main();
