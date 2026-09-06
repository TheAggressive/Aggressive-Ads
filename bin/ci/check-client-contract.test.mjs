/**
 * Tests for the client-contract guard.
 *
 * The guard exists because a write half and a read half that never meet
 * will keep shipping as two complete, green pieces. So the cases that
 * matter here are the ways this lane could quietly stop working —
 * permitting a key with no reader, permitting a sequence nobody sends,
 * and passing over a tree whose files have been renamed.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-client-contract.mjs' );

const FILES = {
	php: 'inc/Domain/class-slot-options.php',
	controller: 'inc/REST/class-fill-controller.php',
	view: 'src/blocks-interactivity/ad-slot/view.js',
	fill: 'src/blocks-interactivity/ad-slot/fill.js',
	empty: 'src/blocks-interactivity/ad-slot/empty.js',
	rotation: 'src/blocks-interactivity/ad-slot/rotation.js',
};

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A passing tree: every key has a reader, the sequence is written, no E2E.
 *
 * @param {Partial<Record<keyof typeof FILES, string>>} overrides File contents.
 * @return {Promise<string>} Absolute scan root.
 */
async function root( overrides = {} ) {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-client-' ) );
	roots.push( dir );

	const contents = {
		[ FILES.php ]: `<?php
	public function resolved_context( Refresh_Policy $policy ): array {
		return array(
			'rotate'            => true,
			'rotateSeconds'     => 30,
			'maxRefreshes'      => 6,
			'collapseWhenEmpty' => true,
		);
	}
`,
		/*
		 * Shaped like the real controller in the way the guard reads it.
		 *
		 * The parameter list used to be irrelevant here because the check
		 * hardcoded `n`. It derives them from the `args` declaration now, so a
		 * fixture without one exercises nothing — and said so, loudly, the
		 * first time this ran.
		 */
		[ FILES.controller ]:
			"<?php\n'args' => array(\n\t'slot' => array(\n\t\t'type' => 'string',\n\t),\n\t'n' => array(\n\t\t'type' => 'integer',\n\t),\n\t'w' => array(\n\t\t'type' => 'integer',\n\t),\n),\n$sequence = (int) $request->get_param( 'n' );\n$viewport = (int) $request->get_param( 'w' );\n",
		[ FILES.view ]:
			'const on = context.rotate;\nconst s = context.rotateSeconds;\nconst cap = rotationCap( context.maxRefreshes );\nawait fillSlot( root, rotations );\n',
		[ FILES.fill ]:
			"export const fillSlot = async ( root, sequence = 0 ) => {\n\tconst n = sequence;\n\tendpoint.searchParams.set( 'n', String( n ) );\n\tendpoint.searchParams.set( 'w', String( viewportWidth() ) );\n};\n",
		[ FILES.empty ]:
			'export const collapses = ( context ) => false !== context?.collapseWhenEmpty;\n',
		[ FILES.rotation ]:
			'export const rotationCap = ( requested ) => Math.min( 100, requested );\n',
		...overrides,
	};

	for ( const [ relative, body ] of Object.entries( contents ) ) {
		const absolute = path.join( dir, relative );

		await mkdir( path.dirname( absolute ), { recursive: true } );
		await writeFile( absolute, body, 'utf8' );
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
		env: { ...process.env, AGGR_CLIENT_CONTRACT_SCAN_DIR: dir },
		encoding: 'utf8',
	} );

	return {
		status: result.status ?? 1,
		output: `${ result.stdout ?? '' }${ result.stderr ?? '' }`,
	};
}

test( 'a context key with no client reader is refused', async () => {
	const dir = await root( {
		[ FILES.view ]:
			'const on = context.rotate;\nconst s = context.rotateSeconds;\nawait fillSlot( root, rotations );\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /context\.maxRefreshes/ );
} );

test( 'a docblock quoting the key is not a reader', async () => {
	const dir = await root( {
		[ FILES.view ]:
			'/** context.maxRefreshes is the publisher cap. */\nconst on = context.rotate;\nconst s = context.rotateSeconds;\nawait fillSlot( root, rotations );\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1, output );
	assert.match( output, /context\.maxRefreshes/ );
} );

test( 'a client that never sends n is refused', async () => {
	const dir = await root( {
		[ FILES.fill ]:
			'export const fillSlot = async ( root ) => {\n\tawait fetch( url );\n};\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /searchParams\.set/ );
} );

test( 'a dead maxRefreshes identifier without rotationCap is refused', async () => {
	const dir = await root( {
		[ FILES.view ]:
			'const on = context.rotate;\nconst s = context.rotateSeconds;\nconst unused = context.maxRefreshes;\nif ( rotations >= MAX_ROTATIONS ) {}\nawait fillSlot( root, rotations );\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1, output );
	assert.match( output, /rotationCap/ );
} );

test( 'fillSlot called without the incrementing counter is refused', async () => {
	const dir = await root( {
		[ FILES.view ]:
			'const on = context.rotate;\nconst s = context.rotateSeconds;\nconst cap = context.maxRefreshes;\nawait fillSlot( root );\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /rotations/ );
} );

test( 'an e2e rotate request without a granted policy is refused', async () => {
	const dir = await root();

	await mkdir( path.join( dir, 'tests/e2e' ), { recursive: true } );
	await writeFile(
		path.join( dir, 'tests/e2e/seed-live-ad.php' ),
		'<?php\n$block = \'<!-- wp:aggr/ad-slot {"slot":"x","rotate":true} /-->\';\n',
		'utf8'
	);

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /set_refresh_policy/ );
} );

test( 'a grant in a different seed does not cover a rotate in this one', async () => {
	const dir = await root();

	await mkdir( path.join( dir, 'tests/e2e' ), { recursive: true } );
	await writeFile(
		path.join( dir, 'tests/e2e/seed-mappings.php' ),
		'<?php\n$placements->set_refresh_policy( $id, true, 1, 100 );\n',
		'utf8'
	);
	await writeFile(
		path.join( dir, 'tests/e2e/seed-live-ad.php' ),
		'<?php\n$block = \'<!-- wp:aggr/ad-slot {"slot":"x","rotate":true} /-->\';\n',
		'utf8'
	);

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /seed-live-ad\.php/ );
} );

test( 'the honest tree passes and says how much it read', async () => {
	const dir = await root();

	await mkdir( path.join( dir, 'tests/e2e' ), { recursive: true } );
	await writeFile(
		path.join( dir, 'tests/e2e/seed-live-ad.php' ),
		'<?php\n$placements->set_refresh_policy( $id, true, 1, 100 );\n$block = \'{"rotate":true}\';\n',
		'utf8'
	);

	const { status, output } = run( dir );

	assert.equal( status, 0, output );
	assert.match( output, /check-client-contract: ok \(4 context keys/ );
} );

test( 'a renamed protected file fails rather than passing over nothing', async () => {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-client-' ) );
	roots.push( dir );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match(
		output,
		/does not exist, so this lane is protecting nothing/
	);
} );
