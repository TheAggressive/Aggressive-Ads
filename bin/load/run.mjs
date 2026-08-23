#!/usr/bin/env node

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { assertSafeTarget } from './mixed-view.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const baseUrl = process.env.AGGR_LOAD_BASE_URL ?? 'http://127.0.0.1:9961';
const resultPath = resolve(
	root,
	process.env.AGGR_LOAD_RESULT ?? '.cache/load/result.json'
);
const duration = process.env.AGGR_LOAD_DURATION ?? '15m';
const warmupDuration = process.env.AGGR_LOAD_WARMUP_DURATION ?? '20s';
const fillDuration = process.env.AGGR_LOAD_FILL_DURATION ?? '60s';
const connections = positiveInteger( 'AGGR_LOAD_CONNECTIONS', 64 );
const minimumFillRps = positiveNumber( 'AGGR_LOAD_MIN_FILL_RPS', 250 );
const minimumViewRps = positiveNumber( 'AGGR_LOAD_MIN_VIEW_RPS', 100 );
const maximumP95Ms = positiveNumber( 'AGGR_LOAD_MAX_P95_MS', 250 );
const maximumP99Ms = positiveNumber( 'AGGR_LOAD_MAX_P99_MS', 750 );

const target = assertSafeTarget(
	baseUrl,
	process.env.AGGR_LOAD_REMOTE_CONFIRM
);

function positiveInteger( name, fallback ) {
	const value = Number.parseInt(
		process.env[ name ] ?? String( fallback ),
		10
	);

	if ( ! Number.isSafeInteger( value ) || value <= 0 ) {
		throw new Error( `${ name } must be a positive integer.` );
	}

	return value;
}

function positiveNumber( name, fallback ) {
	const value = Number( process.env[ name ] ?? fallback );

	if ( ! Number.isFinite( value ) || value <= 0 ) {
		throw new Error( `${ name } must be a positive number.` );
	}

	return value;
}

function runScenario( name, script, scenarioDuration, scenarioConnections ) {
	const output = execFileSync(
		process.execPath,
		[
			resolve( root, script ),
			target.origin,
			scenarioDuration,
			String( scenarioConnections ),
		],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'inherit' ] }
	);

	process.stdout.write( output );

	const line = output
		.split( /\r?\n/ )
		.find( ( candidate ) => candidate.startsWith( 'AGGR_LOAD_JSON:' ) );

	if ( ! line ) {
		throw new Error(
			`${ name } did not emit its machine-readable result.`
		);
	}

	return {
		...JSON.parse( line.slice( 'AGGR_LOAD_JSON:'.length ) ),
		connections: scenarioConnections,
	};
}

function operationalSocketErrors( result ) {
	return (
		result.connect_errors +
		result.read_errors +
		result.write_errors +
		result.timeout_errors
	);
}

console.log(
	`Warming the full WordPress/PHP/object-cache path for ${ warmupDuration }...`
);
runScenario(
	'warmup',
	'bin/load/fill.mjs',
	warmupDuration,
	Math.min( connections, 32 )
);

console.log( 'Measuring concurrent fill throughput...' );
const fill = runScenario(
	'fill',
	'bin/load/fill.mjs',
	fillDuration,
	connections
);

console.log( `Running the mixed fill/impression soak for ${ duration }...` );
const mixed = runScenario(
	'mixed-view',
	'bin/load/mixed-view.mjs',
	duration,
	connections
);

const failures = [];

if ( fill.requests_per_second < minimumFillRps ) {
	failures.push(
		`fill throughput ${ fill.requests_per_second.toFixed(
			1
		) } req/s is below ${ minimumFillRps }`
	);
}

if ( mixed.views_per_second < minimumViewRps ) {
	failures.push(
		`view throughput ${ mixed.views_per_second.toFixed(
			1
		) } views/s is below ${ minimumViewRps }`
	);
}

for ( const result of [ fill, mixed ] ) {
	if ( result.p95_us / 1000 > maximumP95Ms ) {
		failures.push(
			`${ result.scenario } p95 ${ ( result.p95_us / 1000 ).toFixed(
				1
			) }ms exceeds ${ maximumP95Ms }ms`
		);
	}

	if ( result.p99_us / 1000 > maximumP99Ms ) {
		failures.push(
			`${ result.scenario } p99 ${ ( result.p99_us / 1000 ).toFixed(
				1
			) }ms exceeds ${ maximumP99Ms }ms`
		);
	}

	if ( operationalSocketErrors( result ) !== 0 ) {
		failures.push(
			`${ result.scenario } had ${ operationalSocketErrors(
				result
			) } operational socket errors`
		);
	}

	if ( result.unexpected_status !== 0 ) {
		failures.push(
			`${ result.scenario } had ${ result.unexpected_status } unexpected HTTP statuses`
		);
	}
}

if ( mixed.missing_token !== 0 ) {
	failures.push(
		`${ mixed.missing_token } successful fills had no usable token`
	);
}

if ( mixed.fill_ok !== mixed.beacon_ok ) {
	failures.push(
		`fill/beacon completion drift ${
			mixed.fill_ok - mixed.beacon_ok
		} is not zero`
	);
}

const result = {
	status: failures.length === 0 ? 'http-pass' : 'failed',
	recorded_at_gmt: new Date().toISOString(),
	target: target.origin,
	duration,
	connections,
	criteria: {
		minimum_fill_requests_per_second: minimumFillRps,
		minimum_views_per_second: minimumViewRps,
		maximum_p95_ms: maximumP95Ms,
		maximum_p99_ms: maximumP99Ms,
		maximum_errors: 0,
	},
	fill,
	mixed,
	failures,
};

mkdirSync( dirname( resultPath ), { recursive: true } );
writeFileSync( resultPath, `${ JSON.stringify( result, null, 2 ) }\n` );
console.log( `Wrote ${ resultPath }` );

if ( failures.length > 0 ) {
	for ( const failure of failures ) {
		console.error( `FAIL: ${ failure }` );
	}

	process.exitCode = 1;
} else {
	console.log( 'HTTP load and latency criteria passed.' );
}
