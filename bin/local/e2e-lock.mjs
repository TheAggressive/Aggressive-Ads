#!/usr/bin/env node
/**
 * One browser-suite run per site at a time.
 *
 *   node bin/local/e2e-lock.mjs lock   <site path> <runner pid>
 *   node bin/local/e2e-lock.mjs unlock <site path> <runner pid>
 *
 * The snapshot a run leaves in the site means "restore me" only when the run
 * that took it is dead. Without this, a second run started beside a first one
 * read the first's snapshot as a crash and restored the site under it,
 * mid-test.
 *
 * The lock names its runner by pid *and* that process's start time, because a
 * pid alone is reused: a run killed last week would otherwise be "alive" for as
 * long as some unrelated process held its number. A lock whose runner is gone is
 * stale and is taken over; one whose runner is alive is refused.
 *
 * Every step is atomic on its own — a lock appears whole through link(), which
 * fails if one exists, and a stale lock is removed only by the one racer whose
 * link() claimed it — so runs starting at the same moment cannot both hold it.
 * The race tests prove that with real processes.
 */

import { execFileSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import {
	existsSync,
	linkSync,
	readFileSync,
	realpathSync,
	rmSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const LOCK_FILE = '.aggr-e2e-lock';

/**
 * An identity for the process `pid` that no later process with the same pid
 * shares, or '' when no such process exists.
 *
 * On Linux, the start time from /proc in clock ticks since boot. Not
 * `ps -o lstart`: that adds the boot time, which WSL re-derives as its clock
 * syncs, so one live process reported two start times seconds apart — and the
 * second run that saw the other one took a live run's lock and restored the site
 * under it. Elsewhere (macOS, which has no /proc) `ps -o lstart`, whose boot time
 * does not move.
 *
 * @param {number} pid
 * @return {string}
 */
export function startedAt( pid ) {
	if ( existsSync( '/proc/self/stat' ) ) {
		let stat;

		try {
			stat = readFileSync( `/proc/${ pid }/stat`, 'utf8' );
		} catch {
			return '';
		}

		// Field 22. The command name before it is in parentheses and may
		// itself contain spaces or parentheses, so count from the last ')'.
		const fields = stat.slice( stat.lastIndexOf( ')' ) + 2 ).split( ' ' );

		return `ticks:${ fields[ 19 ] }`;
	}

	try {
		return execFileSync( 'ps', [ '-o', 'lstart=', '-p', String( pid ) ], {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'ignore' ],
		} ).trim();
	} catch {
		return '';
	}
}

/**
 * @param {string} held The lock file's contents.
 * @return {boolean} Whether the run it names is still running.
 */
function alive( held ) {
	let owner;

	try {
		owner = JSON.parse( held );
	} catch {
		// Never written by lock(), which only ever links a complete file.
		return false;
	}

	return '' !== owner.started && startedAt( owner.pid ) === owner.started;
}

/** Block for `ms`; lock() is synchronous and only ever waits briefly. */
function pause( ms ) {
	Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, ms );
}

/**
 * @param {string} file
 * @return {string|null} Its contents, or null when it is not there.
 */
function read( file ) {
	try {
		return readFileSync( file, 'utf8' );
	} catch ( error ) {
		if ( 'ENOENT' === error.code ) {
			return null;
		}

		throw error;
	}
}

/**
 * @param {string} site
 * @param {number} pid The runner holding the lock for the length of the run.
 */
export function lock( site, pid ) {
	const file = path.join( realpathSync( site ), LOCK_FILE );
	const started = startedAt( pid );

	if ( '' === started ) {
		throw new Error( `process ${ pid } is not running.` );
	}

	// The nonce makes every lock's contents unique, so "the lock I judged
	// stale" and "the lock there now" can be told apart by contents alone.
	const mine = `${ file }.${ pid }.${ process.pid }.new`;
	writeFileSync(
		mine,
		JSON.stringify( { pid, started, nonce: randomUUID() } )
	);

	try {
		for ( let attempt = 0; attempt < 40; attempt++ ) {
			try {
				linkSync( mine, file );
				return;
			} catch ( error ) {
				if ( 'EEXIST' !== error.code ) {
					throw error;
				}
			}

			const held = read( file );

			if ( null === held ) {
				continue;
			}

			if ( alive( held ) ) {
				const owner = JSON.parse( held );
				throw new Error(
					`another run (pid ${ owner.pid }, started ${ owner.started }) is using this site.`
				);
			}

			/*
			 * Stale. Only the racer that claims *this* stale lock may remove
			 * it, and only while it is still the file there. Moving it aside and
			 * putting back whatever turned out to be live was the first version
			 * of this, and with eight racers two of them won: a third linked
			 * its own lock into the gap before the live one could go back.
			 * Nothing here ever removes a lock that is not the stale one.
			 */
			const claim = `${ file }.takeover-${ createHash( 'sha256' )
				.update( held )
				.digest( 'hex' )
				.slice( 0, 16 ) }`;

			try {
				linkSync( mine, claim );
			} catch ( error ) {
				if ( 'EEXIST' !== error.code ) {
					throw error;
				}

				const claimant = read( claim );

				// A racer died in the microseconds it held the claim. Rare
				// enough that guessing is the wrong trade: refuse, and say
				// what to remove.
				if ( null !== claimant && ! alive( claimant ) ) {
					throw new Error(
						`an earlier run died taking over a stale lock. With no run active, remove ${ claim } and ${ file }.`
					);
				}

				pause( 25 );
				continue;
			}

			try {
				if ( read( file ) === held ) {
					unlinkSync( file );
				}
			} finally {
				unlinkSync( claim );
			}
		}

		throw new Error( 'could not take the lock; try again.' );
	} finally {
		rmSync( mine, { force: true } );
	}
}

/**
 * Release the lock, if `pid` holds it. Anyone else's is left alone.
 *
 * @param {string} site
 * @param {number} pid
 * @return {boolean} Whether it was released.
 */
export function unlock( site, pid ) {
	const file = path.join( realpathSync( site ), LOCK_FILE );
	let owner;

	try {
		owner = JSON.parse( readFileSync( file, 'utf8' ) );
	} catch {
		return false;
	}

	if ( owner.pid !== pid || owner.started !== startedAt( pid ) ) {
		return false;
	}

	unlinkSync( file );

	return true;
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	const [ command, site, pid ] = process.argv.slice( 2 );

	if (
		! [ 'lock', 'unlock' ].includes( command ) ||
		! site ||
		! /^[1-9][0-9]*$/.test( pid ?? '' )
	) {
		console.error( 'usage: e2e-lock.mjs lock|unlock <site path> <pid>' );
		process.exit( 2 );
	}

	try {
		if ( 'lock' === command ) {
			lock( site, Number( pid ) );
		} else if ( ! unlock( site, Number( pid ) ) ) {
			console.error( `e2e-lock: ${ pid } did not hold the lock.` );
			process.exit( 1 );
		}
	} catch ( error ) {
		console.error( `e2e-lock: ${ error.message }` );
		process.exit( 1 );
	}
}
