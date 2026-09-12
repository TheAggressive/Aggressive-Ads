#!/usr/bin/env node
/**
 * Fails when a locale catalog no longer covers the POT.
 *
 * The POT drift check compares the POT to the source. Nothing compared the
 * catalogs to the POT, so es_ES, fr_FR and it_IT sat 350 strings behind for as
 * long as nobody looked — every one of them a screen that renders English —
 * and `pnpm ci:i18n` was green throughout. A catalog falling behind is exactly
 * the failure that is invisible from the outside, because a missing msgid and
 * an untranslated msgid both just show English.
 *
 * Compares msgid sets rather than running msgmerge and diffing, so the POT's
 * creation date cannot make this fail on a catalog that is actually current.
 *
 * An obsolete (`#~`) entry is not "covered": msgmerge parked it there because
 * the source dropped the string. Only live entries count.
 */

import fs from 'node:fs';
import path from 'node:path';

import { parsePo } from './po.mjs';

const languagesDir = path.join( process.cwd(), 'languages' );
const potPath = path.join( languagesDir, 'aggressive-ads.pot' );

if ( ! fs.existsSync( potPath ) ) {
	console.error(
		'[i18n] No POT at languages/aggressive-ads.pot. Run: pnpm i18n:pot'
	);
	process.exit( 1 );
}

/**
 * The live msgid keys of a catalog or template.
 *
 * @param {string} file Path to a .po or .pot file.
 * @return {Set<string>} `msgctxtmsgid` keys.
 */
function keysOf( file ) {
	const keys = new Set();

	for ( const entry of parsePo( fs.readFileSync( file, 'utf8' ) ).entries ) {
		if ( ! entry.msgid || entry.obsolete ) {
			continue;
		}
		keys.add( `${ entry.msgctxt ?? '' }${ entry.msgid }` );
	}

	return keys;
}

const potKeys = keysOf( potPath );

if ( 0 === potKeys.size ) {
	console.error(
		'[i18n] The POT parsed to zero strings. This check cannot pass vacuously.'
	);
	process.exit( 1 );
}

const catalogs = fs
	.readdirSync( languagesDir )
	.filter( ( f ) => f.endsWith( '.po' ) )
	.sort();

let failed = false;

for ( const file of catalogs ) {
	const keys = keysOf( path.join( languagesDir, file ) );
	const missing = [ ...potKeys ].filter( ( k ) => ! keys.has( k ) );
	const extra = [ ...keys ].filter( ( k ) => ! potKeys.has( k ) );

	if ( 0 === missing.length && 0 === extra.length ) {
		console.log(
			`[i18n] ${ file }: covers all ${ potKeys.size } strings.`
		);
		continue;
	}

	failed = true;
	console.error(
		`[i18n] ${ file }: ${ missing.length } string(s) in the POT are absent, ` +
			`${ extra.length } live string(s) are no longer in the POT.`
	);

	for ( const key of missing.slice( 0, 5 ) ) {
		console.error( `         missing: ${ key.split( '' )[ 1 ] }` );
	}
}

if ( failed ) {
	console.error( '\n[i18n] Run `pnpm i18n:sync` and commit the result.' );
	process.exit( 1 );
}

console.log( `[i18n] Catalog coverage OK (${ catalogs.length } catalogs).` );
