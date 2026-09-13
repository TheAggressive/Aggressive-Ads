/**
 * Tests for the pipefail-and-grep-q gate.
 *
 * The defect this gate exists for is a race, so it cannot be caught by running
 * the scripts — it passed that way most of the time, which is how it reached a
 * pull request. The gate reads the pattern instead, and every assertion here is
 * about a way it could stop reading it: a flag cluster it no longer matches, a
 * safe form it starts refusing, or a scan root that no longer resolves.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-pipefail-grep.mjs' );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root holding the given scripts.
 *
 * @param {Record<string, string>} files Repository-relative path to contents.
 * @return {Promise<string>} The scan root.
 */
async function root( files ) {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-pipefail-' ) );

	roots.push( dir );

	for ( const [ relative, contents ] of Object.entries( files ) ) {
		const full = path.join( dir, relative );

		await mkdir( path.dirname( full ), { recursive: true } );
		await writeFile( full, contents, 'utf8' );
	}

	return dir;
}

/**
 * Runs the guard against a scan root.
 *
 * @param {string} dir Scan root.
 * @return {{status: number, output: string}}
 */
function run( dir ) {
	const result = spawnSync( process.execPath, [ CHECKER ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_PIPEFAIL_GREP_SCAN_DIR: dir },
	} );

	return {
		status: result.status,
		output: `${ result.stdout ?? '' }${ result.stderr ?? '' }`,
	};
}

/** A script body with pipefail on. */
const strict = ( body ) =>
	`#!/usr/bin/env bash\nset -euo pipefail\n${ body }\n`;

test( 'a pipe into grep -q under pipefail is refused', async () => {
	const dir = await root( {
		'bin/release/verify.sh': strict(
			'if echo "${listing}" | grep -q "x"; then :; fi'
		),
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /bin\/release\/verify\.sh:3/ );
} );

/*
 * The exact shape that failed the package lane: the quiet flag inside a
 * cluster. A matcher looking only for a bare `-q` would pass right over it.
 */
test( 'a quiet flag inside a cluster is refused', async () => {
	const dir = await root( {
		'bin/release/verify.sh': strict(
			'if echo "${listing}" | grep -qxF "${path}"; then :; fi'
		),
		'bin/ci/parity.sh': strict(
			'if ! printf "%s\\n" "$a" | grep -E -q "$b"; then :; fi'
		),
		'bin/ci/tree.sh': strict(
			'git status --porcelain | grep --quiet "^??" && exit 1'
		),
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /verify\.sh:3/ );
	assert.match( output, /parity\.sh:3/ );
	assert.match( output, /tree\.sh:3/ );
} );

test( 'a here-string is the sanctioned form', async () => {
	const dir = await root( {
		'bin/release/verify.sh': strict(
			'if grep -qxF "${path}" <<< "${listing}"; then :; fi'
		),
	} );

	const { status, output } = run( dir );

	assert.equal( status, 0 );
	assert.match(
		output,
		/check-pipefail-grep: ok \(1 scripts with pipefail\)/
	);
} );

/*
 * Neither of these pipes into grep. Refusing them would make the gate fire on
 * correct code, and a gate that does that is a defect of its own.
 */
test( 'a logical or and a non-quiet grep are not refused', async () => {
	const dir = await root( {
		'bin/ci/a.sh': strict( 'command -v jq || grep -q "x" file.txt' ),
		'bin/ci/b.sh': strict(
			'count=$(git status --porcelain | grep -c "^??")'
		),
		'bin/ci/c.sh': strict(
			'# echo "$x" | grep -q "y"  -- a comment, not code'
		),
	} );

	assert.equal( run( dir ).status, 0 );
} );

/*
 * Without pipefail the pipeline's status is grep's, so the race cannot turn a
 * match into a miss. Such a script is not this gate's concern — but it is not
 * counted either, so a tree holding only those still fails for reading nothing.
 */
test( "a script without pipefail is not this gate's concern", async () => {
	const dir = await root( {
		'bin/ci/loose.sh':
			'#!/usr/bin/env bash\nif echo "$x" | grep -q y; then :; fi\n',
		'bin/ci/strict.sh': strict( 'grep -q y <<< "$x"' ),
	} );

	const { status, output } = run( dir );

	assert.equal( status, 0 );
	assert.match( output, /ok \(1 scripts with pipefail\)/ );
} );

test( 'a scan root with no strict scripts fails rather than passing', async () => {
	const dir = await root( { 'bin/readme.md': 'nothing to scan\n' } );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /found no script that sets pipefail/ );
} );
