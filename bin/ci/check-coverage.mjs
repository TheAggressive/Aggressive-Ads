import { readFile } from 'node:fs/promises';

/*
 * This measures the UNIT suite only, which cannot load WordPress. Most of this
 * plugin's logic is org-scoped map_meta_cap, real REST authorization, dbDelta
 * and uploads — none of it expressible without a bootstrap, all of it covered
 * by the integration, rest, security and upgrade suites instead. So 8% is not
 * "barely tested"; it is the share of the codebase that is pure enough to test
 * in milliseconds, and the floor exists to stop that share shrinking.
 *
 * It is a ratchet, and it sits close to the current figure on purpose: adding
 * pure logic without a unit test trips it. If it trips on code that genuinely
 * needs WordPress, the fix is a test in the right suite, not a lower number.
 */
const MIN_LINE_PERCENT = 8;
const reportPath = process.argv[ 2 ];

if ( ! reportPath ) {
	throw new Error( 'Usage: node bin/ci/check-coverage.mjs <clover.xml>' );
}

const xml = await readFile( reportPath, 'utf8' );
const projectMetrics = [ ...xml.matchAll( /<metrics\b([^>]*)\/>/g ) ].at(
	-1
)?.[ 1 ];

if ( ! projectMetrics ) {
	throw new Error(
		`ci:coverage: no project metrics found in ${ reportPath }`
	);
}

const attributes = Object.fromEntries(
	[ ...projectMetrics.matchAll( /(\w+)="([^"]*)"/g ) ].map( ( match ) => [
		match[ 1 ],
		Number( match[ 2 ] ),
	] )
);
const statements = attributes.statements ?? 0;
const coveredStatements = attributes.coveredstatements ?? 0;

if ( statements === 0 ) {
	throw new Error(
		`ci:coverage: ${ reportPath } contains no executable statements`
	);
}

const percent = ( coveredStatements / statements ) * 100;
console.log(
	`ci:coverage: ${ coveredStatements }/${ statements } statements (${ percent.toFixed(
		2
	) }%; minimum ${ MIN_LINE_PERCENT.toFixed( 2 ) }%)`
);

if ( percent < MIN_LINE_PERCENT ) {
	process.exitCode = 1;
}
