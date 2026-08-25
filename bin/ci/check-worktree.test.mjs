/**
 * Tests for the pre-push worktree precondition.
 *
 * This one is not a lane and has no CI counterpart by design: it exists to
 * simulate the single thing CI does that a local run cannot, which is start
 * from a clean checkout of committed content.
 *
 * The failure it prevents has happened. `pnpm qa` reads the working tree, so a
 * file that exists on disk but was never `git add`ed is read by every lane and
 * passes every one. GitHub checks out only what is committed, the file is not
 * there, and the same suite fails on a missing class. Locally green, remotely
 * red, with nothing in the diff to explain it — because the explanation is in
 * what the diff does *not* contain.
 *
 * An untracked file is therefore the case that matters most here, and it is
 * the one a naive `git diff --quiet` implementation would miss entirely.
 */

import { strict as assert } from 'node:assert';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-worktree.sh' );

const repos = [];

after( async () => {
	await Promise.all(
		repos.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A git repository with one committed file.
 *
 * @return {Promise<string>} Absolute repository path.
 */
async function repo() {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-worktree-' ) );
	repos.push( dir );

	const git = ( ...args ) =>
		execFileSync( 'git', args, { cwd: dir, encoding: 'utf8' } );

	git( 'init', '--quiet', '--initial-branch=main' );
	git( 'config', 'user.email', 'test@example.com' );
	git( 'config', 'user.name', 'Test' );
	// Signing is on for this developer globally; a fixture must not need a key.
	git( 'config', 'commit.gpgsign', 'false' );

	await writeFile( path.join( dir, 'tracked.txt' ), 'committed\n', 'utf8' );
	await writeFile( path.join( dir, '.gitignore' ), 'dist/\n', 'utf8' );

	git( 'add', '.' );
	git( 'commit', '--quiet', '-m', 'initial' );

	return dir;
}

/**
 * Runs the guard against a repository.
 *
 * @param {string} dir Repository.
 * @param {object} env Extra environment.
 * @return {{status: number, stdout: string, stderr: string}}
 */
function run( dir, env = {} ) {
	const result = spawnSync( 'bash', [ CHECKER ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_WORKTREE_REPO: dir, ...env },
	} );

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

test( 'a clean tree passes', async () => {
	const dir = await repo();
	const { status, stdout } = run( dir );

	assert.equal( status, 0 );
	assert.match( stdout, /clean tree/ );
} );

test( 'a modified tracked file fails', async () => {
	const dir = await repo();
	await writeFile( path.join( dir, 'tracked.txt' ), 'changed\n', 'utf8' );

	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.match( stderr, /does not match the commit/ );
	assert.match( stderr, /tracked\.txt/ );
} );

test( 'an untracked file fails, and is called out specifically', async () => {
	/*
	 * The case this guard exists for. CI will not have the file at all, so
	 * every local lane can read it and pass while the same lane fails there.
	 * A tracked-changes-only check would miss it, which is why the message
	 * singles out `??` rather than just listing the porcelain output.
	 */
	const dir = await repo();
	await writeFile( path.join( dir, 'forgotten.php' ), '<?php\n', 'utf8' );

	const { status, stderr } = run( dir );

	assert.equal( status, 1 );
	assert.match( stderr, /forgotten\.php/ );
	assert.match( stderr, /untracked/ );
	assert.match( stderr, /CI will not have them/ );
} );

test( 'a gitignored file does not fail the check', async () => {
	/*
	 * The negative half, and the one that keeps the guard usable. `dist/` is
	 * gitignored and CI builds it fresh, so a built bundle on disk is exactly
	 * as invisible to CI as it is here. A guard that fired on it would fire on
	 * every developer machine after every build.
	 */
	const dir = await repo();
	execFileSync( 'mkdir', [ '-p', path.join( dir, 'dist' ) ] );
	await writeFile( path.join( dir, 'dist', 'bundle.js' ), 'x\n', 'utf8' );

	assert.equal( run( dir ).status, 0 );
} );

test( 'a staged-but-uncommitted change still fails', async () => {
	// Staged is not committed. CI checks out the commit.
	const dir = await repo();
	await writeFile( path.join( dir, 'staged.txt' ), 'new\n', 'utf8' );
	execFileSync( 'git', [ 'add', 'staged.txt' ], { cwd: dir } );

	assert.equal( run( dir ).status, 1 );
} );

test( 'AGGR_QA_ALLOW_DIRTY=1 skips, and says the run is not representative', async () => {
	/*
	 * The documented escape hatch. It must keep working — a guard with no way
	 * past it mid-change is one people disable permanently — but it must also
	 * say what was given up, or the next green run gets trusted as a rehearsal
	 * it is not.
	 */
	const dir = await repo();
	await writeFile( path.join( dir, 'forgotten.php' ), '<?php\n', 'utf8' );

	const { status, stdout } = run( dir, { AGGR_QA_ALLOW_DIRTY: '1' } );

	assert.equal( status, 0 );
	assert.match( stdout, /does not represent the commit/ );
} );

test( 'AGGR_QA_ALLOW_DIRTY only counts when it is exactly 1', async () => {
	// `AGGR_QA_ALLOW_DIRTY=0` and `=false` are people trying to turn it off.
	const dir = await repo();
	await writeFile( path.join( dir, 'forgotten.php' ), '<?php\n', 'utf8' );

	assert.equal( run( dir, { AGGR_QA_ALLOW_DIRTY: '0' } ).status, 1 );
	assert.equal( run( dir, { AGGR_QA_ALLOW_DIRTY: 'false' } ).status, 1 );
} );
