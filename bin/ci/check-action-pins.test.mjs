/**
 * Tests for the GitHub Actions supply-chain gate.
 *
 * `uses: foo/bar@v4` hands the repository token to whatever the action's owner
 * decides `v4` means, on every run, with nobody re-approving it. Several real
 * supply-chain incidents worked exactly that way, and this guard is the only
 * thing standing between the repository and one of them.
 *
 * It had no test. Writing one found the same failure the other two guards had:
 * a missing `.github/workflows` printed "ok (no workflows)" and exited 0, so a
 * moved or renamed directory turns the gate off silently. It also read only
 * `.github/workflows`, while a composite action under `.github/actions/` runs
 * with the same token and can call anything it likes.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-action-pins.sh' );

/** A real 40-character commit SHA shape. */
const SHA = 'a'.repeat( 40 );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A `.github`-shaped scan root containing the given files.
 *
 * @param {Record<string, string>} files Relative path to contents.
 * @return {Promise<string>} Absolute scan root.
 */
async function fixture( files ) {
	const root = await mkdtemp( path.join( tmpdir(), 'aggr-pins-' ) );
	roots.push( root );

	for ( const [ relative, contents ] of Object.entries( files ) ) {
		const full = path.join( root, relative );
		await mkdir( path.dirname( full ), { recursive: true } );
		await writeFile( full, contents, 'utf8' );
	}

	return root;
}

/**
 * Runs the guard against a scan root.
 *
 * @param {string} root Scan root.
 * @return {{status: number, stdout: string, stderr: string}}
 */
function run( root ) {
	const result = spawnSync( 'bash', [ CHECKER ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_ACTION_PINS_SCAN_DIR: root },
	} );

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

/** A workflow using the given `uses:` lines. */
function workflow( ...uses ) {
	return `name: Example
on: [push]
jobs:
  build:
    runs-on: ubuntu-24.04
    steps:
${ uses.map( ( line ) => `      - uses: ${ line }` ).join( '\n' ) }
`;
}

test( 'a SHA-pinned action with a version comment passes', async () => {
	const root = await fixture( {
		'workflows/ci.yml': workflow( `actions/checkout@${ SHA } # v7.0.1` ),
	} );

	const { status, stdout } = run( root );

	assert.equal( status, 0 );
	// The count is what makes a vacuous run visible.
	assert.match( stdout, /ok \(1 files?\)/ );
} );

test( 'a tag-pinned action is refused', async () => {
	const root = await fixture( {
		'workflows/ci.yml': workflow( 'actions/checkout@v7' ),
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /full commit SHA/ );
} );

test( 'a branch-pinned action is refused', async () => {
	// The worst form: `@main` is repointed by every push to the action's repo.
	const root = await fixture( {
		'workflows/ci.yml': workflow( 'some/action@main' ),
	} );

	assert.equal( run( root ).status, 1 );
} );

test( "GitHub's own actions are held to the same rule", async () => {
	// The argument is about mutability, not about trusting the author. A gate
	// that exempted actions/* would exempt most of the surface.
	const root = await fixture( {
		'workflows/ci.yml': workflow( 'actions/checkout@v7.0.1' ),
	} );

	assert.equal( run( root ).status, 1 );
} );

test( 'a short SHA is refused', async () => {
	// Seven characters is a prefix, and a prefix can become ambiguous.
	const root = await fixture( {
		'workflows/ci.yml': workflow( 'actions/checkout@3d3c42e' ),
	} );

	assert.equal( run( root ).status, 1 );
} );

test( 'a pinned action with no version comment is refused', async () => {
	// Pinned but unreadable: nobody can tell what they are updating from, so
	// nobody updates it, and the pin silently becomes abandonware.
	const root = await fixture( {
		'workflows/ci.yml': workflow( `actions/checkout@${ SHA }` ),
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /comment naming the exact tag/ );
} );

test( 'a local action reference is allowed', async () => {
	// `./.github/actions/x` is this repository's own code, already covered by
	// every other gate. Requiring a SHA for it would be meaningless.
	const root = await fixture( {
		'workflows/ci.yml': workflow( './.github/actions/setup' ),
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'composite actions are scanned too', async () => {
	/*
	 * The second hole. A composite action runs with the same repository token
	 * as any workflow step and can call anything it likes, so an unpinned
	 * `uses:` inside one is the same grant — and only `.github/workflows` was
	 * being read.
	 */
	const root = await fixture( {
		'workflows/ci.yml': workflow( `actions/checkout@${ SHA } # v7.0.1` ),
		'actions/setup/action.yml': `name: Setup
runs:
  using: composite
  steps:
    - uses: evil/action@v1
`,
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /evil\/action@v1/ );
} );

test( 'a commented-out example does not fail the build', async () => {
	// The negative half. A gate that fires on prose about the mistake teaches
	// people to work around the gate — the lesson check-boundaries.php records.
	const root = await fixture( {
		'workflows/ci.yml': `name: Example
on: [push]
jobs:
  build:
    runs-on: ubuntu-24.04
    steps:
      # Never write: uses: actions/checkout@v7
      - uses: actions/checkout@${ SHA } # v7.0.1
`,
	} );

	assert.equal(
		run( root ).status,
		0,
		'A commented-out example is not an unpinned action.'
	);
} );

test( 'a missing scan directory fails instead of reporting ok', async () => {
	/*
	 * The hole this guard shared with the other two. It printed
	 * "ok (no workflows)" and exited 0, so a renamed or moved directory turns
	 * the supply-chain gate off with nothing saying so.
	 */
	const { status, stderr } = run(
		path.join( tmpdir(), 'aggr-pins-missing' )
	);

	assert.equal( status, 1 );
	assert.match( stderr, /does not exist/ );
} );

test( 'a scan directory with no workflows fails instead of reporting ok', async () => {
	const root = await fixture( { 'README.md': 'no workflows here' } );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no workflows or actions directory/ );
} );

test( 'an empty workflows directory fails instead of reporting ok', async () => {
	// Directory present, nothing in it. "No violations" over nothing read.
	const root = await fixture( { 'workflows/.gitkeep': '' } );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no workflow or action files/ );
} );

test( 'every unpinned action is reported, not just the first', async () => {
	const root = await fixture( {
		'workflows/a.yml': workflow( 'one/action@v1' ),
		'workflows/b.yml': workflow( 'two/action@main' ),
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /one\/action@v1/ );
	assert.match( stderr, /two\/action@main/ );
} );
