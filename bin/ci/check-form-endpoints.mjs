#!/usr/bin/env node
/**
 * Fails when a module posts to `form.action`.
 *
 * Every WordPress `admin-post.php` form carries a hidden control named
 * `action`, and `HTMLFormElement` is declared `[LegacyOverrideBuiltIns]` — so
 * in a real browser that control shadows the property and `form.action`
 * returns the input, not the URL. `fetch()` stringifies it to
 * `[object HTMLInputElement]`, the write goes to a nonsense relative address,
 * and the 404 that comes back is not JSON. What a reader sees is the page
 * reloading as though the script were not there at all.
 *
 * **jsdom does not reproduce it**, so the unit suite cannot guard this: it
 * returns the URL from `form.action` either way, and a revert would leave
 * `helpers.test.ts` passing. Only a real browser catches the behaviour, and
 * only this lane catches the code.
 *
 * Reads `endpointOf()` as the sanctioned way to ask, so adding a second
 * caller is free and going back to the property is not.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.env.AGGR_FORM_ENDPOINT_SCAN_DIR ?? process.cwd();
const dirs = [ 'src/interactivity', 'src/admin', 'src/blocks-interactivity' ];

/** @type {string[]} */
const offenders = [];
let scanned = 0;

/**
 * Every TypeScript and JavaScript file under one directory.
 *
 * @param {string} dir Directory to walk.
 * @return {string[]} Absolute file paths.
 */
function walk( dir ) {
	if ( ! fs.existsSync( dir ) ) {
		return [];
	}

	return fs
		.readdirSync( dir, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const full = path.join( dir, entry.name );

			if ( entry.isDirectory() ) {
				return walk( full );
			}

			return /\.(ts|tsx|js|jsx)$/.test( entry.name ) ? [ full ] : [];
		} );
}

dirs.forEach( ( relative ) => {
	walk( path.join( root, relative ) ).forEach( ( file ) => {
		scanned += 1;

		const source = fs.readFileSync( file, 'utf8' );

		source.split( '\n' ).forEach( ( line, index ) => {
			// Comments explain the trap; they are not the trap.
			if ( /^\s*(\/\/|\*|\/\*)/.test( line ) ) {
				return;
			}

			if ( /\bform\.action\b|\bforms\[[^\]]*\]\.action\b/.test( line ) ) {
				offenders.push(
					`${ path.relative( root, file ) }:${
						index + 1
					}: ${ line.trim() }`
				);
			}
		} );
	} );
} );

if ( 0 === scanned ) {
	console.error(
		'check-form-endpoints: scanned no files, so this lane proves nothing.'
	);
	process.exit( 1 );
}

if ( offenders.length > 0 ) {
	console.error(
		'check-form-endpoints: read the action attribute, not the property.\n'
	);
	offenders.forEach( ( line ) => console.error( `  ${ line }` ) );
	console.error(
		'\nUse endpointOf() from @aggr/helpers. A control named "action" shadows'
	);
	console.error(
		'the property in every real browser, and jsdom will not tell you.'
	);
	process.exit( 1 );
}

console.log( `check-form-endpoints: ok (${ scanned } files)` );
