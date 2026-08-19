#!/usr/bin/env node

/**
 * The gate that decides whether a run passed.
 *
 * Reads job results from the environment and applies `summary-rules.mjs`.
 * Prints every lane so a failure is diagnosable from the log alone, and every
 * reason rather than the first, because a run that fails three ways should say
 * so once rather than over three pushes.
 */

import process from 'node:process';

import { judge, QUALITY_LANES } from './summary-rules.mjs';

const env = process.env;
const flag = ( name ) => 'true' === env[ name ];

const run = {
	syncOnly: flag( 'SYNC_ONLY' ),
	proseOnly: flag( 'PROSE_ONLY' ),
	publishRequested: flag( 'PUBLISH_REQUESTED' ),
	onMaster: 'refs/heads/master' === env.EVENT_REF,
	shouldRelease: flag( 'SHOULD_RELEASE' ),
};

for ( const lane of [
	...QUALITY_LANES,
	'RELEASE_PLAN',
	'RELEASE',
	'VERSION_SYNC',
] ) {
	run[ lane ] = env[ lane ] ?? 'missing';
	console.log( `${ lane.padEnd( 13 ) }${ run[ lane ] }` );
}

const { ok, problems } = judge( run );

if ( ! ok ) {
	console.error( '\ncheck-summary: this run does not pass.' );

	for ( const problem of problems ) {
		console.error( `  ${ problem }` );
	}

	process.exit( 1 );
}

console.log( '\ncheck-summary: every lane is where it should be.' );
