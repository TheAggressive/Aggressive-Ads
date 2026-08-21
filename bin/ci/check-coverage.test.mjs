import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { after, test } from 'node:test';

const root = await mkdtemp( path.join( tmpdir(), 'aggr-coverage-' ) );
const checker = path.resolve( import.meta.dirname, 'check-coverage.mjs' );
const childEnv = { ...process.env };
delete childEnv.NODE_TEST_CONTEXT;

after( async () => {
	await rm( root, { recursive: true, force: true } );
} );

function clover( sourcePath, counts ) {
	const lines = counts
		.map(
			( count, index ) =>
				`<line num="${ index + 1 }" type="stmt" count="${ count }"/>`
		)
		.join( '' );

	return `<coverage><project><file name="${ sourcePath }">${ lines }</file></project></coverage>`;
}

test( 'unions statement hits across checkout roots without double counting', async () => {
	const unit = path.join( root, 'unit.xml' );
	const integration = path.join( root, 'integration.xml' );

	await writeFile(
		unit,
		clover(
			'/home/runner/plugin/inc/Domain/class-rule.php',
			[ 1, 0, 1, 0 ]
		)
	);
	await writeFile(
		integration,
		clover(
			'/var/www/html/wp-content/plugins/plugin/inc/Domain/class-rule.php',
			[ 0, 2, 0, 0 ]
		)
	);

	const result = spawnSync(
		process.execPath,
		[ checker, unit, integration ],
		{ encoding: 'utf8', env: childEnv }
	);

	assert.ifError( result.error );
	assert.equal( result.status, 0, result.stderr );
	assert.match( result.stdout, /3\/4 statements across 2 reports \(75\.00%/ );
} );

test( 'fails when a report contains no executable statements', async () => {
	const empty = path.join( root, 'empty.xml' );
	await writeFile(
		empty,
		clover( '/home/runner/plugin/inc/Domain/class-rule.php', [] )
	);

	const result = spawnSync( process.execPath, [ checker, empty ], {
		encoding: 'utf8',
		env: childEnv,
	} );

	assert.ifError( result.error );
	assert.notEqual( result.status, 0 );
	assert.match( result.stderr, /contains no executable statements/ );
} );
