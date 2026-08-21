#!/usr/bin/env node
/**
 * Regression check for audited transitive packages patched in this repository.
 */

import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire( import.meta.url );

/*
 * The parents that pull adm-zip into this project.
 *
 * `@wordpress/env` used to be one and is not a dependency any more. Removing
 * wp-env left this list behind, and it kept resolving only because a stale copy
 * sat in an unrelated parent directory's node_modules — so on a clean checkout
 * require.resolve threw and this lane died before it checked anything.
 *
 * A parent named here must be a real dependency of this package.
 */
const parents = [ '@wordpress/scripts/package.json' ];
const zipPaths = new Set(
	parents.map( ( parent ) =>
		createRequire( require.resolve( parent ) ).resolve(
			'adm-zip/zipEntry.js'
		)
	)
);

for ( const zipEntryPath of zipPaths ) {
	const source = readFileSync( zipEntryPath, 'utf8' );

	if ( source.includes( 'Buffer.alloc(_centralHeader.size)' ) ) {
		console.error(
			'patched-dependencies: adm-zip still allocates attacker-declared output size'
		);
		process.exit( 1 );
	}

	if ( ! source.includes( 'Buffer.alloc(compressedData.length)' ) ) {
		console.error(
			'patched-dependencies: adm-zip CVE-2026-39244 patch is missing'
		);
		process.exit( 1 );
	}
}

const scriptsRequire = createRequire(
	require.resolve( '@wordpress/scripts/package.json' )
);
const AdmZip = scriptsRequire( 'adm-zip' );
const archive = new AdmZip();
archive.addFile( 'round-trip.txt', Buffer.from( 'patched dependency check' ) );
const reopened = new AdmZip( archive.toBuffer() );

if ( reopened.readAsText( 'round-trip.txt' ) !== 'patched dependency check' ) {
	console.error(
		'patched-dependencies: patched adm-zip failed a normal round trip'
	);
	process.exit( 1 );
}

console.log( 'patched-dependencies: adm-zip CVE-2026-39244 fix present' );
