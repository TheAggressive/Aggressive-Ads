#!/usr/bin/env node
/**
 * Applies the pull-request policy.
 *
 * The decisions live in `pr-policy-rules.mjs`, which is pure and tested. This
 * file does only what a test cannot: read the pull request from the API, apply
 * labels, and ask GitHub to update or auto-merge.
 *
 * **It never merges anything itself.** `gh pr merge --auto` registers GitHub's
 * native auto-merge, so the branch ruleset stays the thing that decides — every
 * required check, the strict up-to-date policy, signed commits and resolved
 * review threads all still apply. Custom code that called the merge API
 * directly would be duplicating GitHub's merge engine and would be the obvious
 * place for a protection to get quietly skipped.
 *
 * Every fact it judges is read from the API, never from the event payload. A
 * label, a branch name and a title are all things a person can set; the payload
 * for a `pull_request` event is attacker-influenced on a fork. So the workflow
 * passes in a number and this asks GitHub who the author is.
 *
 * Usage: pr-policy.mjs <pr-number>
 */

import { execFileSync } from 'node:child_process';

import {
	classify,
	decide,
	isDependabot,
	MANAGED_LABELS,
} from './pr-policy-rules.mjs';

const number = process.argv[ 2 ];

if ( ! /^[0-9]+$/.test( number ?? '' ) ) {
	console.error( 'pr-policy: expected a pull request number' );
	process.exit( 1 );
}

/** Accounts permitted to use the `automerge` label, from the workflow. */
const trustedAuthors = ( process.env.AGGR_AUTOMERGE_ACTORS ?? '' )
	.split( ',' )
	.map( ( login ) => login.trim() )
	.filter( Boolean );

const dryRun = '1' === process.env.AGGR_PR_POLICY_DRY_RUN;

/**
 * Runs `gh` and returns stdout.
 *
 * @param {string[]} args Arguments.
 * @return {string}
 */
function gh( args ) {
	return execFileSync( 'gh', args, {
		encoding: 'utf8',
		maxBuffer: 16 * 1024 * 1024,
	} );
}

const pr = JSON.parse(
	gh( [
		'pr',
		'view',
		number,
		'--json',
		[
			'author',
			'title',
			'labels',
			'files',
			'isDraft',
			'mergeStateStatus',
			'reviewDecision',
			'statusCheckRollup',
			'headRefOid',
			'headRefName',
		].join( ',' ),
	] )
);

const author = pr.author?.login ?? '';
const files = ( pr.files ?? [] ).map( ( file ) => file.path );
const labels = ( pr.labels ?? [] ).map( ( label ) => label.name );

const classification = classify( { title: pr.title, author, files } );

/*
 * Dependabot majors.
 *
 * The dependabot.yml `ignore` rules already stop routine majors being opened,
 * but a *security* update may legitimately cross one — which is the behaviour
 * that configuration is deliberately written to preserve. Such a pull request
 * must arrive and must not merge itself, so the major is detected here from the
 * title Dependabot generates and recorded as a label the decision reads.
 */
const major = ( () => {
	const bump = /from\s+(\d+)\.\d+\.\d+\S*\s+to\s+(\d+)\.\d+\.\d+/.exec(
		pr.title ?? ''
	);

	return null !== bump && bump[ 1 ] !== bump[ 2 ];
} )();

const desired = new Set( classification.labels );

if ( major && isDependabot( author ) ) {
	desired.add( 'dependency-major' );
}

/*
 * `needs-attention` is the one label a person is meant to scan for. It marks a
 * pull request automation has looked at and deliberately declined, so an
 * unlabelled pull request means "still working" rather than "nobody checked".
 */
const verdict = decide( {
	classification,
	author,
	labels: [ ...new Set( [ ...labels, ...desired ] ) ],
	mergeStateStatus: pr.mergeStateStatus,
	draft: Boolean( pr.isDraft ),
	/*
	 * `statusCheckRollup` mixes two shapes. A CheckRun carries `conclusion`
	 * (and `status` while running); a commit StatusContext carries `state` and
	 * neither of the others. Reading only conclusion/status maps every commit
	 * status to PENDING, which would hang any pull request that has one — the
	 * previous workflow had this gap and got away with it only because nothing
	 * here posts commit statuses today.
	 */
	checkConclusions: ( pr.statusCheckRollup ?? [] ).map(
		( check ) =>
			check.conclusion || check.state || check.status || 'PENDING'
	),
	reviewDecision: pr.reviewDecision ?? '',
	trustedAuthors,
} );

if ( 'risk:high' === classification.risk ) {
	desired.add( 'needs-attention' );
}

const managed = new Set( [ ...MANAGED_LABELS, 'dependency-major' ] );
const toAdd = [ ...desired ].filter( ( label ) => ! labels.includes( label ) );
const toRemove = labels.filter(
	( label ) => managed.has( label ) && ! desired.has( label )
);

console.log( `#${ number } by ${ author }` );
console.log( `  risk:   ${ classification.risk }` );

for ( const reason of classification.riskReasons ) {
	console.log( `          ${ reason }` );
}

console.log( `  labels: ${ [ ...desired ].sort().join( ', ' ) || '(none)' }` );
console.log( `  action: ${ verdict.action } — ${ verdict.reason }` );

if ( dryRun ) {
	process.exit( 0 );
}

if ( toAdd.length > 0 ) {
	gh( [
		'pr',
		'edit',
		number,
		...toAdd.flatMap( ( l ) => [ '--add-label', l ] ),
	] );
}

if ( toRemove.length > 0 ) {
	gh( [
		'pr',
		'edit',
		number,
		...toRemove.flatMap( ( l ) => [ '--remove-label', l ] ),
	] );
}

switch ( verdict.action ) {
	case 'update-branch':
		/*
		 * `expected_head_sha` makes this a compare-and-swap. Without it a push
		 * landing between the read and the update would be silently rebased by
		 * automation onto a base nobody checked against.
		 */
		gh( [
			'api',
			'--method',
			'PUT',
			`repos/${ process.env.GH_REPO }/pulls/${ number }/update-branch`,
			'-f',
			`expected_head_sha=${ pr.headRefOid }`,
		] );
		console.log( '  updated the branch; fresh checks will decide.' );
		break;

	case 'merge':
		gh( [
			'pr',
			'merge',
			number,
			'--auto',
			'--squash',
			'--delete-branch',
		] );
		console.log( '  native squash auto-merge registered.' );
		break;

	default:
		// `wait` and `skip` both do nothing. The difference is only whether a
		// later event is expected to change the answer.
		break;
}
