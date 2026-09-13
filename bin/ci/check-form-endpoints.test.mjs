/**
 * Tests for the `form.action` gate.
 *
 * The gate exists because a real browser and jsdom disagree.
 * `HTMLFormElement` is `[LegacyOverrideBuiltIns]`, so the hidden control named
 * `action` that every `admin-post.php` form carries shadows the property —
 * `form.action` returns the input, `fetch()` stringifies it, and the save goes
 * to a nonsense address. jsdom returns the URL either way, so the unit suite
 * cannot see a revert and only this lane can.
 *
 * Which makes the gate itself the single point of failure, and the reason it
 * gets its own test rather than being trusted. A guard that stops matching
 * reports success over code it is no longer reading, and every assertion below
 * is about the ways this one could go quiet: a pattern that no longer matches,
 * and a scan root that no longer resolves.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-form-endpoints.mjs' );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root holding the given files.
 *
 * @param {Record<string, string>} files Repository-relative path to contents.
 * @return {Promise<string>} The scan root.
 */
async function root( files ) {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-endpoints-' ) );

	roots.push( dir );

	for ( const [ relative, contents ] of Object.entries( files ) ) {
		const full = path.join( dir, relative );

		await mkdir( path.dirname( full ), { recursive: true } );
		await writeFile( full, contents, 'utf8' );
	}

	return dir;
}

/**
 * Runs the guard against a scan root.
 *
 * @param {string} dir Scan root.
 * @return {{status: number, output: string}}
 */
function run( dir ) {
	const result = spawnSync( process.execPath, [ CHECKER ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_FORM_ENDPOINT_SCAN_DIR: dir },
	} );

	return {
		status: result.status,
		output: `${ result.stdout ?? '' }${ result.stderr ?? '' }`,
	};
}

test( 'a module reading form.action is refused', async () => {
	const dir = await root( {
		'src/interactivity/save.ts':
			'const endpoint = form.action;\nawait fetch( endpoint );\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /read the action attribute, not the property/ );
	assert.match( output, /save\.ts:1/ );
} );

test( 'the indexed form of the same mistake is refused', async () => {
	const dir = await root( {
		'src/admin/inventory/form.tsx':
			"await fetch( document.forms[ 'aggr' ].action );\n",
	} );

	assert.equal( run( dir ).status, 1 );
} );

test( 'endpointOf is the sanctioned way to ask', async () => {
	const dir = await root( {
		'src/interactivity/save.ts':
			"import { endpointOf } from '@aggr/helpers';\n" +
			'await fetch( endpointOf( form ) );\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 0 );
	assert.match( output, /check-form-endpoints: ok \(1 files\)/ );
} );

/*
 * The failure four of this lane's siblings shipped with. A walker answers a
 * missing directory with an empty list, which is right for an optional
 * subdirectory and catastrophic for the roots: with no files scanned there are
 * no offenders, so "nothing reads form.action" is trivially true and the gate
 * prints ok over a renamed `src/`.
 */
test( 'a scan root with no modules fails rather than passing', async () => {
	const dir = await root( { 'readme.md': 'nothing to scan\n' } );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /scanned no files/ );
} );

/*
 * The count is the other half of that. A lane that says only "ok" cannot be
 * caught reading one file when it should be reading fifty, which is how a
 * narrowed directory list goes unnoticed.
 */
test( 'the lane reports how much it read', async () => {
	const dir = await root( {
		'src/interactivity/save.ts': 'const a = 1;\n',
		'src/interactivity/upload.ts': 'const b = 2;\n',
		'src/blocks-interactivity/ad-slot/view.js': 'const c = 3;\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 0 );
	assert.match( output, /ok \(3 files\)/ );
} );
