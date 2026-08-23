#!/usr/bin/env node

import http from 'node:http';
import https from 'node:https';
import { pathToFileURL } from 'node:url';
import { performance } from 'node:perf_hooks';

export function assertSafeTarget( baseUrl, remoteConfirm = '' ) {
	const target = new URL( baseUrl );

	if ( ! [ 'http:', 'https:' ].includes( target.protocol ) ) {
		throw new Error( 'Load targets must use HTTP or HTTPS.' );
	}

	if (
		! [ '127.0.0.1', 'localhost', '::1' ].includes( target.hostname ) &&
		remoteConfirm !== '1'
	) {
		throw new Error(
			'Refusing a remote load test without AGGR_LOAD_REMOTE_CONFIRM=1.'
		);
	}

	return target;
}

export function parseDuration( value ) {
	const match = /^(\d+(?:\.\d+)?)(ms|s|m|h)$/.exec( value );

	if ( ! match ) {
		throw new Error(
			'Duration must use ms, s, m, or h (for example, 15m).'
		);
	}

	const factors = { ms: 1, s: 1000, m: 60_000, h: 3_600_000 };
	const milliseconds = Number( match[ 1 ] ) * factors[ match[ 2 ] ];

	if ( ! Number.isFinite( milliseconds ) || milliseconds <= 0 ) {
		throw new Error( 'Duration must be greater than zero.' );
	}

	return milliseconds;
}

export function percentile( sortedValues, percentage ) {
	if ( sortedValues.length === 0 ) {
		return 0;
	}

	const index = Math.min(
		sortedValues.length - 1,
		Math.ceil( ( percentage / 100 ) * sortedValues.length ) - 1
	);

	return sortedValues[ Math.max( 0, index ) ];
}

export function extractToken( body ) {
	try {
		const token = JSON.parse( body )?.creative?.token;

		return typeof token === 'string' && token !== '' ? token : null;
	} catch {
		return null;
	}
}

function positiveInteger( value, name ) {
	const number = Number.parseInt( value, 10 );

	if ( ! Number.isSafeInteger( number ) || number <= 0 ) {
		throw new Error( `${ name } must be a positive integer.` );
	}

	return number;
}

function request( transport, agent, target, options, body = '' ) {
	return new Promise( ( resolve, reject ) => {
		const started = performance.now();
		let settled = false;
		const finish = ( callback, value ) => {
			if ( settled ) {
				return;
			}

			settled = true;
			callback( value );
		};
		const outgoing = transport.request(
			target,
			{ ...options, agent },
			( response ) => {
				const chunks = [];

				response.on( 'data', ( chunk ) => chunks.push( chunk ) );
				response.on( 'aborted', () => {
					const error = new Error( 'Response was aborted.' );
					error.code = 'ECONNRESET';
					finish( reject, error );
				} );
				response.on( 'end', () => {
					finish( resolve, {
						body: Buffer.concat( chunks ).toString( 'utf8' ),
						latencyUs: Math.round(
							( performance.now() - started ) * 1000
						),
						status: response.statusCode ?? 0,
					} );
				} );
			}
		);

		outgoing.setTimeout( 5000, () => {
			const error = new Error( 'Request timed out.' );
			error.code = 'ETIMEDOUT';
			outgoing.destroy( error );
		} );
		outgoing.on( 'error', ( error ) => finish( reject, error ) );

		if ( body !== '' ) {
			outgoing.write( body );
		}

		outgoing.end();
	} );
}

function clientIp( connectionIndex, viewIndex ) {
	return `10.${ ( connectionIndex % 200 ) + 1 }.${
		( Math.floor( viewIndex / 200 ) % 200 ) + 1
	}.${ ( viewIndex % 200 ) + 1 }`;
}

function classifyError( error, totals ) {
	const code = typeof error?.code === 'string' ? error.code : 'UNKNOWN';
	totals.errorCodes[ code ] = ( totals.errorCodes[ code ] ?? 0 ) + 1;

	if ( code === 'ETIMEDOUT' ) {
		totals.timeoutErrors++;
	} else if (
		[ 'ECONNREFUSED', 'EHOSTUNREACH', 'ENETUNREACH', 'ENOTFOUND' ].includes(
			code
		)
	) {
		totals.connectErrors++;
	} else {
		totals.readErrors++;
	}
}

function createAgent( Agent ) {
	return new Agent( {
		keepAlive: true,
		maxFreeSockets: 1,
		maxSockets: 1,
	} );
}

export async function runFill( baseUrl, durationMs, connections ) {
	const target = new URL( baseUrl );
	const transport = target.protocol === 'https:' ? https : http;
	const Agent = target.protocol === 'https:' ? https.Agent : http.Agent;
	const totals = {
		connectErrors: 0,
		errorCodes: {},
		fillOk: 0,
		latencies: [],
		readErrors: 0,
		timeoutErrors: 0,
		unexpectedStatus: 0,
	};
	const started = performance.now();
	const deadline = started + durationMs;
	const workers = Array.from( { length: connections }, async () => {
		const agent = createAgent( Agent );

		try {
			while ( performance.now() < deadline ) {
				try {
					const fill = await request( transport, agent, target, {
						headers: {
							Accept: 'application/json',
							'Sec-Fetch-Site': 'same-origin',
							'User-Agent': 'AggressiveAdsLoad/1.0',
						},
						method: 'GET',
						path: '/?rest_route=/aggr/v1/fill/aggr-load-leaderboard',
					} );

					totals.latencies.push( fill.latencyUs );

					if ( fill.status === 200 ) {
						totals.fillOk++;
					} else {
						totals.unexpectedStatus++;
					}
				} catch ( error ) {
					classifyError( error, totals );
				}
			}
		} finally {
			agent.destroy();
		}
	} );

	await Promise.all( workers );

	const elapsedSeconds = ( performance.now() - started ) / 1000;
	const sortedLatencies = totals.latencies.sort(
		( left, right ) => left - right
	);

	return {
		scenario: 'fill',
		requests: sortedLatencies.length,
		requests_per_second: sortedLatencies.length / elapsedSeconds,
		p50_us: percentile( sortedLatencies, 50 ),
		p95_us: percentile( sortedLatencies, 95 ),
		p99_us: percentile( sortedLatencies, 99 ),
		max_us: sortedLatencies.at( -1 ) ?? 0,
		fill_ok: totals.fillOk,
		unexpected_status: totals.unexpectedStatus,
		connect_errors: totals.connectErrors,
		read_errors: totals.readErrors,
		write_errors: 0,
		timeout_errors: totals.timeoutErrors,
		error_codes: totals.errorCodes,
	};
}

export async function runMixedView( baseUrl, durationMs, connections ) {
	const target = new URL( baseUrl );
	const transport = target.protocol === 'https:' ? https : http;
	const Agent = target.protocol === 'https:' ? https.Agent : http.Agent;
	const totals = {
		beaconOk: 0,
		connectErrors: 0,
		errorCodes: {},
		fillOk: 0,
		latencies: [],
		missingToken: 0,
		readErrors: 0,
		timeoutErrors: 0,
		unexpectedStatus: 0,
	};
	const started = performance.now();
	const deadline = started + durationMs;
	const workers = Array.from( { length: connections }, async ( _, index ) => {
		const agent = createAgent( Agent );
		let viewIndex = 0;

		try {
			while ( performance.now() < deadline ) {
				viewIndex++;
				const ip = clientIp( index, viewIndex );
				let fill;

				try {
					fill = await request( transport, agent, target, {
						headers: {
							Accept: 'application/json',
							'Sec-Fetch-Site': 'same-origin',
							'User-Agent': 'AggressiveAdsLoad/1.0',
							'X-Aggr-Load-IP': ip,
						},
						method: 'GET',
						path: '/?rest_route=/aggr/v1/fill/aggr-load-leaderboard',
					} );
				} catch ( error ) {
					classifyError( error, totals );
					continue;
				}

				totals.latencies.push( fill.latencyUs );

				if ( fill.status !== 200 ) {
					totals.unexpectedStatus++;
					continue;
				}

				totals.fillOk++;
				const token = extractToken( fill.body );

				if ( typeof token !== 'string' || token === '' ) {
					totals.missingToken++;
					continue;
				}

				const payload = JSON.stringify( { token } );

				try {
					const beacon = await request(
						transport,
						agent,
						target,
						{
							headers: {
								Accept: 'application/json',
								'Content-Length': Buffer.byteLength( payload ),
								'Content-Type': 'application/json',
								'Sec-Fetch-Site': 'same-origin',
								'User-Agent': 'AggressiveAdsLoad/1.0',
								'X-Aggr-Load-IP': ip,
							},
							method: 'POST',
							path: '/?rest_route=/aggr/v1/i',
						},
						payload
					);

					totals.latencies.push( beacon.latencyUs );

					if ( beacon.status === 204 ) {
						totals.beaconOk++;
					} else {
						totals.unexpectedStatus++;
					}
				} catch ( error ) {
					classifyError( error, totals );
				}
			}
		} finally {
			agent.destroy();
		}
	} );

	await Promise.all( workers );

	const elapsedSeconds = ( performance.now() - started ) / 1000;
	const sortedLatencies = totals.latencies.sort(
		( left, right ) => left - right
	);

	return {
		scenario: 'mixed-view',
		requests: sortedLatencies.length,
		requests_per_second: sortedLatencies.length / elapsedSeconds,
		views_per_second: totals.beaconOk / elapsedSeconds,
		p50_us: percentile( sortedLatencies, 50 ),
		p95_us: percentile( sortedLatencies, 95 ),
		p99_us: percentile( sortedLatencies, 99 ),
		max_us: sortedLatencies.at( -1 ) ?? 0,
		fill_ok: totals.fillOk,
		beacon_ok: totals.beaconOk,
		missing_token: totals.missingToken,
		unexpected_status: totals.unexpectedStatus,
		connect_errors: totals.connectErrors,
		read_errors: totals.readErrors,
		write_errors: 0,
		timeout_errors: totals.timeoutErrors,
		error_codes: totals.errorCodes,
	};
}

async function main() {
	const baseUrl = process.argv[ 2 ] ?? 'http://127.0.0.1:9961';
	const duration = process.argv[ 3 ] ?? '15m';
	const connections = positiveInteger(
		process.argv[ 4 ] ?? '64',
		'connections'
	);
	assertSafeTarget( baseUrl, process.env.AGGR_LOAD_REMOTE_CONFIRM );
	const result = await runMixedView(
		baseUrl,
		parseDuration( duration ),
		connections
	);

	process.stdout.write( `AGGR_LOAD_JSON:${ JSON.stringify( result ) }\n` );
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	main().catch( ( error ) => {
		console.error( error );
		process.exitCode = 1;
	} );
}
