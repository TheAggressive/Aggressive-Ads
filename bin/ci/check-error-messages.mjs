#!/usr/bin/env node
/**
 * Error codes the portal can redirect with, and the sentences it has for them.
 *
 * A creative write that fails hands the browser a *code*, not a message: the
 * error crosses `admin-post.php` as a query string, so whatever sentence the
 * `WP_Error` was constructed with is gone by the time anything renders. Only
 * `Creative_Feedback::error_message()` can put one back, and a code with no arm
 * there falls to the default — "The creative could not be saved. Please try
 * again."
 *
 * That default is not a neutral fallback. It names the wrong object and invites
 * a retry that cannot work: it is what an advertiser saw when they set a share
 * on a creative that was not delivering yet, and when they asked for an ad
 * update that changed nothing. Thirteen codes were reaching readers that way,
 * each already carrying a perfectly good sentence at the point it was raised —
 * and the thirteenth is why this file exists rather than a one-off fix: a
 * careful hand-grep of the same four workflows found twelve.
 *
 * This lane reads both sides and fails when they disagree. It is the same shape
 * as `check-client-contract.mjs`, and for the same reason: a write half and a
 * read half that never meet in a test drift apart silently, and the symptom is
 * a user being told something untrue rather than a build going red.
 */

import { readFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const ROOT = path.resolve( import.meta.dirname, '../..' );

/*
 * Overridable only so this lane's own tests can point it at fixtures. A guard
 * nobody exercises rots into one that permits everything, silently.
 */
const SCAN_ROOT = process.env.AGGR_ERROR_MESSAGE_SCAN_DIR ?? ROOT;

const FEEDBACK = 'inc/Portal/class-creative-feedback.php';

/**
 * The workflows a creative handler calls. A code raised anywhere in one of
 * these can reach the creative screen through `Creative_Feedback::after()`.
 */
const WORKFLOWS = [
	'inc/Workflow/class-creative-manager.php',
	'inc/Workflow/class-creative-uploader.php',
	'inc/Workflow/class-creative-change-manager.php',
	'inc/Workflow/class-assignment-editor.php',
];

/**
 * File contents, or null when it is not there.
 *
 * @param {string} relative Repository-relative path.
 * @return {Promise<string|null>} Contents.
 */
async function read( relative ) {
	try {
		return await readFile( path.join( SCAN_ROOT, relative ), 'utf8' );
	} catch {
		return null;
	}
}

/**
 * Codes a workflow hands to `error()`.
 *
 * Matched on the call rather than on any `'aggr_…'` string in the file, because
 * a file also names codes in comments and in comparisons, and counting those
 * would make the lane demand messages for things that are never redirected.
 *
 * @param {string} php Workflow source.
 * @return {string[]} Error codes.
 */
function raised( php ) {
	return [ ...php.matchAll( /->error\(\s*'(aggr_[a-z0-9_]+)'/g ) ].map(
		( found ) => found[ 1 ]
	);
}

/**
 * Codes `error_message()` has an arm for.
 *
 * Read out of the method body rather than restated here, for the reason the
 * client-contract lane reads its payload out of the source: a list kept beside
 * the thing it describes is a second copy, and a second copy is how a guard
 * passes after only one side changed.
 *
 * @param {string} php Feedback source.
 * @return {string[]|null} Mapped codes, or null when the method cannot be found.
 */
function mapped( php ) {
	const start = php.indexOf( 'function error_message' );

	if ( start < 0 ) {
		return null;
	}

	const end = php.indexOf( '\n\t}', start );

	if ( end < 0 ) {
		return null;
	}

	return [
		...php
			.slice( start, end )
			.matchAll( /'(aggr_[a-z0-9_]+)'\s*(?:,|=>)/g ),
	].map( ( found ) => found[ 1 ] );
}

const feedback = await read( FEEDBACK );

if ( null === feedback ) {
	console.error(
		`check-error-messages: ${ FEEDBACK } is missing, so this lane is blind ` +
			'rather than passing.'
	);
	process.exit( 1 );
}

const known = mapped( feedback );

if ( null === known ) {
	console.error(
		'check-error-messages: could not read error_message() out of ' +
			`${ FEEDBACK }. This lane is protecting nothing until the parse ` +
			'is fixed — do not delete it to get green.'
	);
	process.exit( 1 );
}

const codes = new Set();
let scanned = 0;

for ( const relative of WORKFLOWS ) {
	const php = await read( relative );

	if ( null === php ) {
		continue;
	}

	scanned += 1;
	raised( php ).forEach( ( code ) => codes.add( code ) );
}

/*
 * Both halves have to be non-empty. A renamed workflow makes "every code has a
 * message" true of nothing, and an unparsed match arm makes it false of
 * everything — the first prints ok over a lane that reads no code at all.
 */
if ( 0 === scanned || 0 === codes.size ) {
	console.error(
		'check-error-messages: found no error codes to check, so this lane ' +
			'proves nothing. Have the workflow files moved?'
	);
	process.exit( 1 );
}

if ( 0 === known.length ) {
	console.error(
		'check-error-messages: error_message() parsed as having no arms, so ' +
			'every assertion below it is vacuous.'
	);
	process.exit( 1 );
}

const missing = [ ...codes ]
	.filter( ( code ) => ! known.includes( code ) )
	.sort();

if ( missing.length > 0 ) {
	console.error(
		'check-error-messages: these codes reach a reader as the generic ' +
			'"could not be saved" sentence, because error_message() has no arm ' +
			'for them:\n'
	);
	missing.forEach( ( code ) => console.error( `  ${ code }` ) );
	console.error(
		'\nCopy the wording from the raise site so the two read the same. The ' +
			'sentence a WP_Error carries does not survive the redirect.'
	);
	process.exit( 1 );
}

console.log(
	`check-error-messages: ok (${ codes.size } codes across ${ scanned } ` +
		`workflows, ${ known.length } messages)`
);
