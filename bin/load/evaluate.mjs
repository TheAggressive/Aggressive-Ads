#!/usr/bin/env node

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export function evaluateEvidence( before, after, result ) {
	const failures = [ ...result.failures ];
	const accepted = result.mixed.beacon_ok;
	const eventDelta = after.event_count - before.event_count;
	const rollupDelta = after.rollup_count - before.rollup_count;

	if ( result.status !== 'http-pass' ) {
		failures.push( 'HTTP benchmark did not pass its own criteria' );
	}

	for ( const snapshot of [ before, after ] ) {
		if (
			snapshot.fixture_ads !== 1000 ||
			snapshot.candidate_count !== 1000
		) {
			failures.push(
				'the measured catalogue did not contain exactly 1,000 eligible ads'
			);
		}

		if ( ! snapshot.external_cache || ! snapshot.atomic_cache_counter ) {
			failures.push(
				'the measured WordPress environment lacked a persistent atomic object cache'
			);
		}

		if ( ! snapshot.reconciler_schedule || ! snapshot.retention_schedule ) {
			failures.push(
				'tracking reconciliation or retention was not scheduled'
			);
		}
	}

	if ( eventDelta !== accepted ) {
		failures.push(
			`confirmed ${ accepted } beacons but the durable ledger grew by ${ eventDelta }`
		);
	}

	if ( rollupDelta !== eventDelta ) {
		failures.push(
			`the durable ledger grew by ${ eventDelta } but the reporting projection grew by ${ rollupDelta }`
		);
	}

	if ( after.duplicate_count !== 0 ) {
		failures.push(
			`the event ledger contains ${ after.duplicate_count } duplicate token/event pairs`
		);
	}

	if ( after.innodb_deadlocks !== before.innodb_deadlocks ) {
		failures.push(
			`InnoDB deadlocks increased from ${ before.innodb_deadlocks } to ${ after.innodb_deadlocks }`
		);
	}

	return {
		accepted,
		eventDelta,
		rollupDelta,
		failures: [ ...new Set( failures ) ],
	};
}

function readJson( path ) {
	return JSON.parse( readFileSync( path, 'utf8' ).trim() );
}

function main() {
	const [ beforePath, afterPath, resultPath ] = process.argv.slice( 2 );

	if ( ! beforePath || ! afterPath || ! resultPath ) {
		throw new Error(
			'usage: evaluate.mjs <before-snapshot.json> <after-snapshot.json> <load-result.json>'
		);
	}

	const evidence = evaluateEvidence(
		readJson( beforePath ),
		readJson( afterPath ),
		readJson( resultPath )
	);

	if ( evidence.failures.length > 0 ) {
		for ( const failure of evidence.failures ) {
			console.error( `FAIL: ${ failure }` );
		}

		process.exitCode = 1;
	} else {
		console.log(
			`PASS: ${ evidence.accepted } accepted impressions were durable and exactly projected with no deadlocks or duplicate ledger rows.`
		);
	}
}

if (
	process.argv[ 1 ] &&
	resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url )
) {
	main();
}
