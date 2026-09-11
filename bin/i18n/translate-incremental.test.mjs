/**
 * A run must be incremental: only what is new, changed, empty or uncertain.
 *
 * The cost of getting this wrong is not correctness but hours — a local model
 * re-translating 1,386 strings on every run — and, worse, silently replacing
 * reviewed German with a fresh guess. So the stub here records every string the
 * model is asked for, and the test asserts on that list rather than on counts
 * alone.
 */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import assert from 'node:assert/strict';
import test, { afterEach, beforeEach } from 'node:test';

import { translatePoFile } from './translate.mjs';

const MODEL = 'test-model';
const realFetch = globalThis.fetch;
const saved = {};

beforeEach( () => {
	for ( const name of [
		'I18N_LOCAL_URL',
		'I18N_LOCAL_MODEL',
		'I18N_MT_PROVIDER',
		'I18N_MT_DELAY_MS',
	] ) {
		saved[ name ] = process.env[ name ];
	}

	process.env.I18N_LOCAL_URL = 'http://model.test:1234/v1';
	process.env.I18N_LOCAL_MODEL = MODEL;
	process.env.I18N_MT_PROVIDER = 'local';
	process.env.I18N_MT_DELAY_MS = '0';
} );

afterEach( () => {
	globalThis.fetch = realFetch;

	for ( const [ name, value ] of Object.entries( saved ) ) {
		if ( undefined === value ) {
			delete process.env[ name ];
		} else {
			process.env[ name ] = value;
		}
	}
} );

/**
 * A catalog holding one of each state a run has to tell apart.
 *
 * @param {string} dir Temporary directory.
 * @return {string} Catalog path.
 */
function catalog( dir ) {
	const file = path.join( dir, 'aggressive-ads-de_DE.po' );

	fs.writeFileSync(
		file,
		`msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"

#. translators: a reviewed string nothing should touch.
#: inc/Example.php:1
msgid "Already done"
msgstr "Bereits erledigt"

#: inc/Example.php:2
#, fuzzy
msgid "Needs work"
msgstr "Alter Text"

#: inc/Example.php:3
msgid "Not yet"
msgstr ""

#~ msgid "Removed from the source"
#~ msgstr "Aus der Quelle entfernt"
`,
		'utf8'
	);

	return file;
}

test( 'only new, changed, empty or uncertain strings reach the model', async () => {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'aggr-incr-' ) );
	const file = catalog( dir );
	const before = fs.readFileSync( file, 'utf8' );
	const asked = [];

	globalThis.fetch = async ( url, init ) => {
		if ( String( url ).endsWith( '/models' ) ) {
			return {
				ok: true,
				status: 200,
				json: async () => ( { data: [ { id: MODEL } ] } ),
			};
		}

		const text = JSON.parse( init.body ).messages[ 1 ].content;

		asked.push( text );

		return {
			ok: true,
			status: 200,
			json: async () => ( {
				choices: [ { message: { content: `DE:${ text }` } } ],
			} ),
		};
	};

	try {
		const result = await translatePoFile( file, {
			dryRun: false,
			limit: Infinity,
		} );

		// The list, not just the count: a count cannot say *which* strings went.
		assert.deepEqual(
			asked.sort(),
			[ 'Needs work', 'Not yet' ],
			'a reviewed translation was sent back to the model'
		);
		assert.equal( result.updated, 2 );
		assert.equal(
			result.skipped,
			1,
			'the reviewed entry must be skipped, not refused'
		);

		const after = fs.readFileSync( file, 'utf8' );

		// Untouched means untouched: same text, same flags, same comments.
		assert.match(
			after,
			/#\. translators: a reviewed string nothing should touch\.\n#: inc\.Example\.php:1|#\. translators: a reviewed string nothing should touch\./
		);
		assert.match(
			after,
			/msgid "Already done"\nmsgstr "Bereits erledigt"/
		);
		assert.doesNotMatch( after, /msgstr "DE:Already done"/ );

		// The fuzzy one was retranslated and is no longer a draft.
		assert.match( after, /msgstr "DE:Needs work"/ );
		assert.doesNotMatch( after, /#, fuzzy\nmsgid "Needs work"/ );

		// A string removed from the source stays commented out, not resurrected
		// and not silently dropped.
		assert.match( after, /#~ msgid "Removed from the source"/ );
		assert.match( after, /#~ msgstr "Aus der Quelle entfernt"/ );
		assert.ok( ! asked.includes( 'Removed from the source' ) );

		// And a second run has nothing left to do.
		asked.length = 0;
		const second = await translatePoFile( file, {
			dryRun: false,
			limit: Infinity,
		} );

		assert.deepEqual(
			asked,
			[],
			'a second run re-translated strings it had already done'
		);
		assert.equal( second.updated, 0 );
		assert.equal( second.remaining, 0 );
		assert.equal(
			fs.readFileSync( file, 'utf8' ),
			after,
			'a run with nothing to do rewrote the catalog'
		);

		assert.notEqual( before, after );
	} finally {
		fs.rmSync( dir, { recursive: true, force: true } );
	}
} );
