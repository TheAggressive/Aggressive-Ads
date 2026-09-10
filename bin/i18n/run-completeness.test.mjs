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

import {
	MT_REFUSED,
	classifyMtFailure,
	judgeRun,
	providerMix,
	refusalAnnotations,
} from './run-completeness.mjs';
import { resumeCatalog } from './resume-progress.mjs';

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

const poHeader = `msgid ""
msgstr ""
"Language: de\\n"

`;

test( 'resume restores tagged MT into an empty master entry', () => {
	const base = `${ poHeader }#: current.php:10
#, php-format
msgid "Hello %s"
msgstr ""
`;
	const draft = `${ poHeader }#. Auto-translated (aggr-mt) via deepl — review before release.
#: old.php:2
#, php-format, aggr-mt
msgid "Hello %s"
msgstr "Hallo %s"
`;
	const result = resumeCatalog( base, draft );

	assert.equal( result.restored, 1 );
	assert.match( result.content, /current\.php:10/ );
	assert.doesNotMatch( result.content, /old\.php:2/ );
	assert.match( result.content, /aggr-mt/ );
	assert.match( result.content, /msgstr "Hallo %s"/ );
} );

test( 'resume never overwrites a clean translation already on master', () => {
	const base = `${ poHeader }#: current.php:10
msgid "Hello"
msgstr "Mensch"
`;
	const draft = `${ poHeader }#. Auto-translated (aggr-mt) via deepl — review before release.
#, aggr-mt
msgid "Hello"
msgstr "Maschine"
`;
	const result = resumeCatalog( base, draft );

	assert.equal( result.restored, 0 );
	assert.match( result.content, /msgstr "Mensch"/ );
	assert.doesNotMatch( result.content, /Maschine/ );
} );

test( 'resume ignores untagged translations from a stale draft', () => {
	const base = `${ poHeader }msgid "Hello"
msgstr ""
`;
	const draft = `${ poHeader }msgid "Hello"
msgstr "Hallo"
`;
	const result = resumeCatalog( base, draft );

	assert.equal( result.restored, 0 );
	assert.match( result.content, /msgstr ""/ );
	assert.doesNotMatch( result.content, /Hallo/ );
} );

test( 'a refused string is told apart from an exhausted quota', () => {
	const refusal = new Error(
		'MT returned the wrong placeholders (source=Limit to one advertiser…)'
	);
	refusal.code = MT_REFUSED;

	/*
	 * "Limit to one advertiser" is a real string in this plugin, and the
	 * refusal message quotes the source so a human can see which one broke.
	 * Sniffing /LIMIT/i across the whole message therefore read a bad
	 * translation as an exhausted quota and abandoned the rest of the locale,
	 * reporting "the provider stopped the run" about a provider that was fine.
	 */
	assert.equal( classifyMtFailure( refusal ), 'refused' );

	const untagged = new Error(
		'MT returned the wrong placeholders (source=Limit to one advertiser…)'
	);

	assert.equal(
		classifyMtFailure( untagged ),
		'provider-stop',
		'the tag, not the message, is what separates the two'
	);

	assert.equal(
		classifyMtFailure( new Error( 'MyMemory HTTP 429' ) ),
		'provider-stop'
	);
	assert.equal(
		classifyMtFailure( new Error( 'DeepL HTTP 456: quota' ) ),
		'provider-stop'
	);
	assert.equal(
		classifyMtFailure( new Error( 'socket hang up' ) ),
		'retryable'
	);
} );

test( 'refused strings do not make a finished pass look unfinished', () => {
	const verdict = judgeRun( [
		{
			locale: 'de_DE',
			updated: 1011,
			skipped: 0,
			remaining: 3,
			truncated: false,
			refused: [
				'All campaigns',
				'Show all campaigns',
				'That delivery policy cannot be used: %s',
			],
		},
	] );

	/*
	 * The deadlock this clears: the three strings are left untranslated on
	 * purpose, the next run asks the same provider and is refused again, so a
	 * run judged incomplete here can never become complete — and because
	 * validation runs before the draft branch is written, every run threw away
	 * the other 1011 translations too.
	 */
	assert.equal( verdict.ok, true );
	assert.deepEqual( verdict.problems, [] );
} );

test( 'a refusal does not excuse the strings nothing tried to translate', () => {
	const verdict = judgeRun( [
		{
			locale: 'de_DE',
			updated: 900,
			skipped: 0,
			remaining: 114,
			truncated: false,
			refused: [ 'All campaigns' ],
		},
	] );

	assert.equal( verdict.ok, false );
	assert.equal( verdict.problems.length, 1 );
	assert.match(
		verdict.problems[ 0 ],
		/de_DE: 113 entries still untranslated/
	);
} );

test( 'a truncated locale still fails even if some strings were refused', () => {
	const verdict = judgeRun( [
		{
			locale: 'fr_FR',
			updated: 10,
			skipped: 0,
			remaining: 1004,
			truncated: true,
			refused: [ 'All campaigns' ],
		},
	] );

	assert.equal( verdict.ok, false );
	assert.match(
		verdict.problems[ 0 ],
		/fr_FR: the provider stopped the run/
	);
} );

test( 'refused strings are annotated on the run, not just logged', () => {
	const lines = refusalAnnotations( [
		{
			locale: 'de_DE',
			updated: 1,
			skipped: 0,
			remaining: 2,
			truncated: false,
			refused: [ 'All campaigns', 'Used: %s' ],
		},
		{
			locale: 'fr_FR',
			updated: 3,
			skipped: 0,
			remaining: 0,
			truncated: false,
			refused: [],
		},
	] );

	assert.equal( lines.length, 1, 'only the locale that refused anything' );
	assert.match( lines[ 0 ], /^::warning title=/ );
	assert.match(
		lines[ 0 ],
		/2 string\(s\) in de_DE need a human translator/
	);

	/*
	 * A refused string is refused *because of its placeholders*, so almost
	 * every message this encodes contains a literal % for the runner to
	 * misread. Escaping % after the newline escapes would corrupt the %0A the
	 * previous replacement had just written.
	 */
	assert.match( lines[ 0 ], /Used: %25s/ );
	assert.doesNotMatch( lines[ 0 ], /Used: %s/ );
	assert.match( lines[ 0 ], /%0A/ );
	assert.doesNotMatch(
		lines[ 0 ],
		/%250A/,
		'the newline escape must not itself be escaped'
	);

	// One annotation per locale: the message is multiline, the line is not.
	assert.equal( lines[ 0 ].split( '\n' ).length, 1 );
} );

test( 'a run that refused nothing annotates nothing', () => {
	assert.deepEqual(
		refusalAnnotations( [ complete( 'de_DE' ), complete( 'fr_FR' ) ] ),
		[]
	);
} );

test( 'a draft from the fallback engine says so, loudly', () => {
	/*
	 * Two runs — 718 strings — went out entirely from MyMemory because DeepL
	 * answered `456 Quota exceeded`, and the only trace was a `via` tag inside
	 * a PO comment. The pull request named no engine and the job was red for an
	 * unrelated reason. The draft was reviewed on its German, found about a
	 * third defective, and closed.
	 */
	const mix = providerMix( [
		{
			locale: 'de_DE',
			updated: 328,
			skipped: 0,
			remaining: 0,
			truncated: false,
			refused: [],
			providers: { 'mymemory-fallback': 328 },
		},
	] );

	assert.equal( mix.degraded, true );
	assert.equal( mix.total, 328 );
	assert.match( mix.summary, /\[!WARNING\]/ );
	assert.match( mix.summary, /fallback engine/ );
	assert.match( mix.summary, /mymemory-fallback` — 328 strings/ );
} );

test( 'a run on the preferred engine carries no warning', () => {
	const mix = providerMix( [
		{
			locale: 'de_DE',
			updated: 300,
			skipped: 0,
			remaining: 0,
			truncated: false,
			refused: [],
			providers: { deepl: 300 },
		},
		{
			locale: 'fr_FR',
			updated: 100,
			skipped: 0,
			remaining: 0,
			truncated: false,
			refused: [],
			providers: { deepl: 100 },
		},
	] );

	assert.equal( mix.degraded, false );
	assert.equal( mix.total, 400 );
	assert.doesNotMatch( mix.summary, /WARNING/ );
	assert.match( mix.summary, /`deepl` — 400 strings/ );
} );

test( 'a mixed run is counted across locales and still warns', () => {
	// One locale finishing on the preferred engine does not make the draft
	// safe: the reviewer needs to know part of it did not.
	const mix = providerMix( [
		{
			locale: 'de_DE',
			updated: 10,
			skipped: 0,
			remaining: 0,
			truncated: false,
			refused: [],
			providers: { deepl: 6, 'mymemory-fallback': 4 },
		},
		{
			locale: 'fr_FR',
			updated: 5,
			skipped: 0,
			remaining: 0,
			truncated: false,
			refused: [],
			providers: { 'mymemory-fallback': 5 },
		},
	] );

	assert.equal( mix.degraded, true );
	assert.equal( mix.total, 15 );
	assert.equal( mix.counts[ 'mymemory-fallback' ], 9 );
	assert.equal( mix.counts.deepl, 6 );
	// Largest first, so the engine that wrote most of it leads.
	assert.match(
		mix.summary,
		/mymemory-fallback` — 9 strings\n- `deepl` — 6 strings/
	);
} );

test( 'a run that translated nothing does not claim an engine', () => {
	const mix = providerMix( [
		{
			locale: 'de_DE',
			updated: 0,
			skipped: 0,
			remaining: 5,
			truncated: true,
			refused: [],
		},
	] );

	assert.equal( mix.degraded, false );
	assert.equal( mix.total, 0 );
	assert.match( mix.summary, /No strings were translated/ );
} );
