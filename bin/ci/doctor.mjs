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
 *
 * It also compares the *host* interpreters against the versions the workflow
 * pins. Those pins are the second drift axis after the lane list: `ci:php` runs
 * PHPCS, PHPStan and the unit suite on whatever `php` is on PATH, so a machine a
 * minor ahead of CI is analysing different deprecations and different behaviour
 * than the run that decides the build. Node is read from `env.NODE_VERSION` and
 * PHP from `env.PHP_VERSION`, so the workflow stays the one place either is
 * declared.
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

/**
 * The interpreter versions the workflow pins, read from the workflow.
 *
 * @return {{node: string|null, php: string|null}} Pinned versions.
 */
function workflowPins() {
	try {
		const yaml = readFileSync(
			resolve( root, '.github/workflows/ci.yml' ),
			'utf8'
		);

		return {
			node: /NODE_VERSION:\s*'([^']+)'/.exec( yaml )?.[ 1 ] ?? null,
			php: /PHP_VERSION:\s*'([^']+)'/.exec( yaml )?.[ 1 ] ?? null,
		};
	} catch {
		return { node: null, php: null };
	}
}

/**
 * The host PHP's `major.minor`, or null when PHP is not on PATH.
 *
 * @return {string|null} Version series.
 */
function phpSeries() {
	try {
		const raw = execFileSync( 'php', [ '-r', 'echo PHP_VERSION;' ], {
			encoding: 'utf8',
		} );

		return /^(\d+\.\d+)/.exec( raw )?.[ 1 ] ?? null;
	} catch {
		return null;
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

/*
 * The workflow's pins, compared by series. An exact patch match is not the
 * point and would fail on every upstream release; running a different *minor*
 * is what makes a local result unrepresentative.
 */
const pins = workflowPins();
const php = phpSeries();
const nodeSeries = /^v?(\d+\.\d+)/.exec( process.version )?.[ 1 ] ?? null;
const pinnedNodeSeries = pins.node
	? /^(\d+\.\d+)/.exec( pins.node )?.[ 1 ] ?? null
	: null;

/*
 * A warning rather than a failure, and the distinction is the point.
 *
 * Every analyser is already pinned to the floor independently of the host:
 * phpstan.neon sets phpVersion 80400, phpcs.xml.dist sets testVersion 8.4-,
 * and composer.json pins platform.php. Those produce identical results on any
 * interpreter, so failing here would stop a run that is going to agree with CI
 * anyway — a gate firing on a legitimate machine is itself a defect.
 *
 * What is genuinely host-dependent is narrow and worth naming: the unit suite
 * executes on this PHP, so a deprecation or behaviour change between series
 * shows up here and not in CI, or the reverse.
 */
if ( null !== php && null !== pins.php && php !== pins.php ) {
	console.warn(
		`doctor: PHP ${ php } here, ${ pins.php } in CI.\n` +
			'        PHPStan, PHPCS and Composer are pinned to the floor and agree\n' +
			'        either way; the unit suite runs on this interpreter, so runtime\n' +
			'        deprecations are the one thing that can differ.'
	);
}

if (
	null !== nodeSeries &&
	null !== pinnedNodeSeries &&
	nodeSeries !== pinnedNodeSeries
) {
	console.error(
		`doctor: Node ${ nodeSeries } here, ${ pinnedNodeSeries } in CI.\n` +
			'        Change NODE_VERSION in .github/workflows/ci.yml, or switch\n' +
			'        this machine to the pinned series.'
	);
	failed = true;
}

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
