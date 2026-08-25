/**
 * Tests for the machine-translation completeness judgement.
 *
 * The case that matters is the one that shipped: a run that translated 390 of
 * 1014 entries in one locale and none at all in three others, exited zero, and
 * opened a pull request indistinguishable from a complete one. Every test here
 * asserts a *count* or a named locale rather than a bare boolean, because "the
 * run failed" is not the useful part — which locale, and how much it left
 * behind, is what a reviewer needs.
 */

import { strict as assert } from 'node:assert';
import test from 'node:test';

import { judgeRun } from './run-completeness.mjs';

/** A locale that finished cleanly. */
function complete( locale, updated = 1014 ) {
	return { locale, updated, skipped: 0, remaining: 0, truncated: false };
}

test( 'a run where every locale finished passes', () => {
	const { ok, problems } = judgeRun( [
		complete( 'de_DE' ),
		complete( 'fr_FR' ),
	] );

	assert.equal( ok, true );
	assert.deepEqual( problems, [] );
} );

test( 'a locale the provider cut short fails, and says how much is left', () => {
	// The shape of the real incident: quota died partway through German.
	const { ok, problems } = judgeRun( [
		{
			locale: 'de_DE',
			updated: 390,
			skipped: 0,
			remaining: 624,
			truncated: true,
		},
	] );

	assert.equal( ok, false );
	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /de_DE/ );
	assert.match( problems[ 0 ], /624 entries still untranslated/ );
	assert.match( problems[ 0 ], /filled 390/ );
} );

test( 'every unfinished locale is reported, not just the first', () => {
	/*
	 * The incident had four: one truncated and three that never started, and a
	 * verdict naming only the first would have sent somebody back for a second
	 * run to discover the next one.
	 */
	const { ok, problems } = judgeRun( [
		{
			locale: 'de_DE',
			updated: 390,
			skipped: 0,
			remaining: 624,
			truncated: true,
		},
		{
			locale: 'es_ES',
			updated: 0,
			skipped: 0,
			remaining: 1014,
			truncated: true,
		},
		{
			locale: 'fr_FR',
			updated: 0,
			skipped: 0,
			remaining: 1014,
			truncated: true,
		},
		{
			locale: 'it_IT',
			updated: 0,
			skipped: 0,
			remaining: 1014,
			truncated: true,
		},
	] );

	assert.equal( ok, false );
	assert.equal( problems.length, 4 );
	assert.match( problems.join( '\n' ), /es_ES/ );
	assert.match( problems.join( '\n' ), /it_IT/ );
} );

test( 'entries left untranslated without truncation still fail', () => {
	// No provider error, and yet the pass did not fill everything. That is a
	// different bug and it is still not a finished run.
	const { ok, problems } = judgeRun( [
		{
			locale: 'fr_FR',
			updated: 10,
			skipped: 4,
			remaining: 3,
			truncated: false,
		},
	] );

	assert.equal( ok, false );
	assert.match(
		problems[ 0 ],
		/3 entries still untranslated after a complete pass/
	);
} );

test( 'a --limit run is never judged incomplete', () => {
	// Stopping early is exactly what --limit is for, so treating its leftovers
	// as a failure would make the debugging tool unusable.
	const { ok } = judgeRun(
		[
			{
				locale: 'de_DE',
				updated: 40,
				skipped: 0,
				remaining: 974,
				truncated: false,
			},
		],
		{ limited: true }
	);

	assert.equal( ok, true );
} );

test( 'a run that processed no locale fails rather than passing vacuously', () => {
	// The empty-input trap: "no problems found" over nothing examined is the
	// failure mode every guard in this repository is written to avoid.
	const { ok, problems } = judgeRun( [] );

	assert.equal( ok, false );
	assert.match( problems[ 0 ], /no locale was processed/ );
} );

test( 'singular and plural read correctly, because the message is the product', () => {
	const one = judgeRun( [
		{
			locale: 'de_DE',
			updated: 1,
			skipped: 0,
			remaining: 1,
			truncated: true,
		},
	] );

	assert.match( one.problems[ 0 ], /1 entry still untranslated/ );

	const many = judgeRun( [
		{
			locale: 'de_DE',
			updated: 1,
			skipped: 0,
			remaining: 2,
			truncated: true,
		},
	] );

	assert.match( many.problems[ 0 ], /2 entries still untranslated/ );
} );
