#!/usr/bin/env node

/**
 * Guard: the checked-in version must match the latest published release.
 *
 * `package.sh` stamps the version into the archive only, so nothing about
 * publishing keeps the repository honest on its own. A post-release job opens a
 * sync pull request — but automation that opens a pull request cannot make
 * anyone merge it, so delivery is best-effort by construction.
 *
 * This is the gate that turns that into enforcement. An unmerged sync blocks
 * the next change instead of accumulating quietly, which is the only reason the
 * drift cannot come back. WordPress reads the plugin header as the authoritative
 * version and `AGGR_VERSION` is a cache key, so the drift is functional rather
 * than cosmetic.
 *
 * Fails closed. No reachable tags means the answer is unknown, not "fine": a
 * shallow clone must report that it cannot verify rather than pass vacuously.
 * The lanes that run this fetch tags for exactly that reason.
 *
 * Fix a failure with:  pnpm version:sync
 */

import { execFileSync } from 'node:child_process';
import process from 'node:process';

import { readSourceVersions } from '../release/version-contract.mjs';

const PLACEHOLDER_KEY = 'package.json version';

function latestReleaseTag() {
	// Sorted by version, not by date: a patch published after a later minor
	// must not be mistaken for the newest release.
	const tags = execFileSync(
		'git',
		[ 'tag', '--list', 'v[0-9]*', '--sort=-v:refname' ],
		{ encoding: 'utf8' }
	)
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean );

	return tags[ 0 ] ?? '';
}

try {
	execFileSync( 'git', [ 'rev-parse', '--git-dir' ], { stdio: 'ignore' } );
} catch {
	console.error(
		'check-version-sync: not a git repository, so the released version is unknown.'
	);
	process.exit( 1 );
}

const tag = latestReleaseTag();

if ( '' === tag ) {
	console.error(
		'check-version-sync: no release tags are reachable, so the released version is unknown.'
	);
	console.error(
		'check-version-sync: this fails rather than passes on missing data.'
	);
	console.error(
		'check-version-sync: shallow clone? run: git fetch --tags --force'
	);
	process.exit( 1 );
}

const released = tag.replace( /^v/u, '' );

if ( ! /^\d+\.\d+\.\d+$/u.test( released ) ) {
	console.error(
		`check-version-sync: latest tag '${ tag }' is not a bare semantic version.`
	);
	process.exit( 1 );
}

try {
	const versions = await readSourceVersions();
	const drifted = Object.entries( versions ).filter(
		( [ label, version ] ) =>
			label !== PLACEHOLDER_KEY && version !== released
	);

	if ( drifted.length > 0 ) {
		console.error(
			`check-version-sync: the checked-in version is behind the ${ tag } release.`
		);

		for ( const [ label, version ] of drifted ) {
			console.error(
				`  ${ label }: ${ version } (expected ${ released })`
			);
		}

		console.error( 'check-version-sync: fix with: pnpm version:sync' );
		process.exit( 1 );
	}

	console.log( `check-version-sync: ${ released } matches ${ tag } ok` );
} catch ( error ) {
	const message = error instanceof Error ? error.message : String( error );
	console.error( `check-version-sync: ${ message }` );
	process.exit( 1 );
}
