/**
 * Tests for the stylesheet gate.
 *
 * `inc/` has boundary guards that make whole categories of mistake impossible.
 * The stylesheets had nothing equivalent until this, and the defects it was
 * written for are the kind no CSS linter can see: `aggr-linkbutton` was written
 * into two components and defined nowhere, so the browser drew its default
 * button — a grey box around a campaign name — with Stylelint, axe and the
 * whole suite green.
 *
 * Testing the gate found the failure four of its siblings had: `filesIn()`
 * answers a missing directory with an empty list, which is right for an
 * optional subdirectory and wrong for the roots. With no stylesheets there are
 * no definitions and with no markup there are no uses, so "nothing is
 * undefined" is trivially true of both — a renamed `src/` turned the gate off
 * and printed "ok".
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-styles.mjs' );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A repository-shaped scan root holding the given files.
 *
 * @param {Record<string, string>} files Paths relative to the root.
 * @return {Promise<string>} Absolute scan root.
 */
async function fixture( files ) {
	const root = await mkdtemp( path.join( tmpdir(), 'aggr-styles-' ) );
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
	const result = spawnSync( process.execPath, [ CHECKER ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_STYLES_SCAN_DIR: root },
	} );

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

/** A minimal tree: one stylesheet, one template. */
function tree( css, markup ) {
	return {
		'src/styles/components.css': css,
		'templates/portal/row.php': markup,
	};
}

test( 'a class that is defined passes, and the counts are reported', async () => {
	const root = await fixture(
		tree(
			'.aggr-pill { color: red; }',
			'<?php ?><span class="aggr-pill">Live</span>'
		)
	);

	const { status, stdout } = run( root );

	assert.equal( status, 0 );
	// The counts are what make a vacuous run visible.
	assert.match( stdout, /ok \(1 stylesheets, 1 markup files\)/ );
} );

test( 'a class used but never defined fails', async () => {
	/*
	 * The real defect, verbatim: `aggr-linkbutton` was written into two
	 * components and defined nowhere, so the browser drew its default button.
	 * No CSS linter can see this — it checks the stylesheet in isolation and
	 * cannot know what the markup asks for.
	 */
	const root = await fixture(
		tree(
			'.aggr-pill { color: red; }',
			'<?php ?><a class="aggr-linkbutton">Campaign</a>'
		)
	);

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /aggr-linkbutton/ );
	assert.match( stderr, /no rule under src\// );
} );

test( 'a token read but never declared fails', async () => {
	const root = await fixture(
		tree(
			'.aggr-pill { color: var(--aggr-ink); }',
			'<?php ?><span class="aggr-pill">x</span>'
		)
	);

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /--aggr-ink/ );
	assert.match( stderr, /never declared/ );
} );

test( 'a token with a fallback is not required to exist', async () => {
	// `var(--aggr-x, 4px)` cannot break the page: the fallback is the whole
	// point. Flagging it would make the gate fire on correct code.
	const root = await fixture(
		tree(
			'.aggr-pill { gap: var(--aggr-gap, 4px); }',
			'<?php ?><span class="aggr-pill">x</span>'
		)
	);

	assert.equal( run( root ).status, 0 );
} );

test( 'a dynamic modifier only needs its prefix to exist', async () => {
	/*
	 * `aggr-pill--<?php echo $status ?>` cannot be resolved here, and should
	 * not be: which modifier a status maps to is the server's business. The
	 * prefix existing is the most that can honestly be asserted.
	 */
	const root = await fixture(
		tree(
			'.aggr-pill--live { color: green; }',
			'<?php ?><span class="aggr-pill--<?php echo $status; ?>">x</span>'
		)
	);

	assert.equal( run( root ).status, 0 );
} );

test( 'a non-aggr class is ignored', async () => {
	// The gate owns the plugin's own namespace. Core and third-party classes
	// are not its business and it cannot see their definitions.
	const root = await fixture(
		tree(
			'.aggr-pill { color: red; }',
			'<?php ?><div class="components-panel wp-block-group aggr-pill">x</div>'
		)
	);

	assert.equal( run( root ).status, 0 );
} );

test( 'a missing src/ fails instead of reporting ok', async () => {
	/*
	 * The hole this shares with four sibling guards. With no stylesheets there
	 * are no definitions, so nothing can be undefined, so the gate passes over
	 * a codebase it never read.
	 */
	const root = await fixture( {
		'templates/portal/row.php':
			'<?php ?><span class="aggr-anything">x</span>',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no stylesheets found/ );
} );

test( 'a missing markup tree fails instead of reporting ok', async () => {
	// The same hole from the other side: stylesheets with nothing using them.
	const root = await fixture( {
		'src/styles/components.css': '.aggr-pill {}',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no markup found/ );
} );

test( 'every undefined name is reported, not just the first', async () => {
	const root = await fixture(
		tree(
			'.aggr-pill { color: red; }',
			'<?php ?><a class="aggr-linkbutton">x</a><b class="aggr-chip">y</b>'
		)
	);

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /aggr-linkbutton/ );
	assert.match( stderr, /aggr-chip/ );
} );
