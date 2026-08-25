/**
 * Tests for the pull-request policy.
 *
 * This decides whether a pull request merges without a person looking at it,
 * so it is the highest-consequence logic in the repository after
 * `summary-rules.mjs`. A mistake blocks every pull request, or merges one that
 * needed a person.
 *
 * The rule the whole design rests on, and the one asserted from several
 * directions below: **`risk:high` always beats `automerge`**. A label is
 * something a person types. If typing one can override the classification of a
 * workflow or ruleset change, the classification is decoration.
 *
 * The scenario list is the one the policy was specified against, plus the
 * fail-closed cases that specification implies.
 */

import { strict as assert } from 'node:assert';
import test from 'node:test';

import {
	classify,
	decide,
	isDependabot,
	MANAGED_LABELS,
	parseTitle,
} from './pr-policy-rules.mjs';

const HUMAN = 'shawnmosher';
const TRUSTED = [ HUMAN ];

/** Classify with sensible defaults. */
function pr( title, files, author = HUMAN ) {
	return classify( { title, author, files } );
}

/** Decide with everything green unless overridden. */
function verdict( classification, overrides = {} ) {
	return decide( {
		classification,
		author: HUMAN,
		labels: [],
		mergeStateStatus: 'CLEAN',
		draft: false,
		checkConclusions: [ 'SUCCESS', 'SUCCESS' ],
		trustedAuthors: TRUSTED,
		...overrides,
	} );
}

/* -------------------------------------------------------------------------- */
/* Titles                                                                      */
/* -------------------------------------------------------------------------- */

test( 'conventional titles parse, with and without a scope', () => {
	assert.equal( parseTitle( 'feat: add renewals' ).type, 'feat' );

	const scoped = parseTitle( 'fix(cart): prevent duplicate updates' );
	assert.equal( scoped.valid, true );
	assert.equal( scoped.scope, 'cart' );
} );

test( "Dependabot's generated titles are valid without a special case", () => {
	// `commit-message.prefix: chore` + `include: scope` produces this shape.
	// If it ever stopped parsing, every dependency PR would stop auto-merging.
	assert.equal(
		parseTitle( 'chore(deps): bump the actions group with 3 updates' )
			.valid,
		true
	);
	assert.equal(
		parseTitle(
			'chore(deps-dev): bump the js-tooling group across 1 directory with 5 updates'
		).valid,
		true
	);
} );

test( 'a non-conventional title is refused, because it becomes the squash subject', () => {
	// Squash titles feed semantic-release, so this is not a style rule: an
	// unparsed title is a release that silently does not happen.
	const { valid, problem } = parseTitle( 'Update the thing' );

	assert.equal( valid, false );
	assert.match( problem, /Conventional Commit/ );
} );

test( 'an unknown type is refused and lists the ones that work', () => {
	const { valid, problem } = parseTitle( 'wip: still going' );

	assert.equal( valid, false );
	assert.match( problem, /"wip" is not one of/ );
	assert.match( problem, /feat/ );
} );

test( 'a trailing full stop is refused', () => {
	assert.equal( parseTitle( 'fix: stop the thing.' ).valid, false );
} );

test( 'an empty title is refused rather than treated as unclassified', () => {
	assert.equal( parseTitle( '' ).valid, false );
	assert.equal( parseTitle( undefined ).valid, false );
} );

test( 'a breaking-change marker is recognised', () => {
	assert.equal( parseTitle( 'feat!: drop PHP 8.3' ).breaking, true );
	assert.equal( parseTitle( 'feat(api)!: drop PHP 8.3' ).breaking, true );
} );

/* -------------------------------------------------------------------------- */
/* Classification — the specified scenarios                                    */
/* -------------------------------------------------------------------------- */

test( 'a normal feature PR is medium risk and labelled by area', () => {
	const c = pr( 'feat: add campaign renewals', [
		'inc/Workflow/class-campaign-editor.php',
		'src/interactivity/wizard.ts',
	] );

	assert.equal( c.risk, 'risk:medium' );
	assert.ok( c.labels.includes( 'type:feature' ) );
	assert.ok( c.labels.includes( 'area:php' ) );
	assert.ok( c.labels.includes( 'area:frontend' ) );
} );

test( 'a normal fix PR is medium risk', () => {
	const c = pr( 'fix: correct the rollup window', [
		'inc/Workflow/class-fill-service.php',
	] );

	assert.equal( c.risk, 'risk:medium' );
	assert.ok( c.labels.includes( 'type:fix' ) );
} );

test( 'a docs-only PR is low risk', () => {
	const c = pr( 'docs: explain the migration', [
		'docs/data-schema.md',
		'README.md',
	] );

	assert.equal( c.risk, 'risk:low' );
	assert.equal( c.prose, true );
	assert.ok( c.labels.includes( 'type:docs' ) );
} );

test( 'a translation-only PR is low risk and labelled i18n', () => {
	const c = pr( 'chore(i18n): refresh the German catalog', [
		'languages/aggressive-ads-de_DE.po',
	] );

	assert.equal( c.risk, 'risk:low' );
	assert.ok( c.labels.includes( 'area:i18n' ) );
} );

test( 'a tests-only PR is low risk', () => {
	const c = pr( 'test: cover the upgrade wiring', [
		'tests/php/Upgrade/LineItemUpgradeWiringTest.php',
	] );

	assert.equal( c.risk, 'risk:low' );
	assert.ok( c.labels.includes( 'area:tests' ) );
} );

test( 'a workflow change is high risk and says which file and why', () => {
	const c = pr( 'ci: add a lane', [ '.github/workflows/ci.yml' ] );

	assert.equal( c.risk, 'risk:high' );
	assert.ok( c.labels.includes( 'area:ci' ) );
	assert.match( c.riskReasons.join( '\n' ), /workflow definition/ );
} );

test( 'release machinery is high risk', () => {
	const c = pr( 'fix: correct the packaging allowlist', [
		'bin/release/package.sh',
	] );

	assert.equal( c.risk, 'risk:high' );
	assert.ok( c.labels.includes( 'area:release' ) );
} );

test( 'a CI enforcement script is high risk', () => {
	/*
	 * This repository has now found three guards silently reading nothing, so
	 * "a small edit to a guard" is precisely the change that must not merge
	 * itself. The guard cannot catch its own blinding.
	 */
	const c = pr( 'ci: relax a guard', [
		'bin/ci/check-permission-callbacks.php',
	] );

	assert.equal( c.risk, 'risk:high' );
	assert.match( c.riskReasons.join( '\n' ), /enforcement script/ );
} );

test( 'the ruleset, CODEOWNERS and dependabot config are all high risk', () => {
	for ( const file of [
		'.github/rulesets/release-branches.json',
		'.github/CODEOWNERS',
		'.github/dependabot.yml',
		'.releaserc.json',
	] ) {
		assert.equal( pr( 'chore: tweak', [ file ] ).risk, 'risk:high', file );
	}
} );

test( 'authorization, installer and storage code are high risk', () => {
	for ( const file of [
		'inc/Security/class-ownership.php',
		'inc/REST/class-line-items-controller.php',
		'inc/Install/class-uninstaller.php',
		'inc/Storage/class-creative-cipher.php',
		'uninstall.php',
	] ) {
		assert.equal( pr( 'fix: adjust', [ file ] ).risk, 'risk:high', file );
	}
} );

test( 'the plugin header is high risk, because it carries the runtime floor', () => {
	assert.equal(
		pr( 'chore: bump requires-php', [ 'aggressive-ads.php' ] ).risk,
		'risk:high'
	);
} );

test( 'a breaking change is high risk whatever it touches', () => {
	// semantic-release cuts a major from it, and that reaches every site.
	const c = pr( 'feat!: change the fill payload', [ 'inc/Workflow/x.php' ] );

	assert.equal( c.risk, 'risk:high' );
	assert.match( c.riskReasons.join( '\n' ), /breaking change/ );
} );

test( 'a PR whose files could not be read is high risk, not low', () => {
	/*
	 * Fail closed. An empty file list is not "changed nothing", it is "we do
	 * not know what changed" — and treating unknown as harmless is how
	 * automation merges the one thing it should not.
	 */
	const c = pr( 'fix: something', [] );

	assert.equal( c.risk, 'risk:high' );
	assert.match( c.riskReasons.join( '\n' ), /no changed files/ );
} );

/* -------------------------------------------------------------------------- */
/* Manifests: a version contract in a person's hands, routine for Dependabot   */
/* -------------------------------------------------------------------------- */

test( 'a person editing package.json is high risk', () => {
	assert.equal(
		pr( 'chore: bump a dep', [ 'package.json', 'pnpm-lock.yaml' ] ).risk,
		'risk:high'
	);
} );

test( 'Dependabot editing the same manifest is not', () => {
	/*
	 * Otherwise no dependency update ever auto-merges and the whole arrangement
	 * is pointless. Dependabot is constrained far more tightly than a person:
	 * it can only bump a version, majors are refused, and every gate still runs.
	 */
	const c = pr(
		'chore(deps): bump the js-tooling group with 3 updates',
		[ 'package.json', 'pnpm-lock.yaml' ],
		'dependabot[bot]'
	);

	assert.equal( c.risk, 'risk:medium' );
	assert.ok( c.labels.includes( 'dependencies' ) );
} );

test( 'Dependabot touching a workflow is still high risk', () => {
	// The github-actions ecosystem edits workflow files. A SHA bump is routine,
	// but this file cannot tell a SHA bump from a rewritten job, so it does not
	// guess.
	const c = pr(
		'chore(deps): bump actions/checkout',
		[ '.github/workflows/ci.yml' ],
		'dependabot[bot]'
	);

	assert.equal( c.risk, 'risk:high' );
} );

test( 'both Dependabot logins are recognised', () => {
	// The REST API says `app/dependabot`; event payloads say `dependabot[bot]`.
	assert.equal( isDependabot( 'app/dependabot' ), true );
	assert.equal( isDependabot( 'dependabot[bot]' ), true );
	assert.equal( isDependabot( 'dependabot' ), false );
	assert.equal( isDependabot( HUMAN ), false );
} );

/* -------------------------------------------------------------------------- */
/* The decision                                                                */
/* -------------------------------------------------------------------------- */

test( 'a high-risk PR with automerge is still refused', () => {
	/*
	 * The single most important assertion here. `automerge` is a person typing
	 * a word; the classification is derived from the diff. If the word wins,
	 * the classification is decoration and a workflow edit can merge itself.
	 */
	const c = pr( 'ci: add a lane', [ '.github/workflows/ci.yml' ] );

	const { action, reason } = verdict( c, { labels: [ 'automerge' ] } );

	assert.equal( action, 'skip' );
	assert.match( reason, /high-risk/ );
} );

test( 'a low-risk PR without the label waits for a person', () => {
	// Opt-in, not opt-out: not every PR should merge itself.
	const c = pr( 'docs: explain the migration', [ 'docs/x.md' ] );

	const { action, reason } = verdict( c );

	assert.equal( action, 'skip' );
	assert.match( reason, /no automerge label/ );
} );

test( 'a labelled low-risk PR from a trusted author merges', () => {
	const c = pr( 'docs: explain the migration', [ 'docs/x.md' ] );

	assert.equal( verdict( c, { labels: [ 'automerge' ] } ).action, 'merge' );
} );

test( 'the label alone is not enough — the author must be permitted', () => {
	/*
	 * Anyone who can label a pull request could otherwise merge one. The label
	 * says *what* to do; the author list says whether this account may ask.
	 */
	const c = pr( 'docs: explain the migration', [ 'docs/x.md' ] );

	const { action, reason } = verdict( c, {
		labels: [ 'automerge' ],
		author: 'drive-by-contributor',
	} );

	assert.equal( action, 'skip' );
	assert.match( reason, /not permitted/ );
} );

test( 'a Dependabot patch update merges with no label at all', () => {
	const c = pr(
		'chore(deps): bump adm-zip from 0.5.18 to 0.5.19',
		[ 'package.json', 'pnpm-lock.yaml' ],
		'dependabot[bot]'
	);

	assert.equal( verdict( c, { author: 'dependabot[bot]' } ).action, 'merge' );
} );

test( 'a Dependabot minor update merges', () => {
	const c = pr(
		'chore(deps): bump the wordpress group with 4 updates',
		[ 'package.json', 'pnpm-lock.yaml' ],
		'dependabot[bot]'
	);

	assert.equal( verdict( c, { author: 'dependabot[bot]' } ).action, 'merge' );
} );

test( 'a Dependabot major update is refused', () => {
	// The caller labels majors after reading the update metadata; this asserts
	// the decision honours it.
	const c = pr(
		'chore(deps): bump eslint from 8.0.0 to 9.0.0',
		[ 'package.json' ],
		'dependabot[bot]'
	);

	const { action, reason } = verdict( c, {
		author: 'dependabot[bot]',
		labels: [ 'dependency-major' ],
	} );

	assert.equal( action, 'skip' );
	assert.match( reason, /major version/ );
} );

test( 'a grouped Dependabot PR merges', () => {
	const c = pr(
		'chore(deps): bump the php-tooling group across 1 directory with 2 updates',
		[ 'composer.json', 'composer.lock' ],
		'app/dependabot'
	);

	assert.equal( verdict( c, { author: 'app/dependabot' } ).action, 'merge' );
} );

test( 'a stale branch is updated rather than merged', () => {
	// And updating is all it does: fresh checks against the new merge result
	// decide, which is what `strict_required_status_checks_policy` means.
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	const { action, reason } = verdict( c, {
		labels: [ 'automerge' ],
		mergeStateStatus: 'BEHIND',
	} );

	assert.equal( action, 'update-branch' );
	assert.match( reason, /fresh checks/ );
} );

test( 'a conflicting PR is left alone', () => {
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	const { action, reason } = verdict( c, {
		labels: [ 'automerge' ],
		mergeStateStatus: 'DIRTY',
	} );

	assert.equal( action, 'skip' );
	assert.match( reason, /conflicts/ );
} );

test( 'a blocked PR is left alone, because GitHub is the authority', () => {
	// BLOCKED covers an unresolved review thread, which this repository's
	// ruleset requires resolved. That is a person's business.
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	assert.equal(
		verdict( c, { labels: [ 'automerge' ], mergeStateStatus: 'BLOCKED' } )
			.action,
		'skip'
	);
} );

test( 'an unknown merge state waits rather than proceeding', () => {
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	assert.equal(
		verdict( c, { labels: [ 'automerge' ], mergeStateStatus: 'UNKNOWN' } )
			.action,
		'wait'
	);
} );

test( 'an unrecognised merge state is refused, not assumed benign', () => {
	// GitHub can add a state. A default of "proceed" would merge on a state
	// this code has never seen.
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	const { action, reason } = verdict( c, {
		labels: [ 'automerge' ],
		mergeStateStatus: 'SOMETHING_NEW',
	} );

	assert.equal( action, 'skip' );
	assert.match( reason, /unrecognised merge state/ );
} );

test( 'no reported checks is refused, not treated as nothing failing', () => {
	/*
	 * The distinction the Dependabot workflow already made and the one worth
	 * keeping: an empty check list means the workflows have not started, and
	 * "no check has failed" is true of that state.
	 */
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	const { action, reason } = verdict( c, {
		labels: [ 'automerge' ],
		checkConclusions: [],
	} );

	assert.equal( action, 'skip' );
	assert.match( reason, /no checks have reported/ );
} );

test( 'a pending or failing check waits and names the states', () => {
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	const pending = verdict( c, {
		labels: [ 'automerge' ],
		checkConclusions: [ 'SUCCESS', 'PENDING' ],
	} );

	assert.equal( pending.action, 'wait' );
	assert.match( pending.reason, /PENDING/ );

	const failed = verdict( c, {
		labels: [ 'automerge' ],
		checkConclusions: [ 'SUCCESS', 'FAILURE' ],
	} );

	assert.equal( failed.action, 'wait' );
	assert.match( failed.reason, /FAILURE/ );
} );

test( 'skipped and neutral checks do not block a merge', () => {
	// The pipeline deliberately skips lanes a prose change cannot affect.
	// Treating SKIPPED as unfinished would block every docs PR forever.
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	assert.equal(
		verdict( c, {
			labels: [ 'automerge' ],
			checkConclusions: [ 'SUCCESS', 'SKIPPED', 'NEUTRAL' ],
		} ).action,
		'merge'
	);
} );

test( 'a draft is never merged', () => {
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	assert.equal(
		verdict( c, { labels: [ 'automerge' ], draft: true } ).action,
		'skip'
	);
} );

test( 'requested changes stop a merge even with the label', () => {
	const c = pr( 'docs: x', [ 'docs/x.md' ] );

	assert.equal(
		verdict( c, {
			labels: [ 'automerge' ],
			reviewDecision: 'CHANGES_REQUESTED',
		} ).action,
		'skip'
	);
} );

test( 'an invalid title stops a merge, because it becomes the squash subject', () => {
	const c = pr( 'just fixing stuff', [ 'docs/x.md' ] );

	const { action, reason } = verdict( c, { labels: [ 'automerge' ] } );

	assert.equal( action, 'skip' );
	assert.match( reason, /Conventional Commit/ );
} );

test( 'an unclassifiable bot PR is refused', () => {
	/*
	 * An unknown bot with an unparseable title and no label: three separate
	 * reasons to stop, and the test asserts it stops rather than which reason
	 * won. Fail closed means the answer does not depend on ordering.
	 */
	const c = pr( 'Automated update', [ 'some/file.txt' ], 'random-bot[bot]' );

	assert.equal( verdict( c, { author: 'random-bot[bot]' } ).action, 'skip' );
} );

/* -------------------------------------------------------------------------- */
/* The managed label set                                                       */
/* -------------------------------------------------------------------------- */

test( 'the managed label set is small, and every label it can apply is in it', () => {
	/*
	 * The workflow removes managed labels that no longer apply, so a label the
	 * classifier can apply but the set does not name would be applied once and
	 * never removed — a PR stuck at `risk:high` after the risky file is gone.
	 */
	const everything = pr( 'feat: x', [
		'inc/a.php',
		'src/b.ts',
		'src/blocks/c/index.ts',
		'tests/d.php',
		'languages/e.po',
		'.github/workflows/f.yml',
		'bin/release/g.sh',
	] );

	for ( const label of everything.labels ) {
		if ( 'dependencies' === label ) {
			continue; // Owned by Dependabot, not by this policy.
		}

		assert.ok(
			MANAGED_LABELS.includes( label ),
			`${ label } is applied but not managed, so it could never be removed`
		);
	}

	// Small enough to understand at a glance, which was the stated requirement.
	assert.ok(
		MANAGED_LABELS.length <= 16,
		`${ MANAGED_LABELS.length } managed labels is too many to read at a glance`
	);
} );
