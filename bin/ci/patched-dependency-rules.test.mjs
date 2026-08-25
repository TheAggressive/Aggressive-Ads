/**
 * Tests for the patched-dependency judgement.
 *
 * The case that matters is the one the guard actually had: it looped over
 * resolved copies of adm-zip asserting the patch was present in each, and if
 * that set came back empty the loop body never ran. The only assertion left was
 * a round-trip smoke test that an *unpatched* adm-zip passes just as happily —
 * so the guard printed "CVE-2026-39244 fix present" having verified nothing.
 *
 * That is not hypothetical. The script already records its parent list going
 * stale once, when `@wordpress/env` was removed. That time it threw, which is
 * the lucky outcome; an empty list is the same mistake failing quietly.
 */

import { strict as assert } from 'node:assert';
import test from 'node:test';

import {
	judgeRun,
	judgeSource,
	PATCHED_MARKER,
	VULNERABLE_MARKER,
} from './patched-dependency-rules.mjs';

/** A source file that carries the patch. */
const patched = `
	const data = ${ PATCHED_MARKER };
	return data;
`;

/** A source file that still has the vulnerable allocation. */
const vulnerable = `
	const data = ${ VULNERABLE_MARKER };
	return data;
`;

test( 'a patched copy passes', () => {
	assert.equal( judgeSource( patched ), null );
} );

test( 'the vulnerable allocation is named specifically', () => {
	// CVE-2026-39244: the output buffer was sized from the central-directory
	// header, which the archive supplies. A crafted archive declaring a huge
	// size makes the process allocate it.
	assert.match( judgeSource( vulnerable ), /attacker-declared output size/ );
} );

test( 'a source with neither marker is refused', () => {
	/*
	 * Absence of the vulnerable line is not evidence of the patch. An upstream
	 * refactor that renamed the variable would remove the marker and satisfy a
	 * one-sided check while saying nothing at all about the allocation — which
	 * is how a guard starts reporting on code it no longer understands.
	 */
	assert.match(
		judgeSource( 'const data = something.else();' ),
		/patch is missing/
	);
} );

test( 'an empty source is refused rather than passing vacuously', () => {
	assert.match( judgeSource( '' ), /empty/ );
	assert.match( judgeSource( undefined ), /empty/ );
} );

test( 'a run over one patched copy passes and reports the count', () => {
	const { ok, examined, problems } = judgeRun( [
		{ path: '/n_m/adm-zip/zipEntry.js', source: patched },
	] );

	assert.equal( ok, true );
	assert.equal( examined, 1 );
	assert.deepEqual( problems, [] );
} );

test( 'a run that resolved no copy fails rather than passing vacuously', () => {
	/*
	 * The failure this file exists for. Zero copies examined is not "no
	 * problems found" — it is the guard having verified nothing, and it is
	 * exactly what a stale parent list produces.
	 */
	const { ok, examined, problems } = judgeRun( [] );

	assert.equal( ok, false );
	assert.equal( examined, 0 );
	assert.match( problems[ 0 ], /no copy of adm-zip was resolved/ );
} );

test( 'a non-array input fails too', () => {
	// The same hole reached through a different mistake.
	assert.equal( judgeRun( undefined ).ok, false );
	assert.equal( judgeRun( null ).ok, false );
} );

test( 'one bad copy among several fails the run and names its path', () => {
	// Several parents can pull their own copy. Judging only the first would let
	// an unpatched second copy ship.
	const { ok, problems, examined } = judgeRun( [
		{ path: '/a/adm-zip/zipEntry.js', source: patched },
		{ path: '/b/adm-zip/zipEntry.js', source: vulnerable },
	] );

	assert.equal( ok, false );
	assert.equal( examined, 2 );
	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /\/b\/adm-zip/ );
} );

test( 'every bad copy is reported, not just the first', () => {
	const { problems } = judgeRun( [
		{ path: '/a/adm-zip/zipEntry.js', source: vulnerable },
		{ path: '/b/adm-zip/zipEntry.js', source: 'unrelated' },
	] );

	assert.equal( problems.length, 2 );
	assert.match( problems.join( '\n' ), /\/a\/adm-zip/ );
	assert.match( problems.join( '\n' ), /\/b\/adm-zip/ );
} );
