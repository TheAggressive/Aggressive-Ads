#!/usr/bin/env node
/**
 * Fail when a locale catalog drops or invents a placeholder.
 *
 * `msgfmt -c` already rejects a printf mismatch, but only for entries gettext
 * has flagged `#, php-format` — and it knows nothing at all about brace tokens
 * like `{percent}`, which this plugin substitutes with str_replace(). A catalog
 * translating `Save {percent}%` to `Économisez {pourcentage} %` compiles
 * cleanly and then prints the literal token on a product badge.
 *
 * Usage:
 *   node bin/i18n/lint-placeholders.mjs [file.po …]
 *
 * With no arguments every catalog in languages/ is checked.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	extractPlaceholders,
	parsePo,
	placeholderSourceFor,
	placeholdersIntact,
} from './po.mjs';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const LANGUAGES = path.resolve( __dirname, '../..', 'languages' );

/**
 * Placeholder problems in one catalog.
 *
 * Untranslated (empty) and fuzzy entries are skipped: they are drafts by
 * definition, and gettext falls back to the source string for them, so a
 * placeholder they have not got yet cannot reach a page.
 *
 * @param {string} content Catalog source.
 * @returns {string[]} Human-readable problems.
 */
export function findPlaceholderMismatches( content ) {
	const { entries } = parsePo( content );
	const problems = [];

	for ( const entry of entries ) {
		// Obsolete entries are commented out: gettext never reads them, so
		// they cannot put a broken placeholder on a page.
		if ( entry.obsolete || entry.flags?.has( 'fuzzy' ) ) {
			continue;
		}

		for ( const [ key, msgstr ] of Object.entries( entry.msgstrs ?? {} ) ) {
			if ( msgstr === '' ) {
				continue;
			}

			const source = placeholderSourceFor( entry, key );

			// The verdict comes from po.mjs so that the translator, the
			// resume step and this lint cannot drift apart on what a
			// placeholder mismatch is; the lists below are only for the
			// message. They did drift once, and the weaker definition
			// upstream let the stronger one here fail every run.
			if ( placeholdersIntact( source, msgstr ) ) {
				continue;
			}

			const expected = extractPlaceholders( source );
			const actual = extractPlaceholders( msgstr );

			problems.push(
				`  msgid "${ entry.msgid }"\n` +
					`    expected placeholders: ${
						expected.join( ', ' ) || '(none)'
					}\n` +
					`    found in translation:  ${
						actual.join( ', ' ) || '(none)'
					}`
			);
		}
	}

	return problems;
}

function listCatalogs() {
	if ( ! fs.existsSync( LANGUAGES ) ) {
		return [];
	}

	return fs
		.readdirSync( LANGUAGES )
		.filter( ( file ) => file.endsWith( '.po' ) )
		.sort()
		.map( ( file ) => path.join( LANGUAGES, file ) );
}

function main() {
	const files = process.argv.slice( 2 );
	const catalogs = files.length > 0 ? files : listCatalogs();

	if ( catalogs.length === 0 ) {
		process.stdout.write(
			'[i18n] No locale .po files — nothing to lint.\n'
		);
		return;
	}

	let failures = 0;

	for ( const catalog of catalogs ) {
		const problems = findPlaceholderMismatches(
			fs.readFileSync( catalog, 'utf8' )
		);

		if ( problems.length === 0 ) {
			continue;
		}

		failures += problems.length;
		process.stderr.write(
			`i18n: placeholder mismatch in ${ path.basename(
				catalog
			) }\n${ problems.join( '\n' ) }\n`
		);
	}

	if ( failures > 0 ) {
		process.stderr.write(
			`[i18n] ERROR: ${ failures } translation(s) do not preserve their placeholders.\n`
		);
		process.exitCode = 1;
		return;
	}

	process.stdout.write( '[i18n] Placeholder lint OK.\n' );
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	main();
}
