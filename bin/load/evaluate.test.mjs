import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { after, test } from 'node:test';

import { evaluateEvidence } from './evaluate.mjs';

const temporaryDirectories = [];

after( () => {
	for ( const directory of temporaryDirectories ) {
		rmSync( directory, { recursive: true, force: true } );
	}
} );

function snapshot( overrides = {} ) {
	return {
		fixture_ads: 1000,
		candidate_count: 1000,
		event_count: 20,
		rollup_count: 20,
		duplicate_count: 0,
		innodb_deadlocks: 0,
		external_cache: true,
		atomic_cache_counter: true,
		reconciler_schedule: 'hourly',
		retention_schedule: 'hourly',
		...overrides,
	};
}

function loadResult( overrides = {} ) {
	return {
		status: 'http-pass',
		failures: [],
		mixed: { beacon_ok: 100, fill_ok: 100 },
		...overrides,
	};
}

function childEnvironment( overrides = {} ) {
	const environment = { ...process.env, ...overrides };
	delete environment.NODE_TEST_CONTEXT;

	return environment;
}

function evaluate( before, afterSnapshot, result ) {
	const directory = mkdtempSync( resolve( tmpdir(), 'aggr-load-evaluate-' ) );
	temporaryDirectories.push( directory );

	const paths = [ 'before.json', 'after.json', 'result.json' ].map(
		( name ) => resolve( directory, name )
	);

	for ( const [ index, value ] of [
		before,
		afterSnapshot,
		result,
	].entries() ) {
		writeFileSync( paths[ index ], JSON.stringify( value ) );
	}

	return spawnSync(
		process.execPath,
		[ resolve( 'bin/load/evaluate.mjs' ), ...paths ],
		{ encoding: 'utf8', env: childEnvironment() }
	);
}

test( 'accepts exact durable and projected beacon totals', () => {
	const before = snapshot();
	const afterSnapshot = snapshot( { event_count: 120, rollup_count: 120 } );
	const load = loadResult();
	const result = evaluateEvidence( before, afterSnapshot, load );

	assert.deepEqual( result.failures, [] );
	assert.equal( result.accepted, 100 );
	assert.equal( result.eventDelta, 100 );
	assert.equal( result.rollupDelta, 100 );
	assert.equal( evaluate( before, afterSnapshot, load ).status, 0 );
} );

test( 'fails closed on ledger drift, deadlocks, or missing cache guarantees', () => {
	const result = evaluateEvidence(
		snapshot(),
		snapshot( {
			event_count: 119,
			rollup_count: 118,
			innodb_deadlocks: 1,
			external_cache: false,
		} ),
		loadResult()
	);

	const failures = result.failures.join( '\n' );
	assert.match( failures, /confirmed 100 beacons.*ledger grew by 99/ );
	assert.match( failures, /ledger grew by 99.*projection grew by 98/ );
	assert.match( failures, /lacked a persistent atomic object cache/ );
	assert.match( failures, /InnoDB deadlocks increased/ );
} );

test( 'fails when the ledger grows beyond acknowledged beacons', () => {
	const result = evaluateEvidence(
		snapshot(),
		snapshot( { event_count: 121, rollup_count: 121 } ),
		loadResult()
	);

	assert.match(
		result.failures.join( '\n' ),
		/confirmed 100 beacons.*ledger grew by 101/
	);
} );

test( 'the runner refuses to load test a remote host by default', () => {
	const result = spawnSync(
		process.execPath,
		[ resolve( 'bin/load/run.mjs' ) ],
		{
			encoding: 'utf8',
			env: childEnvironment( {
				AGGR_LOAD_BASE_URL: 'https://example.com',
				AGGR_LOAD_REMOTE_CONFIRM: '',
			} ),
		}
	);

	assert.notEqual( result.status, 0 );
	assert.match( result.stderr, /Refusing a remote load test/ );
} );
