import assert from 'node:assert/strict';
import http from 'node:http';
import { test } from 'node:test';

import {
	assertSafeTarget,
	extractToken,
	parseDuration,
	percentile,
	runFill,
	runMixedView,
} from './mixed-view.mjs';

test( 'HTTP clients refuse an unconfirmed remote target', () => {
	assert.equal(
		assertSafeTarget( 'http://127.0.0.1:9961' ).hostname,
		'127.0.0.1'
	);
	assert.throws(
		() => assertSafeTarget( 'https://example.com' ),
		/Refusing a remote load test/
	);
	assert.equal(
		assertSafeTarget( 'https://example.com', '1' ).hostname,
		'example.com'
	);
	assert.throws(
		() => assertSafeTarget( 'file:///tmp/target', '1' ),
		/must use HTTP or HTTPS/
	);
} );

test( 'parseDuration supports every documented unit', () => {
	assert.equal( parseDuration( '250ms' ), 250 );
	assert.equal( parseDuration( '5s' ), 5000 );
	assert.equal( parseDuration( '1.5m' ), 90_000 );
	assert.equal( parseDuration( '2h' ), 7_200_000 );
} );

test( 'parseDuration rejects ambiguous or unsafe values', () => {
	for ( const value of [ '', '0s', '-1s', '15', 'seconds' ] ) {
		assert.throws( () => parseDuration( value ) );
	}
} );

test( 'percentile uses nearest-rank selection', () => {
	const values = [ 10, 20, 30, 40, 50 ];

	assert.equal( percentile( values, 50 ), 30 );
	assert.equal( percentile( values, 95 ), 50 );
	assert.equal( percentile( [], 99 ), 0 );
} );

test( 'extractToken accepts only a non-empty creative token', () => {
	assert.equal(
		extractToken( '{"creative":{"token":"signed-token"}}' ),
		'signed-token'
	);
	assert.equal( extractToken( '{"creative":null}' ), null );
	assert.equal( extractToken( '{"token":"wrong-level"}' ), null );
	assert.equal( extractToken( 'not-json' ), null );
} );

test( 'HTTP clients preserve successful fill and beacon accounting', async ( t ) => {
	const server = http.createServer( ( request, response ) => {
		if ( request.method === 'POST' ) {
			response.writeHead( 204 ).end();
			return;
		}

		response
			.writeHead( 200, { 'Content-Type': 'application/json' } )
			.end( '{"creative":{"token":"signed-token"}}' );
	} );

	await new Promise( ( resolve ) =>
		server.listen( 0, '127.0.0.1', resolve )
	);
	t.after( () => new Promise( ( resolve ) => server.close( resolve ) ) );

	const address = server.address();
	const baseUrl = `http://127.0.0.1:${ address.port }`;
	const fill = await runFill( baseUrl, 25, 2 );
	const mixed = await runMixedView( baseUrl, 25, 2 );

	assert.ok( fill.fill_ok > 0 );
	assert.equal( fill.fill_ok, fill.requests );
	assert.equal( fill.timeout_errors, 0 );
	assert.ok( mixed.beacon_ok > 0 );
	assert.equal( mixed.fill_ok, mixed.beacon_ok );
	assert.equal( mixed.requests, mixed.fill_ok + mixed.beacon_ok );
	assert.equal( mixed.timeout_errors, 0 );
} );
