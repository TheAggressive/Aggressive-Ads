#!/usr/bin/env node
/**
 * Toolchain check.
 *
 * Runs first in every CI lane and has no dependencies of its own, so a Node or
 * pnpm mismatch fails in two seconds with a clear message rather than as a
 * confusing error twenty minutes into a build.
 *
 * Only the major-version bounds in package.json `engines` are checked, which is
 * all these ranges express and all a hand-rolled comparison should attempt.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const pkg = JSON.parse(
	readFileSync( resolve( root, 'package.json' ), 'utf8' )
);

/**
 * Parses a range of the form ">=24 <25" into inclusive/exclusive majors.
 *
 * @param {string} range Engine range.
 * @return {{min: number|null, maxExclusive: number|null}} Parsed bounds.
 */
function parseRange( range ) {
	const min = /(?:^|\s)>=\s*(\d+)/.exec( range );
	const max = /(?:^|\s)<\s*(\d+)/.exec( range );

	return {
		min: min ? Number( min[ 1 ] ) : null,
		maxExclusive: max ? Number( max[ 1 ] ) : null,
	};
}

/**
 * Reads the major version from a `tool --version` style string.
 *
 * @param {string} output Raw command output.
 * @return {number|null} Major version.
 */
function majorOf( output ) {
	const match = /(\d+)\.\d+/.exec( output.trim().replace( /^v/, '' ) );

	return match ? Number( match[ 1 ] ) : null;
}

/**
 * Reads pnpm from the package-manager user agent when invoked by a pnpm
 * script. This avoids recursively starting pnpm (and opening its store) merely
 * to print a version. Direct invocations retain the command fallback.
 *
 * @return {string} pnpm version, or an empty string when unavailable.
 */
function pnpmVersion() {
	const userAgent = process.env.npm_config_user_agent ?? '';
	const fromUserAgent = /(?:^|\s)pnpm\/([^\s]+)/.exec( userAgent )?.[ 1 ];

	if ( fromUserAgent ) {
		return fromUserAgent;
	}

	try {
		return execFileSync( 'pnpm', [ '--version' ], { encoding: 'utf8' } );
	} catch {
		return '';
	}
}

const checks = [
	{ name: 'node', actual: process.version, range: pkg.engines?.node },
	{
		name: 'pnpm',
		actual: pnpmVersion(),
		range: pkg.engines?.pnpm,
	},
];

let failed = false;

for ( const { name, actual, range } of checks ) {
	if ( ! range ) {
		continue;
	}

	const major = majorOf( actual );

	if ( major === null ) {
		console.error(
			`doctor: could not determine ${ name } version (is it installed?)`
		);
		failed = true;
		continue;
	}

	const { min, maxExclusive } = parseRange( range );
	const tooOld = min !== null && major < min;
	const tooNew = maxExclusive !== null && major >= maxExclusive;

	if ( tooOld || tooNew ) {
		console.error(
			`doctor: ${ name } ${ actual.trim() } does not satisfy "${ range }"`
		);
		failed = true;
		continue;
	}

	console.log( `doctor: ${ name } ${ actual.trim() } ok (${ range })` );
}

process.exit( failed ? 1 : 0 );
