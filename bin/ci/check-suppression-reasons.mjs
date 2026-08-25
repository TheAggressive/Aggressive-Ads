#!/usr/bin/env node

/**
 * Security-suppression gate.
 *
 * PHPCS is the plugin's real defence against SQL injection and XSS:
 * WordPress.DB.PreparedSQL refuses an unprepared query and
 * WordPress.Security.EscapeOutput refuses an unescaped echo. Both are one
 * `phpcs:ignore` away from being switched off, and an ignore with no reason
 * beside it is indistinguishable from one somebody added to get a red build
 * green.
 *
 * So the rule is not "no suppressions" — several here are legitimate and
 * documented. The rule is that every suppression of a *security* sniff states
 * why, in the `-- reason` form PHPCS already supports. Writing the sentence is
 * what makes an unjustifiable suppression obvious to the person adding it, and
 * reviewable by everyone after.
 *
 * See docs/threat-model.md.
 */

import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

/** Sniff fragments whose suppression has to be argued for. */
const SECURITY_SNIFFS = [
	'PreparedSQL',
	'EscapeOutput',
	'NonceVerification',
	'ValidatedSanitizedInput',
	'SafeRedirect',
	'RestrictedVariables',
	'ServerVariables',
	'DirectDatabaseQuery',
];

/** Shorter than this is a shrug, not a reason. */
const MIN_REASON = 15;

/*
 * Overridable so the guard can be pointed at a fixture. See
 * check-suppression-reasons.test.mjs.
 */
const SCAN_ROOT = process.env.AGGR_SUPPRESSION_SCAN_DIR ?? '.';
const ROOTS = [ 'inc', 'templates' ].map( ( dir ) =>
	path.join( SCAN_ROOT, dir )
);

async function phpFiles( root ) {
	const found = [];
	const entries = await readdir( root, { withFileTypes: true } );

	for ( const entry of entries ) {
		const full = path.join( root, entry.name );

		if ( entry.isDirectory() ) {
			found.push( ...( await phpFiles( full ) ) );
		} else if ( entry.name.endsWith( '.php' ) ) {
			found.push( full );
		}
	}

	return found;
}

function offences( file, contents ) {
	const found = [];
	const lines = contents.split( '\n' );

	for ( const [ index, line ] of lines.entries() ) {
		/*
		 * The deprecated form states nothing and cannot.
		 *
		 * `@codingStandardsIgnoreFile|Start|Line` has no `--` reason syntax at
		 * all, so a suppression written this way can never justify itself. It
		 * was deprecated in PHPCS 3.2.0 and removed in 4.0, but this project
		 * runs 3.13, where it still silences everything it covers — including
		 * every sniff in SECURITY_SNIFFS. The old regex required whitespace
		 * after `ignore`, so none of these matched.
		 */
		const legacy = /@codingStandardsIgnore(File|Start|Line)/u.exec( line );

		if ( legacy ) {
			found.push( {
				file,
				line: index + 1,
				directive: `@codingStandardsIgnore${ legacy[ 1 ] } — deprecated, and cannot state a reason`,
			} );

			continue;
		}

		/*
		 * `phpcs:ignoreFile` turns off *every* sniff for the whole file, which
		 * is strictly broader than suppressing a named security sniff — and the
		 * old pattern missed it for the same whitespace reason, because the
		 * directive is followed by `File` rather than a space.
		 */
		const wholeFile = /phpcs:ignoreFile\b(.*)$/u.exec( line );

		if ( wholeFile ) {
			const separator = wholeFile[ 1 ].indexOf( '--' );
			const why =
				-1 === separator
					? ''
					: wholeFile[ 1 ].slice( separator + 2 ).trim();

			if ( why.length < MIN_REASON ) {
				found.push( {
					file,
					line: index + 1,
					directive:
						'phpcs:ignoreFile — disables every sniff in this file',
				} );
			}

			continue;
		}

		const match = /phpcs:(?:ignore|disable)\s+(.*)$/u.exec( line );

		if ( ! match ) {
			continue;
		}

		const directive = match[ 1 ];

		if (
			! SECURITY_SNIFFS.some( ( sniff ) => directive.includes( sniff ) )
		) {
			continue;
		}

		const separator = directive.indexOf( '--' );
		const reason =
			separator === -1 ? '' : directive.slice( separator + 2 ).trim();

		if ( reason.length < MIN_REASON ) {
			found.push( {
				file,
				line: index + 1,
				directive: directive.replace( /\s+/gu, ' ' ).slice( 0, 90 ),
			} );
		}
	}

	return found;
}

const files = ( await Promise.all( ROOTS.map( phpFiles ) ) ).flat();

/*
 * Zero files is not "no unjustified suppressions", it is a guard that read
 * nothing. `phpFiles` throws on a missing root, which fails loudly and is
 * correct; this covers roots that exist and are empty.
 */
if ( 0 === files.length ) {
	console.error(
		`check-suppression-reasons: no PHP files found under ${ ROOTS.join(
			', '
		) }`
	);
	console.error(
		'A gate that reads nothing reports success over nothing. See CLAUDE.md.'
	);
	process.exit( 1 );
}
const contents = await Promise.all(
	files.map( ( file ) => readFile( file, 'utf8' ) )
);
const failures = files.flatMap( ( file, index ) =>
	offences( file, contents[ index ] )
);

if ( failures.length > 0 ) {
	console.error( 'Security sniff suppressed without a reason:\n' );

	for ( const failure of failures ) {
		console.error( `  ${ failure.file }:${ failure.line }` );
		console.error( `    ${ failure.directive }` );
	}

	console.error(
		`\nAdd the reason in the form PHPCS already understands:\n` +
			`  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Why this is safe here.\n` +
			`See docs/threat-model.md.`
	);
	process.exitCode = 1;
} else {
	console.log(
		`check-suppression-reasons: ok (${ files.length } files scanned)`
	);
}
