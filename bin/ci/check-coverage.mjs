import { readFile } from 'node:fs/promises';

/*
 * Unit and WordPress coverage are separate because PHPUnit allows one
 * bootstrap per configuration. Their executable statement lines are unioned
 * here: a statement covered by either suite counts once, even when different
 * runners name the same inc/ file from different checkout roots.
 *
 * The 70% floor is a ratchet just below the measured 70.25% baseline. A test
 * in either appropriate suite can advance it; splitting a WordPress rule into
 * pure PHP is no longer the only way to keep the gate green.
 */
const MIN_LINE_PERCENT = 70;
const reportPaths = process.argv.slice( 2 );

if ( reportPaths.length === 0 ) {
	throw new Error(
		'Usage: node bin/ci/check-coverage.mjs <clover.xml> [clover.xml...]'
	);
}

const statementsByLocation = new Map();

for ( const reportPath of reportPaths ) {
	const xml = await readFile( reportPath, 'utf8' );
	let reportStatements = 0;

	for ( const file of xml.matchAll(
		/<file name="([^"]+)">([\s\S]*?)<\/file>/g
	) ) {
		const normalizedPath = file[ 1 ].replaceAll( '\\', '/' );
		const incMarker = '/inc/';
		const incIndex = normalizedPath.lastIndexOf( incMarker );

		if ( incIndex < 0 ) {
			throw new Error(
				`ci:coverage: ${ reportPath } contains a file outside inc/: ${ file[ 1 ] }`
			);
		}

		const sourcePath = normalizedPath.slice( incIndex + 1 );

		for ( const line of file[ 2 ].matchAll( /<line\b([^>]*)\/>/g ) ) {
			const attributes = Object.fromEntries(
				[ ...line[ 1 ].matchAll( /(\w+)="([^"]*)"/g ) ].map(
					( match ) => [ match[ 1 ], match[ 2 ] ]
				)
			);

			if ( attributes.type !== 'stmt' ) {
				continue;
			}

			reportStatements += 1;
			const location = `${ sourcePath }:${ attributes.num }`;
			const covered = Number( attributes.count ) > 0;
			statementsByLocation.set(
				location,
				( statementsByLocation.get( location ) ?? false ) || covered
			);
		}
	}

	if ( reportStatements === 0 ) {
		throw new Error(
			`ci:coverage: ${ reportPath } contains no executable statements`
		);
	}
}

const statements = statementsByLocation.size;
const coveredStatements = [ ...statementsByLocation.values() ].filter(
	Boolean
).length;

const percent = ( coveredStatements / statements ) * 100;
console.log(
	`ci:coverage: ${ coveredStatements }/${ statements } statements across ${
		reportPaths.length
	} reports (${ percent.toFixed( 2 ) }%; minimum ${ MIN_LINE_PERCENT.toFixed(
		2
	) }%)`
);

if ( percent < MIN_LINE_PERCENT ) {
	process.exitCode = 1;
}
