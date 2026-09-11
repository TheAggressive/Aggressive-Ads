/**
 * What a run does to a catalog when the model answers badly.
 *
 * The per-string verdicts live in providers-local.test.mjs; these drive the
 * whole catalog walk and assert what lands on disk — that a refusal costs one
 * string and not the locale, that a bad translation already in the file is held
 * back, and that entries the run never touched come back unchanged.
 *
 * The answers below are the ones that failed three runs on master: an engine
 * inventing a "%d" for "All campaigns", and one dropping the "%s" it was given.
 */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import assert from 'node:assert/strict';
import test, { afterEach, beforeEach } from 'node:test';

import { mt } from './providers.mjs';
import { translatePoFile } from './translate.mjs';
import { classifyMtFailure } from './run-completeness.mjs';
import { findPlaceholderMismatches } from './lint-placeholders.mjs';
import { parsePo } from './po.mjs';

/**
 * Puts an environment variable back, including putting it back to absent.
 *
 * `process.env.X = undefined` stores the *string* "undefined", which is truthy
 * — so a test that had set a key left every later test believing one was
 * configured, and a test then ran against a provider it never meant to use.
 *
 * @param {string} name
 * @param {string|undefined} previous
 */
function restoreEnv( name, previous ) {
	if ( undefined === previous ) {
		delete process.env[ name ];

		return;
	}

	process.env[ name ] = previous;
}

const realFetch = globalThis.fetch;
const savedEnv = {};

beforeEach( () => {
	for ( const name of [
		'I18N_LOCAL_URL',
		'I18N_LOCAL_MODEL',
		'I18N_MT_PROVIDER',
		'I18N_MT_DELAY_MS',
		'I18N_LOCAL_RETRY_DELAYS_MS',
	] ) {
		savedEnv[ name ] = process.env[ name ];
	}

	process.env.I18N_LOCAL_URL = 'http://model.test:1234/v1';
	process.env.I18N_LOCAL_MODEL = 'test-model';
	process.env.I18N_LOCAL_RETRY_DELAYS_MS = '0,0';
} );

afterEach( () => {
	globalThis.fetch = realFetch;

	for ( const [ name, value ] of Object.entries( savedEnv ) ) {
		restoreEnv( name, value );
	}
} );

/** Answers every chat completion with one translation. */
function stubProvider( translatedText ) {
	globalThis.fetch = async () => ( {
		ok: true,
		status: 200,
		json: async () => ( {
			choices: [ { message: { content: translatedText } } ],
		} ),
	} );
}

/**
 * Sets the local provider up for a catalog-walk test.
 *
 * @return {() => void} Restores what it changed.
 */
function useLocalProvider() {
	const previous = {
		provider: process.env.I18N_MT_PROVIDER,
		delay: process.env.I18N_MT_DELAY_MS,
		url: process.env.I18N_LOCAL_URL,
		model: process.env.I18N_LOCAL_MODEL,
	};

	process.env.I18N_MT_PROVIDER = 'local';
	process.env.I18N_MT_DELAY_MS = '0';
	process.env.I18N_LOCAL_URL = 'http://model.test:1234/v1';
	process.env.I18N_LOCAL_MODEL = 'test-model';

	return () => {
		restoreEnv( 'I18N_MT_PROVIDER', previous.provider );
		restoreEnv( 'I18N_MT_DELAY_MS', previous.delay );
		restoreEnv( 'I18N_LOCAL_URL', previous.url );
		restoreEnv( 'I18N_LOCAL_MODEL', previous.model );
	};
}

test( 'an invented placeholder is refused, and tagged as a refusal', async () => {
	stubProvider( 'Alle %d Kampagnen' );

	const err = await mt( 'All campaigns', 'de_DE' ).then(
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

	globalThis.fetch = async ( url, init ) => {
		if ( String( url ).endsWith( '/models' ) ) {
			return {
				ok: true,
				status: 200,
				json: async () => ( { data: [ { id: 'test-model' } ] } ),
			};
		}

		const asked = JSON.parse( init.body ).messages[ 1 ].content;

		return {
			ok: true,
			status: 200,
			json: async () => ( {
				choices: [ { message: { content: answers.get( asked ) } } ],
			} ),
		};
	};

	const restore = useLocalProvider();

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
		restore();
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

	const restore = useLocalProvider();

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
		restore();
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
	 * translations, and that flag is what marks an entry as machine-written
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

	const restore = useLocalProvider();

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
		restore();
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

test( 'the German glossary corrects the terms the review pass caught', async () => {
	// Applied to MT output, so the assertions go through mt() rather than
	// calling the glossary directly — a rule nothing applies is not a rule.
	stubProvider( 'Neue Konvertierung' );
	assert.equal(
		( await mt( 'New conversion', 'de_DE' ) ).text,
		'Neue Conversion'
	);

	// Plural first, or the singular rule turns it into "Conversionen".
	stubProvider( 'Es sind noch keine Konvertierungen definiert.' );
	assert.equal(
		( await mt( 'No conversions are defined yet.', 'de_DE' ) ).text,
		'Es sind noch keine Conversions definiert.'
	);

	stubProvider( 'Fenster Namensnennung' );
	assert.equal(
		( await mt( 'Attribution window', 'de_DE' ) ).text,
		'Attributionsfenster'
	);

	stubProvider( 'Befundungsschlüssel' );
	assert.equal(
		( await mt( 'Reporting key', 'de_DE' ) ).text,
		'Berichtsschlüssel'
	);

	// A locale with no glossary is untouched.
	stubProvider( 'Nueva Konvertierung' );
	assert.equal(
		( await mt( 'New conversion', 'es_ES' ) ).text,
		'Nueva Konvertierung'
	);
} );
