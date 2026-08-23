import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { after, test } from 'node:test';

/**
 * The guard that keeps rewrite rules and their declared versions together.
 *
 * Worth testing rather than trusting, for the reason recorded in CLAUDE.md: a
 * guard whose matching stops working does not fail, it reports success over
 * code it is no longer reading. This one has two halves that can rot
 * independently — the fingerprint comparison and the scan for rules installed
 * outside a versioned set — so both are exercised in each direction.
 */

const checker = path.resolve(
	import.meta.dirname,
	'check-rewrite-version.php'
);
const realContract = path.resolve(
	import.meta.dirname,
	'rewrite-contract.json'
);

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A temporary directory, removed when the run ends.
 *
 * @return {Promise<string>} Absolute path.
 */
async function scratch() {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-rewrite-' ) );
	roots.push( dir );

	return dir;
}

/**
 * Writes a contract file built from the real one with one set replaced.
 *
 * Built from the real contract so a case only ever differs in the one field it
 * is about; a hand-written fixture drifts from the shape the guard reads.
 *
 * @param {(contract: Record<string, any>) => void} mutate Applied in place.
 * @return {Promise<string>} Absolute path to the fixture contract.
 */
async function contractWith( mutate ) {
	const { readFile } = await import( 'node:fs/promises' );
	const contract = JSON.parse( await readFile( realContract, 'utf8' ) );

	mutate( contract );

	const file = path.join( await scratch(), 'rewrite-contract.json' );
	await writeFile( file, JSON.stringify( contract, null, '\t' ), 'utf8' );

	return file;
}

/**
 * Runs the guard.
 *
 * @param {Record<string,string>} env Extra environment.
 * @return {{ status: number, output: string }} Exit code and combined output.
 */
function run( env = {} ) {
	const result = spawnSync( 'php', [ checker ], {
		encoding: 'utf8',
		env: { ...process.env, ...env },
	} );

	return {
		status: result.status,
		output: `${ result.stdout ?? '' }${ result.stderr ?? '' }`,
	};
}

test( 'passes against the recorded contract', () => {
	const { status, output } = run();

	assert.equal( status, 0, output );
	assert.match( output, /ok \(2 rule sets\)/ );
} );

test( 'fails when the rules no longer hash to the recorded value', async () => {
	const file = await contractWith( ( contract ) => {
		contract.portal.history[ 0 ].hash = 'a'.repeat( 64 );
	} );

	const { status, output } = run( { AGGR_REWRITE_CONTRACT: file } );

	assert.equal( status, 1 );
	assert.match( output, /portal: the rules changed/ );
	// The message has to carry the replacement entry, or the developer it
	// stops has to work out the hash by hand and will edit the old one.
	assert.match( output, /"version": 3, "hash": "[0-9a-f]{64}"/ );
} );

test( 'fails when the constant moves without a recorded entry', async () => {
	const file = await contractWith( ( contract ) => {
		contract.portal.history[ 0 ].version = 1;
	} );

	const { status, output } = run( { AGGR_REWRITE_CONTRACT: file } );

	assert.equal( status, 1 );
	assert.match( output, /newest recorded version is 1/ );
} );

test( 'refuses a history that does not increase', async () => {
	const file = await contractWith( ( contract ) => {
		contract.delivery.history = [
			{ ...contract.delivery.history[ 0 ], version: 3 },
			contract.delivery.history[ 0 ],
		];
	} );

	const { status, output } = run( { AGGR_REWRITE_CONTRACT: file } );

	assert.equal( status, 1 );
	assert.match( output, /append-only/ );
} );

test( 'refuses a set with no recorded history', async () => {
	const file = await contractWith( ( contract ) => {
		contract.delivery.history = [];
	} );

	const { status, output } = run( { AGGR_REWRITE_CONTRACT: file } );

	assert.equal( status, 1 );
	assert.match( output, /delivery: no history recorded/ );
} );

test( 'refuses a malformed history entry', async () => {
	const file = await contractWith( ( contract ) => {
		contract.portal.history[ 0 ].hash = 'not-a-hash';
	} );

	const { status, output } = run( { AGGR_REWRITE_CONTRACT: file } );

	assert.equal( status, 1 );
	assert.match( output, /integer version and a sha256 hash/ );
} );

test( 'refuses a missing contract rather than reporting success', async () => {
	const { status, output } = run( {
		AGGR_REWRITE_CONTRACT: path.join( await scratch(), 'absent.json' ),
	} );

	assert.equal( status, 1 );
	assert.match( output, /missing or is not valid JSON/ );
} );

test( 'catches a rule installed outside a versioned set', async () => {
	const dir = await scratch();

	await writeFile(
		path.join( dir, 'class-stray.php' ),
		"<?php\nadd_rewrite_rule( '^stray/?$', 'index.php', 'top' );\n",
		'utf8'
	);

	const { status, output } = run( { AGGR_REWRITE_SCAN_DIR: dir } );

	assert.equal( status, 1 );
	assert.match( output, /class-stray\.php: installs a rewrite rule/ );
} );

test( 'reads code as code, not as text', async () => {
	const dir = await scratch();

	// Prose and a method of the same name. A grep-based guard fails both of
	// these, and a guard that fires on correct code is one people route around.
	await writeFile(
		path.join( dir, 'class-innocent.php' ),
		[
			'<?php',
			'/**',
			' * Installed with add_rewrite_rule() by the router.',
			' */',
			'final class Innocent {',
			'\tpublic function add_rewrite_rule(): void {}',
			'\tpublic function go( $rewrite ): void {',
			"\t\t$rewrite->add_rewrite_rule( '^x/?$' );",
			'\t}',
			'}',
			'',
		].join( '\n' ),
		'utf8'
	);

	const { status, output } = run( { AGGR_REWRITE_SCAN_DIR: dir } );

	assert.equal( status, 0, output );
} );
