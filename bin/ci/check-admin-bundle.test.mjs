/**
 * Tests for the admin bundle guard.
 *
 * A guard that stops matching does not fail — it reports success over output it
 * is no longer reading. So each case here builds a fixture that is *wrong* in
 * one specific way and asserts the guard says so, and the sound fixture asserts
 * silence. Both halves matter: a guard that never passes is as useless as one
 * that never fails.
 *
 * The guard is run as a subprocess rather than imported, matching
 * `check-navigation.test.mjs`. What the build actually invokes is a command, so
 * what these assert is the command's contract — exit code and message. An
 * imported function can keep returning the right array long after the file
 * stops exiting non-zero, and the lane only ever sees the exit code.
 *
 * The two failing shapes are not hypothetical. Both were produced by the real
 * build while converting the organizations screen to DataViews, and neither
 * caused a webpack error.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

const GUARD = path.resolve( import.meta.dirname, 'check-admin-bundle.mjs' );

/**
 * Runs the guard against a fixture directory.
 *
 * @param {string} dir Directory to scan.
 * @return {{status: number, stdout: string, stderr: string}} Result.
 */
function run( dir ) {
	const result = spawnSync( process.execPath, [ GUARD ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_BUNDLE_DIR: dir },
	} );

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

/**
 * Writes a fake built entry.
 *
 * @param {string}      dir  Directory to write into.
 * @param {string}      name Entry name.
 * @param {string[]}    deps Dependency handles for the .asset.php.
 * @param {string}      js   Contents of the .js bundle.
 * @param {string|null} css  Contents of the .css bundle, or null to omit it.
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
 * stylesheet was missing entirely, so it is the fixture for that case.
 */
const THEME_ONLY_CSS = [
	'.dataviews-wrapper{background:#fff}',
	'.dataviews-view-table td{padding:16px}',
	'.dataviews-view-table th{padding:16px}',
	'.dataviews-filters__container{padding:12px}',
	'.dataviews-action-modal{max-width:60ch}',
	'.dataviews-view-table__primary-column-content{font-weight:600}',
].join( '\n' );

/** JS that only a bundle with DataViews compiled into it would contain. */
const COMPILES_DATAVIEWS = 'className:"dataviews-view-table"';

/** JS that reads the shared global, as a consuming screen's bundle does. */
const USES_SHARED = 'const{DataViews}=window.aggrDataViews;';

const SHARED_HANDLE = 'aggr-dataviews';

test( 'a shared bundle and its consumer pass together', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'dataviews',
		[ 'wp-element', 'wp-components' ],
		COMPILES_DATAVIEWS,
		dataviewsCss( 166 )
	);
	writeEntry(
		dir,
		'organizations',
		[ 'wp-element', SHARED_HANDLE ],
		USES_SHARED,
		THEME_ONLY_CSS
	);
	writeEntry( dir, 'packages', [ 'wp-element' ], 'no dataviews here', null );

	const { status, stdout } = run( dir );

	assert.equal( status, 0 );
	// The count, not just "ok": a scan that quietly stopped reading one of the
	// three entries would still print ok.
	assert.match( stdout, /ok \(3 entries\)/ );
} );

test( 'a screen that compiles its own copy is refused', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'dataviews',
		[ 'wp-element' ],
		COMPILES_DATAVIEWS,
		dataviewsCss( 166 )
	);
	// The regression this exists for: it works, and costs 490 KB per screen.
	writeEntry(
		dir,
		'organizations',
		[ 'wp-element' ],
		COMPILES_DATAVIEWS,
		dataviewsCss( 166 )
	);

	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.match( stderr, /compiles @wordpress\/dataviews into itself/ );
} );

test( 'a consumer that does not declare the shared handle is refused', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'dataviews',
		[ 'wp-element' ],
		COMPILES_DATAVIEWS,
		dataviewsCss( 166 )
	);
	writeEntry( dir, 'organizations', [ 'wp-element' ], USES_SHARED, null );

	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.match( stderr, /does not depend on "aggr-dataviews"/ );
} );

test( 'rejects a subpath import that escaped externalisation', () => {
	const dir = fixture();

	// The exact shape the real build emitted before the matcher handled
	// subpaths: a "handle" that is really a file path.
	writeEntry(
		dir,
		'organizations',
		[ 'wp-element', 'wp-dataviews/build-style/style.css' ],
		COMPILES_DATAVIEWS,
		dataviewsCss( 166 )
	);

	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.match( stderr, /is not a script handle/ );
	assert.match( stderr, /build-style/ );
	// The message must point at a symbol that exists, or it costs the next
	// reader the search this one was written to save.
	assert.match(
		stderr,
		/BUNDLED_PACKAGES in bin\/ci\/bundled-packages\.mjs/
	);
} );

test( 'rejects a package WordPress does not register', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'organizations',
		[ 'wp-element', 'wp-dataviews' ],
		COMPILES_DATAVIEWS,
		dataviewsCss( 166 )
	);

	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.match( stderr, /not registered by WordPress/ );
} );

test( 'the shared-package registry drives the handles and the rewrite', async () => {
	// Not a restatement of the constants: this asserts every file that acts on
	// the registry agrees with it, which is the whole reason it is one module.
	const {
		SHARED_PACKAGES,
		forbiddenHandles,
		sharedHandles,
		sharedPackageFor,
	} = await import( './bundled-packages.mjs' );

	const dataviews = SHARED_PACKAGES.find(
		( pkg ) => '@wordpress/dataviews' === pkg.request
	);

	assert.ok( dataviews );
	assert.equal( dataviews.global, 'aggrDataViews' );
	assert.equal( dataviews.handle, 'aggr-dataviews' );

	// The handle core does not have must stay forbidden; the one this plugin
	// ships must be the one screens are pointed at.
	assert.deepEqual( forbiddenHandles(), [ 'wp-dataviews' ] );
	assert.deepEqual( sharedHandles(), [ 'aggr-dataviews' ] );

	// Subpaths count, which is the bug that shipped the bogus handle.
	assert.equal( sharedPackageFor( '@wordpress/dataviews' ), dataviews );
	assert.equal(
		sharedPackageFor( '@wordpress/dataviews/build-style/style.css' ),
		dataviews
	);
	// And the near-miss must not: a package merely sharing the prefix is a
	// different package.
	assert.equal( sharedPackageFor( '@wordpress/dataviews-extra' ), null );
	assert.equal( sharedPackageFor( '@wordpress/components' ), null );
} );

test( 'rejects a shared bundle shipped without DataViews styles', () => {
	const dir = fixture();

	// The tree-shaken stylesheet: JS carries the class, CSS exists but holds
	// only theme-layer rules, because "sideEffects": false deleted the import.
	writeEntry(
		dir,
		'dataviews',
		[ 'wp-element' ],
		COMPILES_DATAVIEWS,
		THEME_ONLY_CSS
	);

	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.match( stderr, /ships only 5 distinct/ );
	assert.match( stderr, /sideEffects/ );
} );

test( 'a stylesheet just under the threshold still fails', () => {
	const dir = fixture();

	// The boundary, asserted from both sides, because a threshold nobody has
	// tested at its edge is a threshold nobody knows the value of.
	writeEntry(
		dir,
		'dataviews',
		[ 'wp-element' ],
		COMPILES_DATAVIEWS,
		dataviewsCss( 39 )
	);

	assert.equal( run( dir ).status, 1 );
} );

test( 'a stylesheet exactly at the threshold passes', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'dataviews',
		[ 'wp-element' ],
		COMPILES_DATAVIEWS,
		dataviewsCss( 40 )
	);

	assert.equal( run( dir ).status, 0 );
} );

test( 'rejects DataViews markup with no stylesheet emitted at all', () => {
	const dir = fixture();

	writeEntry( dir, 'dataviews', [ 'wp-element' ], COMPILES_DATAVIEWS, null );

	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.match( stderr, /ships only 0 distinct/ );
} );

test( 'a bundle that does not use DataViews needs no DataViews styles', () => {
	const dir = fixture();

	// The negative half: the guard must not demand a stylesheet from the four
	// screens that never import DataViews.
	writeEntry( dir, 'packages', [ 'wp-element' ], 'nothing to see', null );

	assert.equal( run( dir ).status, 0 );
} );

test( 'an unbuilt directory fails rather than passing silently', () => {
	const { status, stderr } = run( fixture() );

	assert.equal( status, 1 );
	assert.match( stderr, /no \.asset\.php files/ );
} );

test( 'a missing directory fails rather than passing silently', () => {
	const { status, stderr } = run(
		path.join( tmpdir(), 'aggr-bundle-does-not-exist' )
	);

	assert.equal( status, 1 );
	assert.match( stderr, /not readable/ );
} );

test( 'a directory entry named like a bundle does not crash the guard', () => {
	const dir = fixture();

	writeEntry( dir, 'organizations', [ 'wp-element' ], 'nothing', null );

	// readFileSync on a directory throws EISDIR. Writing this case for
	// check-navigation.mjs is what found that exact crash in that guard, so
	// this one asserts the scan skips non-files instead of dying on them.
	mkdirSync( path.join( dir, 'decoy.asset.php' ) );

	const { status, stdout } = run( dir );

	assert.equal( status, 0 );
	assert.match( stdout, /ok \(1 entries\)/ );
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

	const { status, stdout } = run( dir );

	assert.equal( status, 0 );
	assert.match( stdout, /ok \(1 entries\)/ );
} );

test( 'reports every problem on one entry, not just the first', () => {
	const dir = fixture();

	writeEntry(
		dir,
		'organizations',
		[ 'wp-dataviews', 'wp-dataviews/build-style/style.css' ],
		COMPILES_DATAVIEWS,
		THEME_ONLY_CSS
	);

	// Two bad handles and a missing stylesheet. A guard that returned early
	// would report one and hide the rest behind a second build.
	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.equal( stderr.trim().split( '\n' ).length, 3 );
} );
