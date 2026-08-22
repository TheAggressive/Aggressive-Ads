import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { after, test } from 'node:test';

/**
 * The guard that keeps navigation sinks out of src/.
 *
 * Worth testing rather than trusting: a guard whose patterns stop matching goes
 * on reporting success, so its failure mode is silence. CodeQL found the
 * original js/xss-through-dom; this lane is what stops the next one reaching a
 * scan at all, and it only helps while its sink patterns still match.
 */

const roots = [];
const checker = path.resolve( import.meta.dirname, 'check-navigation.mjs' );

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root containing a gateway plus whatever files a case needs.
 *
 * @param {Record<string,string>} files Relative path to contents.
 * @return {Promise<string>} Absolute scan root.
 */
async function fixture( files ) {
	const root = await mkdtemp( path.join( tmpdir(), 'aggr-nav-' ) );
	roots.push( root );

	for ( const [ relative, contents ] of Object.entries( files ) ) {
		const full = path.join( root, relative );
		await mkdir( path.dirname( full ), { recursive: true } );
		await writeFile( full, contents, 'utf8' );
	}

	return root;
}

/** The gateway the guard exempts. */
const GATEWAY = {
	'admin/shared/navigate.ts': 'window.location.href = href;\n',
};

function run( root ) {
	const env = { ...process.env, AGGR_NAVIGATION_SCAN_DIR: root };
	delete env.NODE_TEST_CONTEXT;

	return spawnSync( process.execPath, [ checker ], {
		env,
		encoding: 'utf8',
	} );
}

test( 'clean source passes', async () => {
	const root = await fixture( {
		...GATEWAY,
		'admin/app.tsx': 'export const go = () => navigateSameOrigin( url );\n',
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'a location.href assignment is refused', async () => {
	const root = await fixture( {
		...GATEWAY,
		'admin/app.tsx':
			'export const go = ( u ) => { window.location.href = u; };\n',
	} );

	const result = run( root );

	assert.equal( result.status, 1 );
	assert.match( result.stderr, /location\.href assignment/ );
	assert.match( result.stderr, /admin\/app\.tsx:1/ );
} );

test( 'location.assign and location.replace are refused', async () => {
	for ( const call of [ 'assign', 'replace' ] ) {
		const root = await fixture( {
			...GATEWAY,
			'admin/app.tsx': `export const go = ( u ) => window.location.${ call }( u );\n`,
		} );

		const result = run( root );

		assert.equal( result.status, 1, `location.${ call }() must fail` );
		assert.match( result.stderr, new RegExp( `location\\.${ call }` ) );
	}
} );

test( 'reading location.href is allowed', async () => {
	const root = await fixture( {
		...GATEWAY,
		'admin/app.tsx':
			'export const origin = new URL( window.location.href ).origin;\n',
	} );

	assert.equal(
		run( root ).status,
		0,
		'A read is not a navigation; flagging it would push people to work around the guard.'
	);
} );

test( 'an equality comparison is not an assignment', async () => {
	const root = await fixture( {
		...GATEWAY,
		'admin/app.tsx':
			'export const here = ( u ) => window.location.href === u;\n',
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'the gateway itself may navigate', async () => {
	const root = await fixture( GATEWAY );

	assert.equal( run( root ).status, 0 );
} );

test( 'a missing gateway fails rather than exempting nothing', async () => {
	const root = await fixture( {
		'admin/app.tsx': 'export const x = 1;\n',
	} );

	const result = run( root );

	assert.equal( result.status, 1 );
	assert.match( result.stderr, /does not exist/ );
} );

test( 'scanning nothing is refused rather than reported as clean', async () => {
	const root = await fixture( {} );

	const result = run( root );

	assert.equal( result.status, 1 );
	assert.match( result.stderr, /scanned no files/ );
} );

test( 'test fixtures under __tests__ are ignored', async () => {
	const root = await fixture( {
		...GATEWAY,
		'admin/__tests__/app.test.tsx': 'window.location.href = "/x";\n',
	} );

	assert.equal( run( root ).status, 0 );
} );
