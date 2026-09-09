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

test( 'a translation may not invent a placeholder the source never had', () => {
	// The exact string, and the exact borrowed token, that failed three runs
	// on master: a translation memory answered "All campaigns" out of a
	// neighbouring "%d campaigns".
	assert.equal(
		placeholdersIntact( 'All campaigns', 'Alle %d Kampagnen' ),
		false
	);

	assert.equal(
		findPlaceholderMismatches(
			entry( 'All campaigns', 'Alle %d Kampagnen' )
		).length,
		1
	);

	// And the other half of the same incident, which was already caught.
	assert.equal(
		placeholdersIntact(
			'That delivery policy cannot be used: %s',
			'Diese Auslieferungsrichtlinie kann nicht verwendet werden'
		),
		false
	);
} );

test( 'the lint and the translator cannot disagree about a mismatch', () => {
	/*
	 * They did disagree, and that is the whole defect: placeholdersIntact()
	 * short-circuited to true whenever the source carried no placeholders, so
	 * the translator wrote a string this lint then refused, on every run,
	 * forever. Asserting the two verdicts agree over a table is what stops one
	 * of them being relaxed on its own — a count is asserted too, so a table
	 * that stops reaching the mismatching cases fails instead of passing
	 * quietly.
	 */
	const cases = [
		[ 'All campaigns', 'Alle Kampagnen' ],
		[ 'All campaigns', 'Alle %d Kampagnen' ],
		[ 'Show all campaigns', 'Alle %d Kampagnen anzeigen' ],
		[ '%d campaigns', '%d Kampagnen' ],
		[ '%d campaigns', 'Kampagnen' ],
		[ 'Used: %s', 'Verwendet: %s' ],
		[ 'Used: %s', 'Verwendet' ],
		[ 'Used: %s', 'Verwendet: %s %s' ],
		[ '%1$s of %2$s', '%2$s von %1$s' ],
		[ 'Save {pct}%', 'Spara {pct} %' ],
		[ 'Save {pct}%', 'Spara %' ],
	];

	let disagreements = 0;
	let mismatches = 0;

	for ( const [ source, translated ] of cases ) {
		const lintSaysBad =
			findPlaceholderMismatches( entry( source, translated ) ).length > 0;
		const ruleSaysBad = ! placeholdersIntact( source, translated );

		if ( lintSaysBad !== ruleSaysBad ) {
			disagreements += 1;
		}

		if ( lintSaysBad ) {
			mismatches += 1;
		}
	}

	assert.equal( disagreements, 0, 'lint and rule disagreed on some case' );
	assert.equal( mismatches, 6, 'the table stopped covering the bad cases' );
} );
