/**
 * Tests for the reproducibility diff.
 *
 * The lane it serves already failed once in a way nobody could diagnose: the
 * second build of one unchanged `dist/` dropped a single 3 KB file, and what CI
 * printed was `required file missing from the archive` — the verifier's
 * missing-file check, which runs before the digest comparison and reads as an
 * unbuilt file rather than as the reproducibility failure it was. Nothing
 * printed the two listings, so there was no evidence left behind.
 *
 * So the cases here are the three shapes that difference can take, and the one
 * that matters most is the third: two archives holding the same paths whose
 * bytes differ. A listing comparison cannot see it, and reporting "identical"
 * there would be worse than saying nothing.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, rm, writeFile, mkdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const SCRIPT = path.join( HERE, '..', 'release', 'compare-archives.sh' );

const dirs = [];

after( async () => {
	await Promise.all(
		dirs.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A working directory for one case.
 *
 * @return {Promise<string>} Absolute path.
 */
async function workspace() {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-archives-' ) );
	dirs.push( dir );

	return dir;
}

/**
 * Builds one zip from a map of relative path to contents.
 *
 * @param {string}                 dir      Working directory.
 * @param {string}                 name     Archive file name.
 * @param {Record<string, string>} contents Files to include.
 * @return {Promise<string>} Absolute archive path.
 */
async function archive( dir, name, contents ) {
	const staging = path.join( dir, name.replace( /\.zip$/, '' ) );

	for ( const [ relative, body ] of Object.entries( contents ) ) {
		const absolute = path.join( staging, relative );

		await mkdir( path.dirname( absolute ), { recursive: true } );
		await writeFile( absolute, body, 'utf8' );
	}

	const zip = path.join( dir, name );

	spawnSync( 'zip', [ '-qrX', zip, '.' ], { cwd: staging } );

	return zip;
}

/**
 * Runs the comparison.
 *
 * @param {string} first  First archive.
 * @param {string} second Second archive.
 * @return {{status: number, output: string}} Exit status and combined output.
 */
function run( first, second ) {
	const result = spawnSync( 'bash', [ SCRIPT, first, second ], {
		encoding: 'utf8',
	} );

	return {
		status: result.status ?? 1,
		output: `${ result.stdout ?? '' }${ result.stderr ?? '' }`,
	};
}

test( 'two builds of the same tree compare equal', async () => {
	const dir = await workspace();
	const files = { 'a/one.js': 'x', 'a/two.js': 'y' };

	const first = await archive( dir, 'first.zip', files );
	const second = await archive( dir, 'second.zip', files );

	const { status, output } = run( first, second );

	assert.equal( status, 0, output );
	assert.match( output, /compare-archives: identical/ );
} );

test( 'a path the second build lost is named', async () => {
	const dir = await workspace();

	const first = await archive( dir, 'first.zip', {
		'dist/interactivity/wizard.js': 'x',
		'dist/interactivity/dialog.js': 'y',
	} );
	const second = await archive( dir, 'second.zip', {
		'dist/interactivity/dialog.js': 'y',
	} );

	const { status, output } = run( first, second );

	assert.equal( status, 1 );
	assert.match( output, /missing from the second/ );
	assert.match( output, /dist\/interactivity\/wizard\.js/ );
	assert.match( output, /reproducibility failure, not a build failure/ );
} );

test( 'a path the second build gained is named too', async () => {
	const dir = await workspace();

	const first = await archive( dir, 'first.zip', { 'a/one.js': 'x' } );
	const second = await archive( dir, 'second.zip', {
		'a/one.js': 'x',
		'a/stray.log': 'z',
	} );

	const { status, output } = run( first, second );

	assert.equal( status, 1 );
	assert.match( output, /missing from the first/ );
	assert.match( output, /a\/stray\.log/ );
} );

test( 'the same paths with different bytes is still a difference', async () => {
	const dir = await workspace();

	const first = await archive( dir, 'first.zip', { 'a/one.js': 'x' } );
	const second = await archive( dir, 'second.zip', {
		'a/one.js': 'DIFFERENT',
	} );

	const { status, output } = run( first, second );

	assert.equal(
		status,
		1,
		'Equal listings must not be reported as equal archives.'
	);
	assert.match( output, /same paths/ );
	assert.match( output, /file contents/ );
} );

test( 'a missing archive is a usage error rather than a silent pass', async () => {
	const dir = await workspace();
	const first = await archive( dir, 'first.zip', { 'a/one.js': 'x' } );

	const { status, output } = run( first, path.join( dir, 'absent.zip' ) );

	assert.equal( status, 2 );
	assert.match( output, /no archive at/ );
} );

test( 'the wrong number of arguments is refused', () => {
	const result = spawnSync( 'bash', [ SCRIPT ], { encoding: 'utf8' } );

	assert.equal( result.status, 2 );
	assert.match( `${ result.stderr }`, /Usage:/ );
} );
