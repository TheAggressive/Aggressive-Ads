/**
 * The catalog-coverage guard.
 *
 * Driven as a process against a temporary `languages/` directory, because what
 * matters is the exit code CI reads, not a function's return value.
 *
 * The cases are the ways this guard could report success while blind: an empty
 * POT, a catalog missing strings, and a catalog whose obsolete entries look
 * like coverage.
 */

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const SCRIPT = fileURLToPath(
	new URL( './check-catalog-drift.mjs', import.meta.url )
);

const HEADER = [
	'msgid ""',
	'msgstr ""',
	'"Content-Type: text/plain; charset=UTF-8\\n"',
	'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
	'',
].join( '\n' );

/**
 * Runs the guard in a throwaway working directory.
 *
 * @param {Record<string, string>} files Filename in languages/ → contents.
 * @return {{ status: number, out: string }}
 */
function run( files ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'aggr-drift-' ) );
	fs.mkdirSync( path.join( dir, 'languages' ) );

	for ( const [ name, body ] of Object.entries( files ) ) {
		fs.writeFileSync( path.join( dir, 'languages', name ), body );
	}

	try {
		const out = execFileSync( process.execPath, [ SCRIPT ], {
			cwd: dir,
			encoding: 'utf8',
			stdio: 'pipe',
		} );
		return { status: 0, out };
	} catch ( err ) {
		return {
			status: err.status,
			out: `${ err.stdout ?? '' }${ err.stderr ?? '' }`,
		};
	} finally {
		fs.rmSync( dir, { recursive: true, force: true } );
	}
}

const pot = ( ...msgids ) =>
	HEADER + msgids.map( ( m ) => `msgid "${ m }"\nmsgstr ""\n` ).join( '\n' );

test( 'a catalog covering the POT passes, and says how many', () => {
	const { status, out } = run( {
		'aggressive-ads.pot': pot( 'Save changes', 'Delete' ),
		'aggressive-ads-de_DE.po': pot( 'Save changes', 'Delete' ),
	} );

	assert.equal( status, 0 );
	assert.match( out, /covers all 2 strings/ );
	assert.match( out, /Catalog coverage OK \(1 catalogs\)/ );
} );

test( 'a catalog missing a string fails, and names the count', () => {
	const { status, out } = run( {
		'aggressive-ads.pot': pot( 'Save changes', 'Delete', 'Archive' ),
		'aggressive-ads-de_DE.po': pot( 'Save changes' ),
	} );

	assert.equal( status, 1, 'a 2-string gap did not fail the build' );
	assert.match( out, /2 string\(s\) in the POT are absent/ );
	assert.match( out, /missing: Delete/ );
	assert.match( out, /pnpm i18n:sync/ );
} );

test( 'an obsolete entry is not counted as coverage', () => {
	// msgmerge parks a dropped string as #~. Counting those as covered is how
	// a catalog looks complete while the screen renders English.
	const { status, out } = run( {
		'aggressive-ads.pot': pot( 'Save changes', 'Archive' ),
		'aggressive-ads-de_DE.po':
			pot( 'Save changes' ) +
			'\n#~ msgid "Archive"\n#~ msgstr "Archivieren"\n',
	} );

	assert.equal( status, 1 );
	assert.match( out, /1 string\(s\) in the POT are absent/ );
} );

test( 'a live string the POT no longer has is reported too', () => {
	const { status, out } = run( {
		'aggressive-ads.pot': pot( 'Save changes' ),
		'aggressive-ads-de_DE.po': pot( 'Save changes', 'Removed feature' ),
	} );

	assert.equal( status, 1 );
	assert.match( out, /1 live string\(s\) are no longer in the POT/ );
} );

test( 'an empty POT fails rather than passing vacuously', () => {
	// The guard's own blind spot: zero strings means every catalog trivially
	// covers everything. bin/ci guards have shipped in that state before.
	const { status, out } = run( {
		'aggressive-ads.pot': HEADER,
		'aggressive-ads-de_DE.po': HEADER,
	} );

	assert.equal( status, 1, 'an empty POT passed' );
	assert.match( out, /cannot pass vacuously/ );
} );

test( 'a missing POT fails with an instruction', () => {
	const { status, out } = run( { 'aggressive-ads-de_DE.po': HEADER } );

	assert.equal( status, 1 );
	assert.match( out, /pnpm i18n:pot/ );
} );
