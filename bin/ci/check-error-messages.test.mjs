/**
 * Tests for the error-message gate.
 *
 * The gate found a code on its first run that a careful hand-grep of the same
 * files had missed, which is the argument for having it and for testing it: it
 * reads `->error( 'code'` across a line break and a person reads the line they
 * expected. Every assertion below is about a way this could go quiet — a raise
 * site it stops matching, a match arm it stops parsing, and a scan root that no
 * longer resolves.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-error-messages.mjs' );

const FEEDBACK = 'inc/Portal/class-creative-feedback.php';
const WORKFLOW = 'inc/Workflow/class-creative-manager.php';

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root holding the given files.
 *
 * @param {Record<string, string>} files Repository-relative path to contents.
 * @return {Promise<string>} The scan root.
 */
async function root( files ) {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-errmsg-' ) );

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
		env: { ...process.env, AGGR_ERROR_MESSAGE_SCAN_DIR: dir },
	} );

	return {
		status: result.status,
		output: `${ result.stdout ?? '' }${ result.stderr ?? '' }`,
	};
}

/**
 * A feedback file whose `error_message()` maps exactly these codes.
 *
 * @param {string[]} codes Codes to map.
 * @return {string} PHP source.
 */
function feedback( codes ) {
	const arms = codes
		.map(
			( code ) =>
				`\t\t\t'${ code }' => __( 'Something.', 'aggressive-ads' ),`
		)
		.join( '\n' );

	return `<?php
	public static function error_message( string $code, int $max_bytes = 0 ): string {
		return match ( $code ) {
${ arms }
			default => __( 'The creative could not be saved. Please try again.', 'aggressive-ads' ),
		};
	}
`;
}

test( 'a raised code with no message is refused', async () => {
	const dir = await root( {
		[ WORKFLOW ]: `<?php
		return $this->error( 'aggr_widget_exploded', __( 'Boom.', 'aggressive-ads' ), 500 );
`,
		[ FEEDBACK ]: feedback( [ 'aggr_upload_no_file' ] ),
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /aggr_widget_exploded/ );
	assert.match( output, /generic "could not be saved" sentence/ );
} );

/*
 * The shape that defeated the hand-grep this gate replaced: `->error(` and the
 * code on separate lines, which is how every validation failure carrying a
 * `sprintf()` message is written.
 */
test( 'a code raised across a line break is still seen', async () => {
	const dir = await root( {
		[ WORKFLOW ]: `<?php
			return $this->error(
				'aggr_weight_out_of_range',
				sprintf( __( 'Between %1$d and %2$d.', 'aggressive-ads' ), 1, 100 ),
				422
			);
`,
		[ FEEDBACK ]: feedback( [ 'aggr_upload_no_file' ] ),
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /aggr_weight_out_of_range/ );
} );

test( 'a fully mapped set passes and says how much it read', async () => {
	const dir = await root( {
		[ WORKFLOW ]: `<?php
		return $this->error( 'aggr_upload_no_file', __( 'No file.', 'aggressive-ads' ), 422 );
`,
		[ FEEDBACK ]: feedback( [ 'aggr_upload_no_file' ] ),
	} );

	const { status, output } = run( dir );

	assert.equal( status, 0 );
	assert.match( output, /ok \(1 codes across 1 workflows, 1 messages\)/ );
} );

/*
 * A code named only in a comment or a comparison is not redirected with, so
 * demanding a sentence for it would make the gate fire on correct code — and a
 * gate that fires on correct code is a defect of its own.
 */
test( 'a code that is not handed to error() is not demanded', async () => {
	const dir = await root( {
		[ WORKFLOW ]: `<?php
		// 'aggr_never_redirected' is compared, never returned.
		if ( 'aggr_never_redirected' === $code ) {
			return true;
		}

		return $this->error( 'aggr_upload_no_file', __( 'No file.', 'aggressive-ads' ), 422 );
`,
		[ FEEDBACK ]: feedback( [ 'aggr_upload_no_file' ] ),
	} );

	assert.equal( run( dir ).status, 0 );
} );

test( 'a scan root with no workflows fails rather than passing', async () => {
	const dir = await root( {
		[ FEEDBACK ]: feedback( [ 'aggr_upload_no_file' ] ),
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /found no error codes to check/ );
} );

test( 'an error_message it cannot parse fails rather than passing', async () => {
	const dir = await root( {
		[ WORKFLOW ]: `<?php
		return $this->error( 'aggr_upload_no_file', __( 'No file.', 'aggressive-ads' ), 422 );
`,
		[ FEEDBACK ]: '<?php\n// The method was renamed.\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /could not read error_message\(\)/ );
} );

test( 'a missing feedback file fails rather than passing', async () => {
	const dir = await root( {
		[ WORKFLOW ]: `<?php
		return $this->error( 'aggr_upload_no_file', __( 'No file.', 'aggressive-ads' ), 422 );
`,
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /is missing, so this lane is blind/ );
} );
