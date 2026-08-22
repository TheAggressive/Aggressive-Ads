#!/usr/bin/env node
/**
 * One door for navigation.
 *
 * CodeQL found js/xss-through-dom in the review screen: bootstrap data arrives
 * in a `data-aggr-review` attribute, and assigning that DOM text to
 * `location.href` executes it when it carries a `javascript:` scheme. The value was
 * server-generated, so the code was safe in practice and unsafe in shape — the
 * guarantee lived in every producer upstream instead of at the point of use.
 *
 * Fixing the one line CodeQL named would have left the second identical sink in
 * the same file, and nothing stopping a third. So the sinks are banned outright
 * and `sameOriginUrl()` is the only place allowed to reach one.
 *
 * This runs in lint:files, which means it fails a local run and a pre-push. The
 * CodeQL scan is the backstop, not the first line: it only runs once a pull
 * request is open against master, and this repository has already shipped
 * fourteen commits to a branch that never got one.
 */

import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const ROOT = path.resolve( import.meta.dirname, '../..' );

/*
 * Overridable only so this lane's own tests can point it at fixtures, the same
 * reason bin/check-shell.sh takes AGGR_SHELL_SCAN_DIR. A guard whose sink
 * patterns nobody exercises is a guard that rots into permitting everything,
 * and the failure is silent by construction.
 */
const SOURCE_DIR =
	process.env.AGGR_NAVIGATION_SCAN_DIR ?? path.join( ROOT, 'src' );

const EXTENSIONS = [ '.ts', '.tsx', '.js', '.jsx' ];

/** The only module permitted to perform a navigation, relative to SOURCE_DIR. */
const GATEWAY = 'admin/shared/navigate.ts';

/*
 * Assignments and calls, not reads. `new URL( window.location.href )` is fine —
 * it is the write that navigates.
 */
const SINKS = [
	{
		pattern: /location\s*\.\s*href\s*=(?!=)/,
		name: 'location.href assignment',
	},
	{ pattern: /location\s*\.\s*assign\s*\(/, name: 'location.assign()' },
	{ pattern: /location\s*\.\s*replace\s*\(/, name: 'location.replace()' },
];

/** Fixtures describe what a component produced; they are not shipped markup. */
const SKIP = /(^|\/)__tests__(\/|$)/;

/**
 * Every source file under one directory, recursively.
 *
 * @param {string} dir Absolute directory.
 * @return {Promise<string[]>} Absolute file paths.
 */
async function filesIn( dir ) {
	let entries = [];

	try {
		entries = await readdir( dir, { withFileTypes: true } );
	} catch {
		return [];
	}

	const found = await Promise.all(
		entries.map( async ( entry ) => {
			const full = path.join( dir, entry.name );

			if ( entry.isDirectory() ) {
				return filesIn( full );
			}

			if ( SKIP.test( full ) ) {
				return [];
			}

			// isFile(), not "anything that is not a directory". A symlink
			// reports neither, and one pointing at a directory whose name ends
			// in .ts made this crash with EISDIR rather than report a finding.
			if ( ! entry.isFile() ) {
				return [];
			}

			return EXTENSIONS.includes( path.extname( entry.name ) )
				? [ full ]
				: [];
		} )
	);

	return found.flat();
}

async function main() {
	const files = await filesIn( SOURCE_DIR );

	if ( 0 === files.length ) {
		console.error(
			'check-navigation: scanned no files — refusing to report success.'
		);
		process.exit( 1 );
	}

	const problems = [];
	let gatewaySeen = false;

	for ( const file of files ) {
		const relative = path.relative( SOURCE_DIR, file );
		const source = await readFile( file, 'utf8' );
		const lines = source.split( '\n' );

		if ( relative === GATEWAY ) {
			gatewaySeen = true;
			continue;
		}

		lines.forEach( ( line, index ) => {
			for ( const sink of SINKS ) {
				if ( sink.pattern.test( line ) ) {
					problems.push(
						`${ relative }:${ index + 1 }: ${ sink.name } — ` +
							'navigate through sameOriginUrl()/navigateSameOrigin() ' +
							`in ${ GATEWAY } instead.`
					);
				}
			}
		} );
	}

	// The allowlist has to name something real, or a rename turns this lane into
	// a check that permits everything by scanning nothing.
	if ( ! gatewaySeen ) {
		console.error(
			`check-navigation: ${ GATEWAY } does not exist, so the exemption ` +
				'names nothing. Update GATEWAY when the helper moves.'
		);
		process.exit( 1 );
	}

	if ( problems.length > 0 ) {
		console.error( problems.join( '\n' ) );
		console.error(
			'\nDOM text assigned to a navigation sink executes a javascript: URL.'
		);
		process.exit( 1 );
	}

	console.log(
		`check-navigation: ok (${ files.length } files, one gateway)`
	);
}

await main();
