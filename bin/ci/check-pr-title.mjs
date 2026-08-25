#!/usr/bin/env node
/**
 * Pull request titles must be Conventional Commits.
 *
 * Not a style rule. The repository squash-merges, `squash_merge_commit_title`
 * is `PR_TITLE`, and semantic-release reads the resulting subject to decide
 * whether anything ships and at what version. A title it cannot parse is a
 * release that silently does not happen — and nothing else in the pipeline
 * would say so, because every other lane is green.
 *
 * The rule lives in `pr-policy-rules.mjs` so the policy job and this check
 * cannot disagree about what a valid title is.
 */

import { KNOWN_TYPES, parseTitle } from './pr-policy-rules.mjs';

const title = process.env.PR_TITLE ?? '';
const { valid, problem } = parseTitle( title );

if ( ! valid ) {
	console.error( `Pull request title: ${ title }` );
	console.error( '' );
	console.error( `Not usable as a squash subject: ${ problem }` );
	console.error( '' );
	console.error( 'Use Conventional Commit form:' );
	console.error( '' );
	console.error( '  fix(cart): prevent duplicate updates' );
	console.error( '  feat: add campaign renewals' );
	console.error( '  feat!: drop PHP 8.3        (breaking, cuts a major)' );
	console.error( '' );
	console.error( `Types: ${ KNOWN_TYPES.join( ', ' ) }` );
	console.error( '' );
	console.error(
		'This is the subject semantic-release reads to decide what ships.'
	);

	process.exit( 1 );
}

console.log( `check-pr-title: ok (${ title })` );
