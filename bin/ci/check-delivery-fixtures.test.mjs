/**
 * Tests for the delivery-fixture guard.
 *
 * The guard exists because twelve green tests once hid a delivery path that
 * served nothing, and every one of them was green for the same reason: it wrote
 * `'status' => Assignment_Rules::LIVE` into its own fixture. So the cases that
 * matter here are the two ways this lane could quietly stop working —
 * permitting a write it should catch, and catching prose it should not.
 *
 * The second is not hypothetical. The first draft failed against the real tree,
 * on the docblock in `AssignmentProjectionTest` that quotes the forbidden line
 * verbatim in order to explain the rule.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-delivery-fixtures.mjs' );

/** The paths the guard insists on finding, relative to its scan root. */
const PROTECTED = [
	'tests/php/Integration/AssignmentProjectionTest.php',
	'tests/e2e/seed-live-ad.php',
];

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root containing both protected files with the given contents.
 *
 * @param {Record<string, string>} contents Keyed by protected relative path.
 * @return {Promise<string>} Absolute scan root.
 */
async function root( contents ) {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-delivery-' ) );
	roots.push( dir );

	for ( const relative of PROTECTED ) {
		const absolute = path.join( dir, relative );

		await mkdir( path.dirname( absolute ), { recursive: true } );
		await writeFile( absolute, contents[ relative ] ?? '<?php\n', 'utf8' );
	}

	return dir;
}

/**
 * Runs the guard against one scan root.
 *
 * @param {string} dir Scan root.
 * @return {{status: number, output: string}} Exit status and combined output.
 */
function run( dir ) {
	const result = spawnSync( process.execPath, [ CHECKER ], {
		env: { ...process.env, AGGR_DELIVERY_SCAN_DIR: dir },
		encoding: 'utf8',
	} );

	return {
		status: result.status ?? 1,
		output: `${ result.stdout ?? '' }${ result.stderr ?? '' }`,
	};
}

test( 'a fixture that arranges its own live status is refused', async () => {
	const dir = await root( {
		'tests/php/Integration/AssignmentProjectionTest.php':
			"<?php\n$wpdb->insert( $table, array( 'status' => Assignment_Rules::LIVE ) );\n",
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /AssignmentProjectionTest\.php:2/ );
} );

test( 'the bare string form is refused too', async () => {
	const dir = await root( {
		'tests/e2e/seed-live-ad.php':
			"<?php\n$row = array( 'status' => 'live' );\n",
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /seed-live-ad\.php:2/ );
} );

test( 'asserting the status is what these files are for', async () => {
	const dir = await root( {
		'tests/php/Integration/AssignmentProjectionTest.php':
			"<?php\n$this->assertSame( Assignment_Rules::LIVE, $row['status'] );\n",
		'tests/e2e/seed-live-ad.php':
			"<?php\nif ( Assignment_Rules::LIVE !== $seeded['status'] ) {\n\tthrow new RuntimeException( 'not serving' );\n}\n",
	} );

	const { status, output } = run( dir );

	assert.equal( status, 0, output );
	assert.match(
		output,
		/check-delivery-fixtures: ok \(2 files, 2 protected\)/
	);
} );

test( 'prose quoting the forbidden line is not a violation', async () => {
	const dir = await root( {
		'tests/php/Integration/AssignmentProjectionTest.php':
			"<?php\n/**\n * Nothing here may write `'status' => Assignment_Rules::LIVE` itself.\n */\n// Nor may it: 'status' => 'live'\n",
	} );

	const { status, output } = run( dir );

	assert.equal( status, 0, output );
} );

test( 'a write hiding behind a trailing comment is still a write', async () => {
	const dir = await root( {
		'tests/e2e/seed-live-ad.php':
			"<?php\n$row = array( 'status' => Assignment_Rules::LIVE ); // convenience\n",
	} );

	const { status } = run( dir );

	assert.equal( status, 1 );
} );

test( 'a renamed protected file fails rather than passing over nothing', async () => {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-delivery-' ) );
	roots.push( dir );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match(
		output,
		/does not exist, so this lane is protecting nothing/
	);
} );
