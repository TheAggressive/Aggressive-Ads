#!/usr/bin/env node

/**
 * Bring the checked-in version declarations up to the latest release tag.
 *
 * Cosmetic, and deliberately so. `package.sh` derives the version it stamps
 * from the tag, so nothing built is wrong when these are behind — they only
 * decide what a development install shows in the plugins list.
 *
 * It exists because the alternative is editing five files by hand and finding
 * out from `check-version-contract` which one was missed.
 */

import { execFileSync } from 'node:child_process';
import process from 'node:process';

import { assertVersion, writeSourceVersions } from './version-contract.mjs';

function latestTag() {
	// Refresh first. `git describe` reads local tags, and a checkout that has
	// not fetched since the last release resolves to the previous version —
	// writing a stale number into every declaration and reporting success. The
	// guard in CI would then reject work that looked correct locally, which is
	// the divergence this repository spends most of its tooling preventing.
	try {
		execFileSync( 'git', [ 'fetch', '--tags', '--quiet' ], { stdio: 'ignore' } );
	} catch {
		// Offline is survivable — the tags on disk may well be current, and the
		// CI guard is the authority either way.
		console.warn( 'sync-version: could not fetch tags; using what is on disk.' );
	}

	const described = execFileSync(
		'git',
		[ 'describe', '--tags', '--abbrev=0' ],
		{ encoding: 'utf8' }
	).trim();

	return described.replace( /^v/u, '' );
}

try {
	// An explicit argument wins, so a release that has not been tagged yet can
	// still be written without waiting for the tag to exist.
	const requested = process.argv[ 2 ] ?? latestTag();

	assertVersion( requested, 'Requested version' );

	const written = await writeSourceVersions( requested );

	// The catalog embeds the version in Project-Id-Version, so the two have to
	// move together. The drift check normalizes that header away before
	// comparing — deliberately, since it compares strings rather than versions —
	// which means nothing else would ever notice the POT being left behind.
	execFileSync( 'bash', [ 'bin/i18n/pot.sh' ], { stdio: 'inherit' } );

	console.log( `sync-version: checked-in declarations and the POT now read ${ written }` );
} catch ( error ) {
	const message = error instanceof Error ? error.message : String( error );
	console.error( `sync-version: ${ message }` );
	process.exitCode = 1;
}
