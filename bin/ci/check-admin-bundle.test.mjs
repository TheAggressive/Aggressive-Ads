/**
 * Tests for the admin bundle guard.
 *
 * A guard that stops matching does not fail — it reports success over output it
 * is no longer reading. So each case here builds a fixture that is *wrong* in
 * one specific way and asserts the guard says so, and the sound fixture asserts
 * silence. Both halves matter: a guard that never passes is as useless as one
 * that never fails.
 *
 * The two failing shapes are not hypothetical. Both were produced by the real
 * build while converting the organizations screen to DataViews, and neither
 * caused a webpack error.
 */

import { strict as assert } from 'node:assert';
import { mkdtempSync, mkdirSync, writeFileSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	checkDirectory,
	checkEntry,
	parseDependencies,
} from './check-admin-bundle.mjs';

/**
 * Writes a fake built entry.
 *
 * @param {string}   dir  Directory to write into.
 * @param {string}   name Entry name.
 * @param {string[]} deps Dependency handles for the .asset.php.
 * @param {string}   js   Contents of the .js bundle.
 * @param {string?}  css  Contents of the .css bundle, or null to omit it.
 */
function writeEntry( dir, name, deps, js, css ) {
	const list = deps.map( ( d ) => `'${ d }'` ).join( ', ' );

	writeFileSync(
		path.join( dir, `${ name }.asset.php` ),
		`<?php return array('dependencies' => array(${ list }), 'version' => 'abc');\n`
	);
	writeFileSync( path.join( dir, `${ name }.js` ), js );

	if ( null !== css ) {
		writeFileSync( path.join( dir, `${ name }.css` ), css );
	}
}

function fixture() {
	return mkdtempSync( path.join( tmpdir(), 'aggr-bundle-' ) );
}

/**
 * A stand-in for the real DataViews stylesheet.
 *
 * @param {number} count How many distinct .dataviews-* selectors to define.
 * @return {string} CSS text.
 */
function dataviewsCss( count ) {
	return Array.from(
		{ length: count },
		( _, i ) => `.dataviews-rule-${ i }{display:block}`
	).join( '\n' );
}

/**
 * The screen's own theme layer, which names a handful of DataViews classes in
 * order to restyle them. This is what made a presence check pass while the real
 * stylesheet was missing entirely.
 */
const THEME_ONLY_CSS = [
	'.dataviews-wrapper{background:#fff}',
	'.dataviews-view-table td{padding:16px}',
	'.dataviews-view-table th{padding:16px}',
	'.dataviews-filters__container{padding:12px}',
	'.dataviews-action-modal{max-width:60ch}',
	'.dataviews-view-table__primary-column-content{font-weight:600}',
].join( '\n' );

test( 'parses handles out of a generated .asset.php', () => {
	const deps = parseDependencies(
		"<?php return array('dependencies' => array('wp-element', 'wp-components'), 'version' => 'x');"
	);

	// A count, not just membership: a regex that swallowed the version string
	// would still contain both handles.
	assert.equal( deps.length, 2 );
	assert.deepEqual( deps, [ 'wp-element', 'wp-components' ] );
} );

test( 'a sound bundle reports nothing', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'organizations',
		[ 'wp-element', 'wp-components' ],
		'className:"dataviews-view-table"',
		dataviewsCss( 166 )
	);

	assert.deepEqual( checkDirectory( dir ), [] );
} );

test( 'rejects a subpath import that escaped externalisation', () => {
	const dir = fixture();

	// The exact shape the real build emitted before the matcher handled
	// subpaths: a "handle" that is really a file path.
	writeEntry(
		dir,
		'organizations',
		[ 'wp-element', 'wp-dataviews/build-style/style.css' ],
		'className:"dataviews-view-table"',
		dataviewsCss( 166 )
	);

	const problems = checkDirectory( dir );

	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /is not a script handle/ );
	assert.match( problems[ 0 ], /build-style/ );
} );

test( 'rejects a package WordPress does not register', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'organizations',
		[ 'wp-element', 'wp-dataviews' ],
		'className:"dataviews-view-table"',
		dataviewsCss( 166 )
	);

	const problems = checkDirectory( dir );

	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /not registered by WordPress/ );
} );

test( 'rejects DataViews markup shipped without DataViews styles', () => {
	const dir = fixture();

	// The tree-shaken stylesheet: JS carries the class, CSS exists but holds
	// only our own rules, because "sideEffects": false deleted the import.
	writeEntry(
		dir,
		'organizations',
		[ 'wp-element' ],
		'className:"dataviews-view-table"',
		THEME_ONLY_CSS
	);

	const problems = checkDirectory( dir );

	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /ships only 5 distinct/ );
	assert.match( problems[ 0 ], /sideEffects/ );
} );

test( 'a stylesheet just under the threshold still fails', () => {
	const dir = fixture();

	// The boundary, asserted from both sides, because a threshold nobody has
	// tested at its edge is a threshold nobody knows the value of.
	writeEntry(
		dir,
		'organizations',
		[ 'wp-element' ],
		'className:"dataviews-view-table"',
		dataviewsCss( 39 )
	);

	assert.equal( checkDirectory( dir ).length, 1 );
} );

test( 'a stylesheet exactly at the threshold passes', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'organizations',
		[ 'wp-element' ],
		'className:"dataviews-view-table"',
		dataviewsCss( 40 )
	);

	assert.deepEqual( checkDirectory( dir ), [] );
} );

test( 'rejects DataViews markup with no stylesheet emitted at all', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'organizations',
		[ 'wp-element' ],
		'className:"dataviews-view-table"',
		null
	);

	const problems = checkDirectory( dir );

	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /ships only 0 distinct/ );
} );

test( 'a bundle that does not use DataViews needs no DataViews styles', () => {
	const dir = fixture();

	// The negative half: the guard must not demand a stylesheet from the four
	// screens that never import DataViews.
	writeEntry( dir, 'packages', [ 'wp-element' ], 'nothing to see', null );

	assert.deepEqual( checkDirectory( dir ), [] );
} );

test( 'an unbuilt directory fails rather than passing silently', () => {
	const dir = fixture();

	const problems = checkDirectory( dir );

	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /no \.asset\.php files/ );
} );

test( 'a missing directory fails rather than passing silently', () => {
	const problems = checkDirectory(
		path.join( tmpdir(), 'aggr-bundle-does-not-exist' )
	);

	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /not readable/ );
} );

test( 'a directory entry named like a bundle does not crash the guard', () => {
	const dir = fixture();

	writeEntry( dir, 'organizations', [ 'wp-element' ], 'nothing', null );

	// readFileSync on a directory throws EISDIR. Writing this case for
	// check-navigation.mjs is what found that exact crash in that guard, so
	// this one asserts the scan skips non-files instead of dying on them.
	mkdirSync( path.join( dir, 'decoy.asset.php' ) );

	assert.deepEqual( checkDirectory( dir ), [] );
} );

test( 'a symlinked bundle is skipped rather than crashing', () => {
	const dir = fixture();

	writeEntry( dir, 'organizations', [ 'wp-element' ], 'nothing', null );

	// A symlink reports neither isFile() nor isDirectory() from readdir
	// withFileTypes, which is why the filter tests isFile() positively rather
	// than testing isDirectory() negatively.
	symlinkSync(
		path.join( dir, 'organizations.asset.php' ),
		path.join( dir, 'linked.asset.php' )
	);

	assert.deepEqual( checkDirectory( dir ), [] );
} );

test( 'checkEntry reports every problem on one entry, not just the first', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'organizations',
		[ 'wp-dataviews', 'wp-dataviews/build-style/style.css' ],
		'className:"dataviews-view-table"',
		THEME_ONLY_CSS
	);

	// Two bad handles and a missing stylesheet. A guard that returned early
	// would report one and hide the rest behind a second build.
	assert.equal( checkEntry( dir, 'organizations' ).length, 3 );
} );
