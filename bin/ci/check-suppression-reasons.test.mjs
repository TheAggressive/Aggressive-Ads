/**
 * Tests for the security-suppression gate.
 *
 * PHPCS is this plugin's real defence against SQL injection and XSS, and both
 * of those sniffs are one `phpcs:ignore` away from being switched off. The rule
 * is not "no suppressions" — several here are legitimate. The rule is that a
 * suppression of a *security* sniff states why, because writing the sentence is
 * what makes an unjustifiable one obvious to the person adding it.
 *
 * Testing the gate found two ways to switch a security sniff off that it never
 * looked at, both of which PHPCS 3.13 still honours:
 *
 *   `// phpcs:ignoreFile` disables every sniff in the whole file.
 *   `// @codingStandardsIgnoreFile|Start|Line` does the same and has no `--`
 *   reason syntax at all, so it can never justify itself. Deprecated in 3.2.0,
 *   removed only in 4.0 — this project runs 3.13.
 *
 * The old pattern required whitespace after `ignore`, and both of these are
 * followed by a letter. Neither appears in the codebase today, which makes this
 * prevention rather than a live hole — which is what a guard is for.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-suppression-reasons.mjs' );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root with `inc/` and `templates/`, holding the given files.
 *
 * @param {Record<string, string>} files Paths relative to the root.
 * @return {Promise<string>} Absolute scan root.
 */
async function fixture( files ) {
	const root = await mkdtemp( path.join( tmpdir(), 'aggr-suppress-' ) );
	roots.push( root );

	await mkdir( path.join( root, 'inc' ), { recursive: true } );
	await mkdir( path.join( root, 'templates' ), { recursive: true } );

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
		env: { ...process.env, AGGR_SUPPRESSION_SCAN_DIR: root },
	} );

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

test( 'a security suppression with a real reason passes', async () => {
	const root = await fixture( {
		'inc/repo.php': `<?php
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Custom append-only table, every predicate is served by the object index.
$wpdb->get_results( $sql );
`,
	} );

	const { status, stdout } = run( root );

	assert.equal( status, 0 );
	assert.match( stdout, /ok \(1 files scanned\)/ );
} );

test( 'a security suppression with no reason fails', async () => {
	const root = await fixture( {
		'inc/repo.php': `<?php
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->get_results( $sql );
`,
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /suppressed without a reason/ );
	assert.match( stderr, /inc\/repo\.php:2/ );
} );

test( 'a shrug is not a reason', async () => {
	// Fifteen characters is the floor. "-- ok" and "-- needed" are the two
	// things people write when they have not got an argument.
	const root = await fixture( {
		'inc/repo.php':
			'<?php\n// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ok\n',
	} );

	assert.equal( run( root ).status, 1 );
} );

test( 'a non-security sniff needs no reason', async () => {
	/*
	 * The negative half, and the reason this gate is usable at all. Requiring
	 * an essay for every line-length or naming suppression would make the rule
	 * something people route around rather than satisfy.
	 */
	const root = await fixture( {
		'inc/repo.php':
			'<?php\n// phpcs:ignore Generic.Files.LineLength.TooLong\n$x = 1;\n',
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'phpcs:ignoreFile without a reason fails', async () => {
	/*
	 * The first hole. This disables *every* sniff in the file — strictly
	 * broader than suppressing one named security sniff — and the old pattern
	 * never matched it, because it required whitespace after `ignore` and this
	 * is followed by `File`.
	 */
	const root = await fixture( {
		'inc/legacy.php': '<?php\n// phpcs:ignoreFile\n$wpdb->query( $sql );\n',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /disables every sniff in this file/ );
} );

test( 'phpcs:ignoreFile with a real reason passes', async () => {
	// It is a legitimate directive with a documented `--` form. The rule is
	// the same as everywhere else: say why.
	const root = await fixture( {
		'inc/vendor-shim.php':
			'<?php\n// phpcs:ignoreFile -- Vendored upstream file, reformatting it would break the diff against upstream.\n',
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'the deprecated @codingStandardsIgnore syntax always fails', async () => {
	/*
	 * The second hole, and the worse one: this form has no `--` reason syntax
	 * at all, so a suppression written this way can never justify itself. PHPCS
	 * deprecated it in 3.2.0 and removed it in 4.0 — this project runs 3.13,
	 * where it still silences everything it covers.
	 */
	for ( const directive of [
		'@codingStandardsIgnoreFile',
		'@codingStandardsIgnoreStart',
		'@codingStandardsIgnoreLine',
	] ) {
		const root = await fixture( {
			'inc/legacy.php': `<?php\n// ${ directive }\n$wpdb->query( $sql );\n`,
		} );

		const { status, stderr } = run( root );

		assert.equal( status, 1, directive );
		assert.match( stderr, /deprecated, and cannot state a reason/ );
	}
} );

test( 'templates/ is scanned as well as inc/', async () => {
	// Templates echo things. EscapeOutput is exactly the sniff that matters
	// there, and it is the layer where suppressing it is least visible.
	const root = await fixture( {
		'templates/portal/row.php':
			'<?php\n// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped\necho $x;\n',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /templates\/portal\/row\.php/ );
} );

test( 'an empty scan fails instead of reporting ok', async () => {
	/*
	 * Zero files is not "no unjustified suppressions", it is a guard that read
	 * nothing — the failure mode every guard in this directory is written to
	 * avoid, and the one four of them actually had.
	 */
	const root = await fixture( {} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no PHP files found/ );
} );

test( 'every offence is reported, not just the first', async () => {
	const root = await fixture( {
		'inc/a.php':
			'<?php\n// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared\n',
		'inc/b.php':
			'<?php\n// phpcs:ignore WordPress.Security.NonceVerification.Missing\n',
		'templates/c.php': '<?php\n// @codingStandardsIgnoreFile\n',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /a\.php/ );
	assert.match( stderr, /b\.php/ );
	assert.match( stderr, /c\.php/ );
} );
