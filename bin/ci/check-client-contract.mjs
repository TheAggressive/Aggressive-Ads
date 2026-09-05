/**
 * Keeps the server and the browser from shipping opposite halves of one
 * contract.
 *
 * P15's refresh policy resolved on the server — `rotate`, `rotateSeconds`,
 * `maxRefreshes` — and the store never read the cap. The fill endpoint grew
 * an `n` parameter that partitions page from refresh, and `fillSlot` never
 * sent it. Every PHP test that named those behaviours passed a sequence in
 * by hand or asserted the JSON the server *would* emit. The live path kept
 * filing every rotation as a page opportunity and rotating to the client's
 * own hard stop of a hundred.
 *
 * That is the frequency-capping defect again: `get_count()` was correct and
 * nothing called `increment()`. A write half and a read half that never
 * meet in a test will keep happening until something reads both sources
 * and fails when one is missing.
 *
 * This lane is that something. It does not test behaviour. It tests that
 * the two halves still name each other.
 */

import { readdir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const ROOT = path.resolve( import.meta.dirname, '../..' );

/*
 * Overridable only so this lane's own tests can point it at fixtures.
 * A guard nobody exercises rots into one that permits everything, silently.
 */
const SCAN_ROOT = process.env.AGGR_CLIENT_CONTRACT_SCAN_DIR ?? ROOT;

const PHP_CONTEXT = 'inc/Domain/class-slot-options.php';
const FILL_CONTROLLER = 'inc/REST/class-fill-controller.php';
const CLIENT_FILES = [
	'src/blocks-interactivity/ad-slot/view.js',
	'src/blocks-interactivity/ad-slot/fill.js',
	'src/blocks-interactivity/ad-slot/empty.js',
];

/**
 * Absolute path under the scan root.
 *
 * @param {string} relative Repository-relative path.
 * @return {string} Absolute path.
 */
function resolve( relative ) {
	return path.join( SCAN_ROOT, relative );
}

/**
 * Line numbers that are prose rather than code.
 *
 * A docblock that quotes `context.maxRefreshes` to explain the rule is not
 * a reader. Reading it as one is the guard firing on the thing it protects.
 *
 * @param {string[]} lines File split on newlines.
 * @return {Set<number>} Zero-based indices to ignore.
 */
function commentLines( lines ) {
	const skip = new Set();
	let inBlock = false;

	lines.forEach( ( line, index ) => {
		const trimmed = line.trim();

		if ( inBlock ) {
			skip.add( index );

			if ( trimmed.includes( '*/' ) ) {
				inBlock = false;
			}

			return;
		}

		if ( trimmed.startsWith( '//' ) || trimmed.startsWith( '*' ) ) {
			skip.add( index );

			return;
		}

		if ( trimmed.startsWith( '/*' ) ) {
			skip.add( index );
			inBlock = ! trimmed.includes( '*/' );
		}
	} );

	return skip;
}

/**
 * File text with comment lines blanked, so a match cannot hide in prose.
 *
 * @param {string} source File contents.
 * @return {string} Same length, comments replaced with empty lines.
 */
function codeOnly( source ) {
	const lines = source.split( '\n' );
	const prose = commentLines( lines );

	return lines
		.map( ( line, index ) => ( prose.has( index ) ? '' : line ) )
		.join( '\n' );
}

/**
 * Keys `resolved_context()` actually emits.
 *
 * Read out of the return array, not a list kept beside it. A hardcoded
 * list is a second copy, and a second copy is how this lane would pass
 * after a key was added on one side only.
 *
 * @param {string} php Slot_Options source.
 * @return {string[]|null} Context keys, or null when the method is gone.
 */
function contextKeys( php ) {
	const start = php.indexOf( 'function resolved_context' );

	if ( start < 0 ) {
		return null;
	}

	const body = php.slice( start, start + 2500 );
	const arrayMatch = body.match( /return array\s*\(([\s\S]*?)\n\t\t\);/ );

	if ( ! arrayMatch ) {
		return null;
	}

	return [
		...arrayMatch[ 1 ].matchAll( /'([A-Za-z][A-Za-z0-9]*)'\s*=>/g ),
	].map( ( match ) => match[ 1 ] );
}

/**
 * Whether any client file reads `context.KEY` or `context?.KEY`.
 *
 * @param {string} client Concatenated runtime JS, comments stripped.
 * @param {string} key    Context key.
 * @return {boolean} Whether a reader exists.
 */
function clientReads( client, key ) {
	return new RegExp( `context(?:\\?)?\\.${ key }\\b` ).test( client );
}

/**
 * Runs the check.
 *
 * @return {Promise<void>} Resolves when the lane has reported.
 */
async function main() {
	const problems = [];
	const required = [ PHP_CONTEXT, FILL_CONTROLLER, ...CLIENT_FILES ];
	let scanned = 0;

	for ( const relative of required ) {
		try {
			await stat( resolve( relative ) );
		} catch {
			problems.push(
				`check-client-contract: ${ relative } does not exist, so this ` +
					'lane is protecting nothing. Update the path when the file moves.'
			);
		}
	}

	if ( problems.length > 0 ) {
		console.error( problems.join( '\n' ) );
		process.exit( 1 );
	}

	const php = await readFile( resolve( PHP_CONTEXT ), 'utf8' );
	const controller = codeOnly(
		await readFile( resolve( FILL_CONTROLLER ), 'utf8' )
	);
	const clientParts = [];

	for ( const relative of CLIENT_FILES ) {
		clientParts.push(
			codeOnly( await readFile( resolve( relative ), 'utf8' ) )
		);
		scanned += 1;
	}

	const client = clientParts.join( '\n' );
	const keys = contextKeys( php );

	if ( null === keys || 0 === keys.length ) {
		console.error(
			'check-client-contract: resolved_context() was not found, or its ' +
				'return array has no keys, so this lane is reading nothing.'
		);
		process.exit( 1 );
	}

	let read = 0;

	for ( const key of keys ) {
		if ( ! clientReads( client, key ) ) {
			problems.push(
				`context.${ key } is sent by Slot_Options::resolved_context() and ` +
					'no runtime client file reads it. A key with no reader is how ' +
					'maxRefreshes shipped as a publisher cap the timer ignored.'
			);
			continue;
		}

		read += 1;
	}

	const fill =
		clientParts[
			CLIENT_FILES.indexOf( 'src/blocks-interactivity/ad-slot/fill.js' )
		];
	const view =
		clientParts[
			CLIENT_FILES.indexOf( 'src/blocks-interactivity/ad-slot/view.js' )
		];

	if ( ! /get_param\(\s*'n'\s*\)/.test( controller ) ) {
		problems.push(
			"Fill_Controller no longer reads get_param( 'n' ), so the sequence " +
				'the client sends has no server reader.'
		);
	}

	if ( ! /searchParams\.set\(\s*['"]n['"]/.test( fill ) ) {
		problems.push(
			"fill.js does not searchParams.set( 'n', … ). The server reads a " +
				'sequence the live client never writes, so every rotation files as ' +
				'a page opportunity.'
		);
	}

	if ( ! /\bsequence\b/.test( fill ) || ! /String\(\s*n\s*\)/.test( fill ) ) {
		problems.push(
			'fill.js must send `n` from the sequence argument, not a literal. ' +
				'A baked-in n=0 is the increment() that nobody called.'
		);
	}

	if ( ! /fillSlot\s*\(\s*[^,)]+\s*,\s*rotations\s*\)/.test( view ) ) {
		problems.push(
			'view.js never calls fillSlot( …, rotations ). Sending n=0 on every ' +
				'tick is a write that does not meet the read.'
		);
	}

	const e2eDir = resolve( 'tests/e2e' );
	let e2eScanned = 0;

	try {
		const entries = await readdir( e2eDir );

		for ( const name of entries ) {
			if ( ! /\.(php|ts|js)$/.test( name ) ) {
				continue;
			}

			const source = codeOnly(
				await readFile( path.join( e2eDir, name ), 'utf8' )
			);

			e2eScanned += 1;

			const asks = /rotate"\s*:\s*true|rotate=["']true["']/.test(
				source
			);
			const grants = /set_refresh_policy\s*\(/.test( source );

			if ( asks && ! grants ) {
				problems.push(
					`tests/e2e/${ name }: asks rotate:true and does not call ` +
						'set_refresh_policy() in this file. A grant in another seed ' +
						'does not cover a new rotating page — that placement is ' +
						'created after activation and defaults to refresh off.'
				);
			}
		}
	} catch {
		problems.push(
			'check-client-contract: tests/e2e does not exist, so the seed half ' +
				'of this lane is protecting nothing.'
		);
	}

	if ( problems.length > 0 ) {
		console.error( problems.join( '\n' ) );
		process.exit( 1 );
	}

	console.log(
		`check-client-contract: ok (${ read } context keys, ${ scanned } client ` +
			`files, ${ e2eScanned } e2e files)`
	);
}

await main();
