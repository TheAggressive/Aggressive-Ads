/**
 * Tests for the lock that keeps two runs off one site.
 *
 * The failure it prevents is a second run restoring the site under a first one
 * mid-test, so what matters is that a live holder is never displaced — not by a
 * caller, not by an unlock, and not by several runs racing for a stale lock,
 * which is tested with real processes racing rather than by reasoning about it.
 */

import { strict as assert } from 'node:assert';
import { spawn, spawnSync } from 'node:child_process';
import {
	existsSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

import { LOCK_FILE, lock, startedAt, unlock } from './e2e-lock.mjs';

const MODULE = path.join(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'e2e-lock.mjs'
);

const roots = [];

after( () => {
	for ( const root of roots ) {
		rmSync( root, { recursive: true, force: true } );
	}
} );

function site() {
	const root = mkdtempSync( path.join( tmpdir(), 'aggr-lock-' ) );
	roots.push( root );

	return root;
}

function holder( root ) {
	return JSON.parse( readFileSync( path.join( root, LOCK_FILE ), 'utf8' ) );
}

/** A pid that belonged to a process and no longer does. */
function deadPid() {
	return spawnSync( process.execPath, [ '-e', '' ] ).pid;
}

/** No temporary lock files left beside the lock. */
function leftovers( root ) {
	return readdirSync( root ).filter( ( name ) => name !== LOCK_FILE );
}

test( 'a free site is locked to the runner, by pid and start time', () => {
	const root = site();
	lock( root, process.pid );

	const { pid, started, nonce } = holder( root );
	assert.deepEqual(
		{ pid, started },
		{
			pid: process.pid,
			started: startedAt( process.pid ),
		}
	);
	assert.match( nonce, /^[0-9a-f-]{36}$/ );
	assert.notEqual( holder( root ).started, '' );
	assert.deepEqual( leftovers( root ), [] );
} );

test( 'a process has one start time, however often it is asked', () => {
	const first = startedAt( process.pid );

	assert.notEqual( first, '' );

	// On Linux it must come from /proc, not from `ps -o lstart`: WSL moves the
	// boot time that lstart adds, and a live run read as dead lost its lock.
	if ( existsSync( '/proc/self/stat' ) ) {
		assert.match( first, /^ticks:[0-9]+$/ );
	}

	for ( let i = 0; i < 20; i++ ) {
		assert.equal( startedAt( process.pid ), first );
	}

	assert.equal( startedAt( deadPid() ), '' );
} );

test( 'a live holder is refused, and its lock is left as it was', () => {
	const root = site();
	lock( root, process.pid );
	const before = readFileSync( path.join( root, LOCK_FILE ), 'utf8' );

	// The parent is alive for as long as this test is.
	assert.throws( () => lock( root, process.ppid ), /another run/ );

	assert.equal(
		readFileSync( path.join( root, LOCK_FILE ), 'utf8' ),
		before
	);
	assert.deepEqual( leftovers( root ), [] );
} );

test( 'a dead holder is taken over', () => {
	const root = site();
	const dead = deadPid();
	writeFileSync(
		path.join( root, LOCK_FILE ),
		JSON.stringify( { pid: dead, started: 'Thu Jan  1 00:00:00 2026' } )
	);

	lock( root, process.pid );

	assert.equal( holder( root ).pid, process.pid );
	assert.deepEqual( leftovers( root ), [] );
} );

test( 'a reused pid does not keep a dead run alive', () => {
	const root = site();

	// Our pid, alive, but not the process that took this lock.
	writeFileSync(
		path.join( root, LOCK_FILE ),
		JSON.stringify( {
			pid: process.ppid,
			started: 'Thu Jan  1 00:00:00 2026',
		} )
	);

	lock( root, process.pid );

	assert.equal( holder( root ).pid, process.pid );
} );

test( 'a lock that is not ours is never released', () => {
	const root = site();
	lock( root, process.pid );

	assert.equal( unlock( root, process.ppid ), false );
	assert.equal( holder( root ).pid, process.pid );

	assert.equal( unlock( root, process.pid ), true );
	assert.equal( existsSync( path.join( root, LOCK_FILE ) ), false );
	assert.equal( unlock( root, process.pid ), false );
} );

test( 'a runner that is not running cannot take the lock', () => {
	const root = site();

	assert.throws( () => lock( root, deadPid() ), /not running/ );
	assert.equal( existsSync( path.join( root, LOCK_FILE ) ), false );
} );

/**
 * Several processes, each locking for itself and staying alive long enough
 * that its lock is live while the others try.
 */
async function race( root, racers ) {
	const script = `
		import { lock } from ${ JSON.stringify( MODULE ) };
		try {
			lock( ${ JSON.stringify( root ) }, process.pid );
			console.log( 'won' );
		} catch ( error ) {
			console.log( /another run/.test( error.message ) ? 'refused' : 'error: ' + error.message );
		}
		setTimeout( () => {}, 1500 );
	`;

	const outcomes = await Promise.all(
		Array.from(
			{ length: racers },
			() =>
				new Promise( ( resolve ) => {
					const child = spawn(
						process.execPath,
						[ '--input-type=module', '-e', script ],
						{ stdio: [ 'ignore', 'pipe', 'inherit' ] }
					);
					let out = '';
					child.stdout.on( 'data', ( chunk ) => ( out += chunk ) );
					child.on( 'close', () => resolve( out.trim() ) );
				} )
		)
	);

	return outcomes.sort();
}

test( 'of several runs starting at once, exactly one holds a free site', async () => {
	const root = site();
	const outcomes = await race( root, 8 );

	assert.deepEqual( outcomes, [
		'refused',
		'refused',
		'refused',
		'refused',
		'refused',
		'refused',
		'refused',
		'won',
	] );
	assert.deepEqual( leftovers( root ), [] );
} );

test( 'of several runs taking over a stale lock at once, exactly one wins', async () => {
	for ( let round = 0; round < 5; round++ ) {
		const root = site();
		writeFileSync(
			path.join( root, LOCK_FILE ),
			JSON.stringify( {
				pid: deadPid(),
				started: 'Thu Jan  1 00:00:00 2026',
			} )
		);

		const outcomes = await race( root, 8 );

		assert.equal(
			outcomes.filter( ( outcome ) => 'won' === outcome ).length,
			1,
			`round ${ round }: ${ outcomes.join( ', ' ) }`
		);
		assert.equal(
			outcomes.filter( ( outcome ) => 'refused' === outcome ).length,
			7,
			`round ${ round }: ${ outcomes.join( ', ' ) }`
		);
		assert.deepEqual( leftovers( root ), [] );
	}
} );
