/**
 * The local provider: an OpenAI-compatible `/v1/chat/completions` server the
 * publisher runs themselves.
 *
 * Driven through `mt()` with a stubbed server, so what is asserted is what the
 * catalog walk would actually receive — the request that went out, and what of
 * the answer was kept.
 */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import assert from 'node:assert/strict';
import test, { afterEach, beforeEach } from 'node:test';

import {
	checkLocalProvider,
	localProviderDownMessage,
	localRunIncompleteMessage,
	localSystemPrompt,
	mt,
} from './providers.mjs';
import { classifyMtFailure } from './run-completeness.mjs';
import { translatePoFile, translatorNotes } from './translate.mjs';

const URL_BASE = 'http://model.test:1234/v1';
const MODEL = 'qwen/qwen3.8-27b';
const realFetch = globalThis.fetch;
const saved = {};

/**
 * Restores an environment variable, including restoring it to absent.
 *
 * @param {string}           name
 * @param {string|undefined} previous
 */
function restoreEnv( name, previous ) {
	if ( undefined === previous ) {
		delete process.env[ name ];

		return;
	}

	process.env[ name ] = previous;
}

beforeEach( () => {
	for ( const name of [
		'I18N_LOCAL_URL',
		'I18N_LOCAL_MODEL',
		'I18N_LOCAL_API_KEY',
		'I18N_MT_PROVIDER',
		'I18N_LOCAL_RETRY_DELAYS_MS',
		'I18N_MT_DELAY_MS',
		'NO_COLOR',
		'FORCE_COLOR',
	] ) {
		saved[ name ] = process.env[ name ];
	}

	process.env.I18N_LOCAL_URL = URL_BASE;
	process.env.I18N_LOCAL_MODEL = MODEL;
	delete process.env.I18N_LOCAL_API_KEY;

	// Two quick retries rather than two minutes of real backoff.
	process.env.I18N_LOCAL_RETRY_DELAYS_MS = '0,0';
} );

afterEach( () => {
	globalThis.fetch = realFetch;

	for ( const [ name, value ] of Object.entries( saved ) ) {
		restoreEnv( name, value );
	}
} );

/**
 * A server answering every chat completion with one message, recording what
 * it was sent.
 *
 * @param {object} message The `message` object to return.
 * @return {Array<{url: string, init: object}>}
 */
function server( message ) {
	const calls = [];

	globalThis.fetch = async ( url, init ) => {
		calls.push( { url: String( url ), init } );

		return {
			ok: true,
			status: 200,
			json: async () => ( {
				choices: [ { index: 0, message, finish_reason: 'stop' } ],
			} ),
		};
	};

	return calls;
}

test( 'it sends an OpenAI-compatible chat completion at temperature 0', async () => {
	const calls = server( {
		role: 'assistant',
		content: 'Änderungen speichern',
	} );

	const result = await mt( 'Save changes', 'de_DE' );

	assert.equal( result.text, 'Änderungen speichern' );
	assert.equal( result.via, 'local' );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].url, `${ URL_BASE }/chat/completions` );
	assert.equal( calls[ 0 ].init.method, 'POST' );

	const body = JSON.parse( calls[ 0 ].init.body );

	assert.equal( body.model, MODEL );
	assert.equal( body.temperature, 0 );
	assert.equal( body.messages[ 0 ].role, 'system' );
	assert.equal( body.messages[ 1 ].role, 'user' );

	// The user message is exactly the text to translate, nothing wrapped round
	// it that the model might translate or echo back.
	assert.equal( body.messages[ 1 ].content, 'Save changes' );
} );

test( 'only the answer is kept, never the reasoning', async () => {
	// The shape the real server returned on the first probe.
	server( {
		role: 'assistant',
		content: 'Änderungen speichern',
		reasoning_content:
			'The user wants me to translate "Save changes" from English to German…',
	} );

	assert.equal(
		( await mt( 'Save changes', 'de_DE' ) ).text,
		'Änderungen speichern'
	);

	// And the inline form other servers use.
	server( {
		role: 'assistant',
		content: '<think>Formal register, so…</think>\nÄnderungen speichern',
	} );

	assert.equal(
		( await mt( 'Save changes', 'de_DE' ) ).text,
		'Änderungen speichern'
	);
} );

test( 'a quoted answer is unwrapped only when the source was not quoted', async () => {
	server( { role: 'assistant', content: '„Änderungen speichern“' } );

	assert.equal(
		( await mt( 'Save changes', 'de_DE' ) ).text,
		'Änderungen speichern'
	);
} );

test( 'a source that is itself quoted keeps its quotes', async () => {
	// The unwrap exists for a model quoting its whole answer. A label that was
	// quoted in English is quoted on purpose, and must stay so.
	server( { role: 'assistant', content: '„Standard“' } );

	assert.equal( ( await mt( '“Default”', 'de_DE' ) ).text, '„Standard“' );
} );

test( 'placeholders survive, and the existing gates still apply', async () => {
	server( {
		role: 'assistant',
		content: '__AGGR_PH_0__ Werbemittel wurden übersprungen',
	} );

	assert.equal(
		( await mt( '%d creatives were skipped', 'de_DE' ) ).text,
		'%d Werbemittel wurden übersprungen'
	);

	// A dropped token is refused exactly as it is for the other providers.
	server( { role: 'assistant', content: 'Werbemittel wurden übersprungen' } );

	const dropped = await mt( '%d creatives were skipped', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.ok( dropped );
	assert.equal( classifyMtFailure( dropped ), 'refused' );
} );

test( 'an echo and an empty answer are refused rather than filed', async () => {
	server( { role: 'assistant', content: 'Save changes' } );

	const echo = await mt( 'Save changes', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.ok( echo );
	assert.equal( classifyMtFailure( echo ), 'refused' );

	server( { role: 'assistant', content: '<think>Hmm.</think>   ' } );

	const empty = await mt( 'Save changes', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.ok( empty );
	assert.equal( classifyMtFailure( empty ), 'refused' );
} );

test( 'a named local provider never falls back to another one', async () => {
	// Only the local model is ever contacted, including when it is failing.
	const hosts = [];

	globalThis.fetch = async ( url ) => {
		hosts.push( new URL( String( url ) ).host );

		return { ok: false, status: 503, json: async () => ( {} ) };
	};

	const err = await mt( 'Save changes', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.ok( err, 'a failing local model must surface as an error' );
	assert.deepEqual(
		[ ...new Set( hosts ) ],
		[ 'model.test:1234' ],
		'something other than the local model was asked'
	);
} );

test( 'a model the server does not have stops the run, without retrying', async () => {
	let calls = 0;

	globalThis.fetch = async () => {
		calls += 1;

		return {
			ok: false,
			status: 404,
			text: async () => 'model not found',
			json: async () => ( {} ),
		};
	};

	const err = await mt( 'Save changes', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.equal( classifyMtFailure( err ), 'provider-stop' );
	assert.equal(
		calls,
		1,
		'a missing model cannot be waited back into existence'
	);
} );

test( 'context and the translator note reach the prompt', async () => {
	const calls = server( { role: 'assistant', content: '%d Tag' } );

	await mt(
		'%d day',
		'de_DE',
		'duration',
		'placeholder is a number of days.'
	);

	const system = JSON.parse( calls[ 0 ].init.body ).messages[ 0 ].content;

	assert.match( system, /It appears in this context: duration/ );
	assert.match(
		system,
		/Note from the developer: placeholder is a number of days\./
	);
} );

test( 'the German prompt carries the register and the reviewed terms', () => {
	const prompt = localSystemPrompt( 'de_DE' );

	assert.match( prompt, /German \(Germany\)/ );
	assert.match( prompt, /formally with "Sie"/ );

	// The defects the MyMemory drafts shipped, each now an instruction.
	assert.match( prompt, /creative \(the ad artwork, a noun\) → Werbemittel/ );
	assert.match( prompt, /screen width → Bildschirmbreite/ );

	// Product review: delivery and fill are separate concepts. What is
	// delivered is the ad; a request is served or processed.
	assert.match(
		prompt,
		/delivery; to deliver or serve an ad.* → Auslieferung; ausliefern/
	);
	assert.match( prompt, /never say the request itself was ausgeliefert/ );
	assert.doesNotMatch(
		prompt,
		/fill \(a request answered with an ad\) → Auslieferung/,
		'the blanket fill-and-delivery rule the review rejected is back'
	);

	// The first gate rendered "worth reporting" as the legal term.
	assert.match(
		prompt,
		/worth reporting .* → sollte gemeldet werden\. Never meldepflichtig/
	);

	// A locale with no terms still gets a usable prompt.
	assert.match( localSystemPrompt( 'nl_NL' ), /Dutch/ );
	assert.doesNotMatch( localSystemPrompt( 'nl_NL' ), /Use these terms/ );
} );

test( 'translator notes are extracted, and the run’s own marker is not one', () => {
	assert.equal(
		translatorNotes( {
			comments: [
				'#. translators: %d: number of days.',
				'#. Auto-translated (aggr-mt) via local — review before release.',
				'#: inc/Portal/class-view-data.php:12',
			],
		} ),
		'%d: number of days.'
	);
} );

test( 'the preflight names what is wrong before any catalog is touched', async () => {
	globalThis.fetch = async () => ( {
		ok: true,
		status: 200,
		json: async () => ( { data: [ { id: MODEL }, { id: 'embed' } ] } ),
	} );

	assert.deepEqual( await checkLocalProvider(), { ok: true, reason: '' } );

	globalThis.fetch = async () => ( {
		ok: true,
		status: 200,
		json: async () => ( { data: [ { id: 'some-other-model' } ] } ),
	} );

	const missing = await checkLocalProvider();

	assert.equal( missing.ok, false );
	assert.match( missing.reason, /does not serve "qwen\/qwen3\.8-27b"/ );
	assert.match( missing.reason, /some-other-model/ );

	globalThis.fetch = async () => {
		throw new Error( 'connect ECONNREFUSED' );
	};

	const down = await checkLocalProvider();

	assert.equal( down.ok, false );
	assert.match( down.reason, /cannot reach/ );

	delete process.env.I18N_LOCAL_URL;

	const unset = await checkLocalProvider();

	assert.equal( unset.ok, false );
	assert.match( unset.reason, /I18N_LOCAL_URL/ );
} );

test( 'a model being reloaded is waited for, not fatal', async () => {
	/*
	 * The first full German run stopped after 567 of 1,386 strings: the model
	 * was reloaded mid-run, LM Studio answered 400 until it was back, and one
	 * 400 was read as the server refusing for good.
	 */
	let calls = 0;

	globalThis.fetch = async () => {
		calls += 1;

		if ( 1 === calls ) {
			return {
				ok: false,
				status: 400,
				text: async () => 'Model is not loaded',
				json: async () => ( {} ),
			};
		}

		return {
			ok: true,
			status: 200,
			json: async () => ( {
				choices: [ { message: { content: 'Änderungen angefordert' } } ],
			} ),
		};
	};

	assert.equal(
		( await mt( 'Changes requested', 'de_DE' ) ).text,
		'Änderungen angefordert'
	);
	assert.equal( calls, 2, 'the rejected request was not retried' );
} );

test( 'a dropped connection is retried', async () => {
	let calls = 0;

	globalThis.fetch = async () => {
		calls += 1;

		if ( 1 === calls ) {
			throw new TypeError( 'fetch failed' );
		}

		return {
			ok: true,
			status: 200,
			json: async () => ( {
				choices: [ { message: { content: 'Änderungen speichern' } } ],
			} ),
		};
	};

	assert.equal(
		( await mt( 'Save changes', 'de_DE' ) ).text,
		'Änderungen speichern'
	);
	assert.equal( calls, 2 );
} );

test( 'a string a healthy server keeps rejecting is skipped, not fatal', async () => {
	globalThis.fetch = async ( url ) => {
		if ( String( url ).endsWith( '/models' ) ) {
			return {
				ok: true,
				status: 200,
				json: async () => ( { data: [ { id: MODEL } ] } ),
			};
		}

		return {
			ok: false,
			status: 400,
			text: async () => '{"error":"Context length exceeded"}',
			json: async () => ( {} ),
		};
	};

	const err = await mt( 'Save changes', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.ok( err );
	assert.equal(
		classifyMtFailure( err ),
		'retryable',
		'one rejected request stopped the whole locale'
	);
	assert.match(
		err.message,
		/Context length exceeded/,
		'the server’s reason was thrown away'
	);
} );

test( 'a server that stays down stops the run so a re-run can resume', async () => {
	globalThis.fetch = async ( url ) => {
		if ( String( url ).endsWith( '/models' ) ) {
			throw new Error( 'connect ECONNREFUSED' );
		}

		return {
			ok: false,
			status: 503,
			text: async () => 'unavailable',
			json: async () => ( {} ),
		};
	};

	const err = await mt( 'Save changes', 'de_DE' ).then(
		() => null,
		( e ) => e
	);

	assert.equal( classifyMtFailure( err ), 'provider-stop' );
	assert.match( err.message, /unavailable after retries/ );
} );

test( 'one rejected string does not cost the rest of a local run', async () => {
	// The incident, through the catalog walk: the rejected string sits in the
	// middle, so carrying on past it is what is being proved.
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'aggr-local-' ) );
	const file = path.join( dir, 'aggressive-ads-de_DE.po' );

	fs.writeFileSync(
		file,
		'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n' +
			'msgid "Save changes"\nmsgstr ""\n\n' +
			'msgid "Changes requested"\nmsgstr ""\n\n' +
			'msgid "End date"\nmsgstr ""\n',
		'utf8'
	);

	const answers = {
		'Save changes': 'Änderungen speichern',
		'End date': 'Enddatum',
	};

	globalThis.fetch = async ( url, init ) => {
		if ( String( url ).endsWith( '/models' ) ) {
			return {
				ok: true,
				status: 200,
				json: async () => ( { data: [ { id: MODEL } ] } ),
			};
		}

		const text = JSON.parse( init.body ).messages[ 1 ].content;

		if ( ! ( text in answers ) ) {
			return {
				ok: false,
				status: 400,
				text: async () => 'rejected',
				json: async () => ( {} ),
			};
		}

		return {
			ok: true,
			status: 200,
			json: async () => ( {
				choices: [ { message: { content: answers[ text ] } } ],
			} ),
		};
	};

	process.env.I18N_MT_PROVIDER = 'local';
	process.env.I18N_MT_DELAY_MS = '0';

	try {
		const result = await translatePoFile( file, {
			dryRun: false,
			limit: Infinity,
		} );

		assert.equal(
			result.truncated,
			false,
			'one rejected string stopped the locale'
		);
		assert.equal( result.updated, 2 );

		const written = fs.readFileSync( file, 'utf8' );

		assert.match( written, /msgstr "Änderungen speichern"/ );
		assert.match( written, /msgstr "Enddatum"/ );
		assert.match( written, /msgid "Changes requested"\nmsgstr ""/ );
	} finally {
		fs.rmSync( dir, { recursive: true, force: true } );
	}
} );

test( 'a model that is not answering says so, unmissably', () => {
	delete process.env.NO_COLOR;
	process.env.FORCE_COLOR = '1';

	const down = localProviderDownMessage(
		'cannot reach http://model.test:1234/v1 (fetch failed)'
	);

	assert.ok( down.startsWith( '\u001b[31m' ), 'the failure was not red' );
	assert.match( down, /TRANSLATIONS DID NOT RUN/ );
	assert.match( down, /cannot reach http:\/\/model\.test:1234\/v1/ );
	assert.match( down, /\.env\.local/, 'it must say where the address lives' );
	assert.match( down, /Nothing was written/ );

	// Stopping partway is a different state: there is work on disk to keep.
	const midway = localRunIncompleteMessage();

	assert.ok( midway.startsWith( '\u001b[31m' ) );
	assert.match( midway, /DID NOT FINISH/ );
	assert.match( midway, /What was translated is written/ );
} );

test( 'NO_COLOR is honoured, so a log file gets plain text', () => {
	process.env.NO_COLOR = '1';
	delete process.env.FORCE_COLOR;

	assert.doesNotMatch( localProviderDownMessage( 'down' ), /\u001b\[/ );
	assert.doesNotMatch( localRunIncompleteMessage(), /\u001b\[/ );
} );
