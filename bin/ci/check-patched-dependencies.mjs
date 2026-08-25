#!/usr/bin/env node
/**
 * Regression check for audited transitive packages patched in this repository.
 *
 * The decision lives in `patched-dependency-rules.mjs`, which is pure and
 * tested. This file does the two things a test cannot: resolve the real copies
 * inside `node_modules`, and exercise the patched library for real.
 */

import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

import { judgeRun } from './patched-dependency-rules.mjs';

const require = createRequire( import.meta.url );

/*
 * The parents that pull adm-zip into this project.
 *
 * `@wordpress/env` used to be one and is not a dependency any more. Removing
 * wp-env left this list behind, and it kept resolving only because a stale copy
 * sat in an unrelated parent directory's node_modules — so on a clean checkout
 * require.resolve threw and this lane died before it checked anything.
 *
 * A parent named here must be a real dependency of this package.
 */
const parents = [ '@wordpress/scripts/package.json' ];

const copies = [];

for ( const parent of parents ) {
	const path = createRequire( require.resolve( parent ) ).resolve(
		'adm-zip/zipEntry.js'
	);

	if ( copies.some( ( copy ) => copy.path === path ) ) {
		continue;
	}

	copies.push( { path, source: readFileSync( path, 'utf8' ) } );
}

const { ok, problems, examined } = judgeRun( copies );

if ( ! ok ) {
	for ( const problem of problems ) {
		console.error( `patched-dependencies: ${ problem }` );
	}

	process.exit( 1 );
}

/*
 * And prove the patched library still works.
 *
 * On its own this proves nothing about the patch — an unpatched adm-zip round
 * trips just as happily — which is exactly why it used to be possible for this
 * script to report success having verified nothing. It earns its place only
 * after the assertions above have run against a non-empty set.
 */
const scriptsRequire = createRequire(
	require.resolve( '@wordpress/scripts/package.json' )
);
const AdmZip = scriptsRequire( 'adm-zip' );
const archive = new AdmZip();
archive.addFile( 'round-trip.txt', Buffer.from( 'patched dependency check' ) );
const reopened = new AdmZip( archive.toBuffer() );

if ( reopened.readAsText( 'round-trip.txt' ) !== 'patched dependency check' ) {
	console.error(
		'patched-dependencies: patched adm-zip failed a normal round trip'
	);
	process.exit( 1 );
}

console.log(
	`patched-dependencies: adm-zip CVE-2026-39244 fix present (${ examined } copy/copies)`
);
