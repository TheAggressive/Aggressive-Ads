#!/usr/bin/env node
/**
 * Refuses `… | grep -q` in any shell script that sets `pipefail`.
 *
 * `grep -q` exits as soon as it finds a match. If the command feeding it is
 * still writing, that command takes SIGPIPE, and under `pipefail` the whole
 * pipeline then reports failure — so an `if` reads a match as a miss. How often
 * depends on scheduling, which is what makes it expensive: the script passes
 * most of the time and fails on a different line when it does not.
 *
 * It failed a pull request's package lane for `scroll-lock.js`, then failed a
 * local re-run for `wizard.js`, while both files sat in the archive exactly
 * once. Measured against that archive, the piped form falsely missed 6 to 20
 * times in 300 runs and a here-string never did.
 *
 * The miss is not always a spurious failure. In the package verifier's
 * forbidden-path check and in `check-worktree.sh` it runs the other way: a file
 * that must not ship, or an untracked file that must not exist, is reported
 * absent and the gate passes.
 *
 * A race cannot be tested reliably, but the pattern can be refused
 * deterministically, so this reads the scripts instead of running them. Write
 * `grep -q PATTERN <<< "${value}"` instead.
 */

import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const ROOT = path.resolve( import.meta.dirname, '../..' );

/*
 * Overridable only so this lane's own tests can point it at fixtures. A guard
 * nobody exercises rots into one that permits everything, silently.
 */
const SCAN_ROOT = process.env.AGGR_PIPEFAIL_GREP_SCAN_DIR ?? ROOT;
const SCAN_DIR = 'bin';

/** Directories that hold no first-party scripts. */
const SKIP = new Set( [ 'node_modules', 'vendor', '.git', '.cache' ] );

/**
 * A pipe (not `||`) into grep carrying a quiet flag, alone or in a cluster.
 *
 * Matches `| grep -q`, `| grep -qxF`, `| grep -E -q` and `| grep --quiet`, and
 * not `cmd || grep -q …`, which is a logical or with no pipe involved.
 */
const PIPED_QUIET_GREP =
	/(?<!\|)\|(?!\|)\s*grep\b[^|;&]*?\s(?:-[A-Za-z]*q[A-Za-z]*|--quiet|--silent)(?=\s|$)/;

/** A `set` line that turns `pipefail` on. */
const SETS_PIPEFAIL = /^\s*set\s+[^#\n]*\bpipefail\b/m;

/**
 * Every shell script under a directory.
 *
 * @param {string}   dir   Directory to walk.
 * @param {string[]} found Accumulator.
 * @return {Promise<string[]>} Absolute paths.
 */
async function scripts( dir, found = [] ) {
	let entries;

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
			await scripts( full, found );
		} else if ( entry.name.endsWith( '.sh' ) ) {
			found.push( full );
		}
	}

	return found;
}

const offenders = [];
let scanned = 0;

for ( const file of await scripts( path.join( SCAN_ROOT, SCAN_DIR ) ) ) {
	const source = await readFile( file, 'utf8' );

	if ( ! SETS_PIPEFAIL.test( source ) ) {
		continue;
	}

	scanned += 1;

	source.split( '\n' ).forEach( ( line, index ) => {
		if ( line.trimStart().startsWith( '#' ) ) {
			return;
		}

		if ( PIPED_QUIET_GREP.test( line ) ) {
			offenders.push(
				`${ path.relative( SCAN_ROOT, file ) }:${
					index + 1
				}: ${ line.trim() }`
			);
		}
	} );
}

/*
 * A scan that reads nothing proves nothing. A moved `bin/` would otherwise
 * print ok over a lane that looked at no script at all.
 */
if ( 0 === scanned ) {
	console.error(
		'check-pipefail-grep: found no script that sets pipefail, so this lane proves nothing.'
	);
	process.exit( 1 );
}

if ( offenders.length > 0 ) {
	console.error(
		'check-pipefail-grep: a pipe into `grep -q` under pipefail can read a match as a miss.\n'
	);
	offenders.forEach( ( offender ) => console.error( `  ${ offender }` ) );
	console.error(
		'\nUse a here-string instead: grep -q PATTERN <<< "${value}"'
	);
	process.exit( 1 );
}

console.log( `check-pipefail-grep: ok (${ scanned } scripts with pipefail)` );
