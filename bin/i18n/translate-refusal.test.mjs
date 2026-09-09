/**
 * The refusal path, driven through the code that raises it.
 *
 * The tests in run-completeness.test.mjs build a tagged error by hand, which
 * proves the classifier reads a tag and nothing about whether `mt()` ever sets
 * one. That is the failure this project keeps meeting — a read half and a write
 * half that each pass their own tests and never meet — so these drive the real
 * `mt()` with a stubbed provider and assert on what it actually throws.
 *
 * The two responses below are the ones that failed three runs on master: a
 * translation memory answering "All campaigns" out of a neighbouring
 * "%d campaigns", and one dropping the "%s" it was given.
 */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import assert from 'node:assert/strict';
import test, { afterEach } from 'node:test';

import { mt, translatePoFile } from './translate.mjs';
import { classifyMtFailure } from './run-completeness.mjs';
import { findPlaceholderMismatches } from './lint-placeholders.mjs';
import { parsePo } from './po.mjs';

const CODES = { mymemory: 'de', deepl: 'DE' };
const realFetch = globalThis.fetch;

afterEach( () => {
	globalThis.fetch = realFetch;
} );

/** Answers every request with one MyMemory translation. */
function stubProvider( translatedText ) {
	globalThis.fetch = async () => ( {
		ok: true,
		status: 200,
		json: async () => ( {
			responseStatus: 200,
			responseData: { translatedText },
		} ),
	} );
}

test( 'an invented placeholder is refused, and tagged as a refusal', async () => {
	stubProvider( 'Alle %d Kampagnen' );

	const err = await mt( 'All campaigns', CODES, 'mymemory', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.ok( err, 'a translation inventing %d must not be accepted' );
	assert.match( err.message, /wrong placeholders/ );
	assert.match( err.message, /expected none/ );
	assert.match( err.message, /got %d/ );
	assert.equal(
		classifyMtFailure( err ),
		'refused',
		'mt() must tag it so the run skips the string instead of the locale'
	);
} );

test( 'a dropped placeholder is refused too', async () => {
	stubProvider( 'Diese Auslieferungsrichtlinie kann nicht verwendet werden' );

	const err = await mt(
		'That delivery policy cannot be used: %s',
		CODES,
		'mymemory',
		'de_DE'
	).then(
		() => null,
		( e ) => e
	);

	assert.ok( err );
	assert.equal( classifyMtFailure( err ), 'refused' );
} );

test( 'a refusal naming a source that says "Limit" is still a refusal', async () => {
	// The message quotes the source, and "Limit to one advertiser" is a real
	// string here. Classifying on the message read that as an exhausted quota
	// and abandoned the rest of the locale.
	stubProvider( 'Auf einen Werbetreibenden %d beschränken' );

	const err = await mt(
		'Limit to one advertiser',
		CODES,
		'mymemory',
		'de_DE'
	).then(
		() => null,
		( e ) => e
	);

	assert.ok( err );
	assert.match( err.message, /Limit to one advertiser/ );
	assert.equal( classifyMtFailure( err ), 'refused' );
} );

test( 'a good translation still comes back', async () => {
	stubProvider( 'Alle Kampagnen' );

	const result = await mt( 'All campaigns', CODES, 'mymemory', 'de_DE' );

	assert.equal( result.text, 'Alle Kampagnen' );
	assert.equal( result.via, 'mymemory' );
} );

test( 'a placeholder that survives translation is preserved', async () => {
	stubProvider( 'Verwendet: __AGGR_PH_0__' );

	const result = await mt( 'Used: %s', CODES, 'mymemory', 'de_DE' );

	assert.equal( result.text, 'Verwendet: %s' );
} );

test( 'a provider quota error is not mistaken for a refusal', async () => {
	globalThis.fetch = async () => ( { ok: false, status: 429 } );

	const err = await mt( 'All campaigns', CODES, 'mymemory', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.ok( err );
	assert.equal(
		classifyMtFailure( err ),
		'provider-stop',
		'the locale must still stop when the provider stops answering'
	);
} );

/*
 * The whole loop, against a real catalog on disk. Everything above proves one
 * string's verdict; this proves what the run does with it — that a refusal
 * skips the string and not the locale, and that what lands on disk is a catalog
 * the validator accepts. That last assertion is the CI failure itself: three
 * strings out of a thousand made the workflow rewrite a catalog its own
 * validation step then rejected, on every push to master.
 */
test( 'a refused string does not cost the rest of the locale', async () => {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'aggr-i18n-' ) );
	const file = path.join( dir, 'aggressive-ads-de_DE.po' );

	fs.writeFileSync(
		file,
		`msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"

msgid "All campaigns"
msgstr ""

msgid "Campaign name"
msgstr ""

msgid "That delivery policy cannot be used: %s"
msgstr ""

msgid "Organization"
msgstr ""
`,
		'utf8'
	);

	// The provider answers each string in turn: two of them the way the real
	// one did on master, two of them correctly.
	const answers = new Map( [
		[ 'All campaigns', 'Alle %d Kampagnen' ],
		[ 'Campaign name', 'Kampagnenname' ],
		[
			'That delivery policy cannot be used: __AGGR_PH_0__',
			'Diese Auslieferungsrichtlinie kann nicht verwendet werden',
		],
		[ 'Organization', 'Organisation' ],
	] );

	globalThis.fetch = async ( url ) => {
		const query = new URL( url ).searchParams.get( 'q' );

		return {
			ok: true,
			status: 200,
			json: async () => ( {
				responseStatus: 200,
				responseData: { translatedText: answers.get( query ) },
			} ),
		};
	};

	const previousProvider = process.env.I18N_MT_PROVIDER;
	const previousDelay = process.env.I18N_MT_DELAY_MS;
	process.env.I18N_MT_PROVIDER = 'mymemory';
	process.env.I18N_MT_DELAY_MS = '0';

	try {
		const result = await translatePoFile( file, {
			dryRun: false,
			limit: Infinity,
		} );

		// The locale kept going: both good strings were filled, and they sit
		// on either side of a refusal in the file.
		assert.equal( result.updated, 2 );
		assert.equal(
			result.truncated,
			false,
			'a refusal is not a quota stop'
		);
		assert.deepEqual( result.refused.sort(), [
			'All campaigns',
			'That delivery policy cannot be used: %s',
		] );

		const written = fs.readFileSync( file, 'utf8' );

		assert.match( written, /msgstr "Kampagnenname"/ );
		assert.match( written, /msgstr "Organisation"/ );
		assert.ok(
			! written.includes( 'Alle %d Kampagnen' ),
			'a translation that invented %d must not reach the catalog'
		);

		// The gate the workflow runs next, over what was actually written.
		assert.deepEqual(
			findPlaceholderMismatches( written ),
			[],
			'the catalog this run wrote must pass the validation that follows it'
		);
	} finally {
		process.env.I18N_MT_PROVIDER = previousProvider;
		process.env.I18N_MT_DELAY_MS = previousDelay;
		fs.rmSync( dir, { recursive: true, force: true } );
	}
} );

test( 'a bad machine translation already in the file is held back, not published', async () => {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'aggr-i18n-' ) );
	const file = path.join( dir, 'aggressive-ads-de_DE.po' );

	/*
	 * The state the failing runs were actually in: a finished-looking
	 * `aggr-mt` entry whose translation carries a placeholder its source never
	 * had, and one missing a `%s` it should have kept. How the second was
	 * written was never established, which is the reason this backstop exists
	 * rather than only the request-time refusal.
	 */
	fs.writeFileSync(
		file,
		`msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"

#, aggr-mt
msgid "All campaigns"
msgstr "Alle %d Kampagnen"

#, aggr-mt
msgid "That delivery policy cannot be used: %s"
msgstr "Diese Auslieferungsrichtlinie kann nicht verwendet werden"

#, aggr-mt
msgid "Organization"
msgstr "Organisation"

msgid "Reviewed by a person: %s"
msgstr "Von einer Person geprüft"
`,
		'utf8'
	);

	globalThis.fetch = async () => {
		throw new Error( 'the provider must not be called for this catalog' );
	};

	const previousProvider = process.env.I18N_MT_PROVIDER;
	process.env.I18N_MT_PROVIDER = 'mymemory';

	try {
		const result = await translatePoFile( file, {
			dryRun: false,
			limit: Infinity,
		} );

		assert.equal( result.updated, 0, 'nothing here needed translating' );
		assert.deepEqual( result.refused.sort(), [
			'All campaigns',
			'That delivery policy cannot be used: %s',
		] );

		const written = fs.readFileSync( file, 'utf8' );

		const flagsFor = ( msgid ) =>
			[
				...parsePo( written ).entries.find( ( e ) => e.msgid === msgid )
					.flags,
			].sort();

		// Held back as drafts rather than deleted: the text is still there for
		// a human to correct, and gettext falls back to English meanwhile.
		assert.deepEqual( flagsFor( 'All campaigns' ), [ 'aggr-mt', 'fuzzy' ] );
		assert.match( written, /msgstr "Alle %d Kampagnen"/ );

		// A correct machine translation is untouched.
		assert.deepEqual( flagsFor( 'Organization' ), [ 'aggr-mt' ] );

		// And a human translation is never relabelled as machine output.
		assert.deepEqual( flagsFor( 'Reviewed by a person: %s' ), [] );

		/*
		 * The human's broken entry is deliberately left alone, so the lint
		 * still reports it. Quietly flagging somebody's work fuzzy would hide
		 * a problem they are the only one who can fix.
		 */
		const problems = findPlaceholderMismatches( written );

		assert.equal( problems.length, 1 );
		assert.match( problems[ 0 ], /Reviewed by a person: %s/ );
	} finally {
		process.env.I18N_MT_PROVIDER = previousProvider;
		fs.rmSync( dir, { recursive: true, force: true } );
	}
} );

test( 'translating one string does not rewrite the flags of every other', async () => {
	/*
	 * This is how the third failing string on master got there, and it is
	 * reproduced here exactly: msgmerge fuzzy-matched a new
	 * "…cannot be used: %s" onto the older translation of the same sentence
	 * without the "%s" and marked it fuzzy, which is correct and is what
	 * fuzzy is for. serializeEntry() then cleared `fuzzy` on every entry it
	 * wrote — the whole catalog, not the one string this run translated — so
	 * the draft was published as finished, the lint stopped skipping it, and
	 * the job failed on a placeholder the machine had never touched.
	 *
	 * The same code stamped `aggr-mt` on every entry, including reviewed human
	 * translations, and resume-progress.mjs keys on that flag to decide what
	 * counts as a machine draft it may restore over.
	 */
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'aggr-i18n-' ) );
	const file = path.join( dir, 'aggressive-ads-de_DE.po' );

	fs.writeFileSync(
		file,
		`msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\\n"

#, fuzzy
msgid "That delivery policy cannot be used: %s"
msgstr "Diese Auslieferungsrichtlinie kann nicht verwendet werden"

msgid "Reviewed by a person"
msgstr "Von einer Person geprüft"

msgid "Untranslated"
msgstr ""
`,
		'utf8'
	);

	stubProvider( 'Nicht übersetzt' );

	const previousProvider = process.env.I18N_MT_PROVIDER;
	const previousDelay = process.env.I18N_MT_DELAY_MS;
	process.env.I18N_MT_PROVIDER = 'mymemory';
	process.env.I18N_MT_DELAY_MS = '0';

	try {
		await translatePoFile( file, { dryRun: false, limit: Infinity } );

		const written = fs.readFileSync( file, 'utf8' );
		const byId = new Map(
			parsePo( written ).entries.map( ( e ) => [ e.msgid, e ] )
		);

		assert.deepEqual(
			[ ...byId.get( 'That delivery policy cannot be used: %s' ).flags ],
			[ 'fuzzy' ],
			'a fuzzy entry this run never filled must still be fuzzy'
		);

		assert.deepEqual(
			[ ...byId.get( 'Reviewed by a person' ).flags ],
			[],
			'a human translation must not be relabelled as machine output'
		);

		assert.deepEqual(
			[ ...byId.get( 'Untranslated' ).flags ],
			[ 'aggr-mt' ],
			'the string this run did translate is flagged, and only it'
		);

		// The point of keeping the flag: the validation step that runs next
		// skips drafts, so a fuzzy mismatch is not a failure.
		assert.deepEqual( findPlaceholderMismatches( written ), [] );
	} finally {
		process.env.I18N_MT_PROVIDER = previousProvider;
		process.env.I18N_MT_DELAY_MS = previousDelay;
		fs.rmSync( dir, { recursive: true, force: true } );
	}
} );

test( 'an unsupported locale keeps the result contract', async () => {
	const result = await translatePoFile(
		path.join( os.tmpdir(), 'aggressive-ads-xx_YY.po' ),
		{ dryRun: false, limit: Infinity }
	);

	assert.deepEqual( result, {
		locale: 'xx_YY',
		updated: 0,
		skipped: 0,
		remaining: 0,
		truncated: false,
		refused: [],
	} );
} );
