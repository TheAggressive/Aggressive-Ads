/**
 * Tests for the CI parity gate.
 *
 * The claim it enforces — "every `ci:*` lane maps 1:1 onto a GitHub Actions
 * job" — was written in the documentation before any workflow existed, and was
 * unchecked for a long time. This guard exists because a claim about process
 * that nothing verifies is a claim that quietly stops being true.
 *
 * The guard itself was then unchecked for the same reason, which is the joke
 * this file closes. Its failure mode is the expensive kind: a lane that stops
 * running in CI leaves every other gate green, and the missing coverage is
 * invisible precisely because nothing reports on work nobody does.
 *
 * `lanes.mjs` is the real parser here, pointed at the fixture through
 * `AGGR_LANES_ROOT`. Stubbing it would test a copy of the derivation rather
 * than the derivation, and "does the derivation actually reach it" is the whole
 * question.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-ci-parity.sh' );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/** A workflow job that runs the given lanes as `pnpm <lane>` steps. */
function job( name, lanes ) {
	return `  ${ name }:
    name: ${ name }
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@0000000000000000000000000000000000000000 # v1
${ lanes.map( ( lane ) => `      - run: pnpm ${ lane }` ).join( '\n' ) }
`;
}

/** A complete ci.yml running the given lanes in one job. */
function workflow( lanes ) {
	return `name: CI/CD Pipeline
on:
  push:
    branches: [master]
jobs:
${ job( 'quality', lanes ) }`;
}

/** The forward-compatibility workflow, in its correct form. */
const FORWARD = `name: PHP Forward Compatibility
on:
  schedule:
    - cron: '0 6 * * 1'
jobs:
  forward:
    runs-on: ubuntu-24.04
    steps:
      - run: pnpm ci:php:forward
`;

/**
 * A repository-shaped fixture.
 *
 * @param {object}   options            Fixture shape.
 * @param {string[]} options.scripts    `ci:*` script names for package.json.
 * @param {string[]} [options.inWorkflow] Lanes the workflow actually runs.
 * @param {string}   [options.forward]  php-forward-compatibility.yml contents.
 * @param {string}   [options.verify]   verify.sh contents.
 * @return {Promise<string>} Absolute root.
 */
async function fixture( {
	scripts,
	inWorkflow = scripts,
	forward = FORWARD,
	verify = 'node bin/ci/lanes.mjs\n',
} ) {
	const root = await mkdtemp( path.join( tmpdir(), 'aggr-parity-' ) );
	roots.push( root );

	await mkdir( path.join( root, '.github/workflows' ), { recursive: true } );
	await mkdir( path.join( root, 'bin/ci' ), { recursive: true } );

	const scriptMap = Object.fromEntries(
		[ ...scripts, 'ci:verify', 'ci:php:forward' ].map( ( name ) => [
			name,
			'echo x',
		] )
	);

	await writeFile(
		path.join( root, 'package.json' ),
		JSON.stringify( { name: 'fixture', scripts: scriptMap }, null, '\t' ),
		'utf8'
	);
	await writeFile(
		path.join( root, '.github/workflows/ci.yml' ),
		workflow( inWorkflow ),
		'utf8'
	);

	if ( null !== forward ) {
		await writeFile(
			path.join(
				root,
				'.github/workflows/php-forward-compatibility.yml'
			),
			forward,
			'utf8'
		);
	}

	await writeFile( path.join( root, 'bin/ci/verify.sh' ), verify, 'utf8' );

	return root;
}

/**
 * Runs the guard against a fixture root.
 *
 * @param {string} root Fixture root.
 * @return {{status: number, stdout: string, stderr: string}}
 */
function run( root ) {
	const result = spawnSync( 'bash', [ CHECKER ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_CI_PARITY_ROOT: root },
	} );

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

test( 'a lane run by the workflow and reached by the parser passes', async () => {
	const root = await fixture( { scripts: [ 'ci:lint' ] } );

	const { status, stdout } = run( root );

	assert.equal( status, 0 );
	assert.match( stdout, /ok/ );
} );

test( 'a lane no job runs fails, and names it', async () => {
	/*
	 * The drift this exists for: a `ci:*` script exists, the documentation says
	 * every one maps to a job, and no job runs it. Nothing else in the pipeline
	 * can notice, because the symptom is work nobody does.
	 */
	const root = await fixture( {
		scripts: [ 'ci:lint', 'ci:orphaned' ],
		inWorkflow: [ 'ci:lint' ],
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /ci:orphaned: no job runs it/ );
} );

test( 'a lane is not satisfied by a longer lane that shares its prefix', async () => {
	/*
	 * `pnpm ci:php` must not count as covered by a workflow that only runs
	 * `pnpm ci:php:wp`.
	 *
	 * Worth being precise about what this proves, because sabotage showed it is
	 * less than it looks: removing the `( |$)` anchor from the workflow grep
	 * does *not* fail this test. The second assertion catches it anyway —
	 * `lanes.mjs` reads the same workflow and reports `ci:php:wp`, so `ci:php`
	 * is absent from the derived list however the grep is written.
	 *
	 * The two checks therefore overlap on this input, and no fixture can
	 * isolate the anchor: any workflow that would fool the grep also fails the
	 * derivation. The anchor stays as belt-and-braces, and this test asserts the
	 * behaviour rather than the mechanism.
	 */
	const root = await fixture( {
		scripts: [ 'ci:php' ],
		inWorkflow: [ 'ci:php:wp' ],
	} );

	assert.equal( run( root ).status, 1 );
} );

test( 'ci:verify is exempt, because it is the aggregate of the others', async () => {
	// A job running it would run every other job again inside one.
	const root = await fixture( { scripts: [ 'ci:lint' ] } );

	assert.equal( run( root ).status, 0 );
} );

test( 'verify.sh must still derive its lanes from the workflow', async () => {
	/*
	 * The second half of parity. verify.sh used to keep its own copy of the
	 * list, so the question was "did somebody remember to add it there too".
	 * It now reads lanes.mjs, and if it stops doing that the local rehearsal
	 * and CI can diverge again with nothing saying so.
	 */
	const root = await fixture( {
		scripts: [ 'ci:lint' ],
		verify: 'pnpm ci:lint\npnpm ci:security\n',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no longer derives its lanes from the workflow/ );
} );

test( 'a missing workflow fails rather than passing over nothing', async () => {
	const root = await fixture( { scripts: [ 'ci:lint' ] } );
	await rm( path.join( root, '.github/workflows/ci.yml' ) );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no workflow at/ );
} );

test( 'the forward-compatibility exemption is a contract, not a hole', async () => {
	/*
	 * `ci:php:forward` is exempt from the 1:1 rule because it lives on a
	 * schedule in its own workflow. Three things keep that an exception rather
	 * than an escape: the workflow must exist, must still be scheduled, and
	 * must still call the canonical lane rather than inlining shell nobody can
	 * run locally.
	 */
	const missing = await fixture( { scripts: [ 'ci:lint' ], forward: null } );
	assert.match( run( missing ).stderr, /exempt only while/ );

	const onDemand = await fixture( {
		scripts: [ 'ci:lint' ],
		forward: FORWARD.replace(
			"  schedule:\n    - cron: '0 6 * * 1'",
			'  workflow_dispatch:'
		),
	} );
	assert.match( run( onDemand ).stderr, /must stay on a schedule/ );

	const inlined = await fixture( {
		scripts: [ 'ci:lint' ],
		forward: FORWARD.replace(
			'      - run: pnpm ci:php:forward',
			'      - run: php -v && vendor/bin/phpstan analyse'
		),
	} );
	assert.match( run( inlined ).stderr, /must call the canonical/ );
} );

test( 'every drifted lane is reported, not just the first', async () => {
	const root = await fixture( {
		scripts: [ 'ci:lint', 'ci:one', 'ci:two' ],
		inWorkflow: [ 'ci:lint' ],
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /ci:one/ );
	assert.match( stderr, /ci:two/ );
} );
