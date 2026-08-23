import assert from 'node:assert/strict';
import test from 'node:test';

import { findPlaceholderMismatches } from './lint-placeholders.mjs';
import { extractPlaceholders, placeholdersIntact } from './po.mjs';

const HEADER = `msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
`;

const entry = ( msgid, msgstr, extra = '' ) =>
	`${ HEADER }\n${ extra }msgid "${ msgid }"\nmsgstr "${ msgstr }"\n`;

test( 'brace tokens must survive translation', () => {
	assert.deepEqual(
		findPlaceholderMismatches(
			entry( 'Save {percent}%', 'Économisez {pourcentage} %' )
		).length,
		1
	);

	assert.deepEqual(
		findPlaceholderMismatches(
			entry( 'Save {percent}%', 'Spara {percent} %' )
		),
		[]
	);
} );

test( 'printf placeholders must survive translation', () => {
	assert.equal(
		findPlaceholderMismatches(
			entry( 'Save %d%%', 'Économisez pour cent' )
		).length,
		1
	);

	assert.deepEqual(
		findPlaceholderMismatches( entry( 'Save %d%%', 'Spara %d%%' ) ),
		[]
	);
} );

test( 'reordered placeholders are allowed', () => {
	assert.deepEqual(
		findPlaceholderMismatches( entry( '%1$s of %2$s', '%2$s / %1$s' ) ),
		[]
	);
} );

test( 'untranslated and fuzzy entries are drafts, not failures', () => {
	assert.deepEqual(
		findPlaceholderMismatches( entry( 'Save {percent}%', '' ) ),
		[]
	);
	assert.deepEqual(
		findPlaceholderMismatches(
			entry( 'Save {percent}%', 'Sconto', '#, fuzzy\n' )
		),
		[]
	);
} );

test( 'plural forms compare against the plural source', () => {
	const po = `${ HEADER }
msgid "{count} item"
msgid_plural "{count} items"
msgstr[0] "{count} artikel"
msgstr[1] "{count} artiklar"
`;

	assert.deepEqual( findPlaceholderMismatches( po ), [] );
} );

test( 'placeholder extraction covers both families', () => {
	assert.deepEqual( extractPlaceholders( '%1$s saved {percent}% on %d' ), [
		'%1$s',
		'%d',
		'{percent}',
	] );

	assert.equal( placeholdersIntact( 'plain text', 'texte simple' ), true );
	assert.equal( placeholdersIntact( '{pct}% done', 'terminé' ), false );
} );
