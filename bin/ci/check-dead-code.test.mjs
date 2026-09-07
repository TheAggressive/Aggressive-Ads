import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-dead-code.mjs' );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root with enough methods to clear the floor, plus overrides.
 *
 * The filler exists because the lane refuses to pass over a scan that matched
 * almost nothing — so a fixture testing a single method has to look like a
 * real plugin or it trips that guard instead of the one under test.
 *
 * @param {Record<string, string>} overrides Extra files, keyed by relative path.
 * @return {Promise<string>} Scan root.
 */
async function root( overrides = {} ) {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-dead-' ) );
	roots.push( dir );

	let filler = '<?php\nclass Filler {\n';

	for ( let i = 0; i < 210; i++ ) {
		filler += `\tpublic function filler_${ i }() {}\n`;
	}

	filler += '}\n';

	// Every filler method is called from somewhere else, as real ones are.
	let caller = '<?php\n';

	for ( let i = 0; i < 210; i++ ) {
		caller += `$x->filler_${ i }();\n`;
	}

	const files = {
		'inc/class-filler.php': filler,
		'inc/class-caller.php': caller,
		...overrides,
	};

	for ( const [ relative, body ] of Object.entries( files ) ) {
		const absolute = path.join( dir, relative );

		await mkdir( path.dirname( absolute ), { recursive: true } );
		await writeFile( absolute, body, 'utf8' );
	}

	return dir;
}

/**
 * Runs the guard against one scan root.
 *
 * @param {string} dir Scan root.
 * @return {{status: number, output: string}} Exit status and combined output.
 */
function run( dir ) {
	const result = spawnSync( process.execPath, [ CHECKER ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_DEAD_CODE_SCAN_DIR: dir },
	} );

	return {
		status: result.status ?? 1,
		output: `${ result.stdout }${ result.stderr }`,
	};
}

test( 'an honest tree passes and says how much it read', async () => {
	const { status, output } = run( await root() );

	assert.equal( status, 0 );
	assert.match( output, /check-dead-code: ok \(\d+ public methods/ );
} );

test( 'a public method nothing calls is refused', async () => {
	const dir = await root( {
		'inc/Repository/class-thing.php':
			'<?php\nclass Thing {\n\tpublic function opens_in_new_window() {}\n}\n',
	} );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /opens_in_new_window\(\)/ );
} );

test( 'a method registered as a callback in its own file is not dead', async () => {
	const dir = await root( {
		'inc/Admin/class-screen.php':
			"<?php\nclass Screen {\n\tpublic function init() {\n\t\tadd_action( 'admin_menu', array( $this, 'render_page' ) );\n\t}\n\tpublic function render_page() {}\n}\n",

		// `init()` is called by the container, as it is in the real plugin;
		// without it this fixture would fail on the wrong method.
		'inc/class-plugin.php': '<?php\n$service->init();\n',
	} );

	const { status } = run( dir );

	assert.equal(
		status,
		0,
		'Hook callbacks are named only inside their own file, and failing on them would fire on most of inc/Portal and inc/Admin.'
	);
} );

test( 'a method called from a template counts as called', async () => {
	const dir = await root( {
		'inc/class-thing.php':
			'<?php\nclass Thing {\n\tpublic function only_a_template_uses_this() {}\n}\n',
		'templates/portal/page.php':
			'<?php\n$thing->only_a_template_uses_this();\n',
	} );

	const { status } = run( dir );

	assert.equal( status, 0 );
} );

test( 'a method called only from the plugin root counts as called', async () => {
	const dir = await root( {
		'inc/Install/class-uninstaller.php':
			'<?php\nclass Uninstaller {\n\tpublic function run_network() {}\n}\n',
		'uninstall.php': '<?php\n( new Uninstaller() )->run_network();\n',
	} );

	const { status } = run( dir );

	assert.equal(
		status,
		0,
		'run_network() was the audit script’s one false positive, because the plugin root was outside its scan.'
	);
} );

test( 'a similarly named method does not count as a reference', async () => {
	const dir = await root( {
		'inc/class-engine.php':
			'<?php\nclass Engine {\n\tpublic function no_fill_reason() {}\n}\n',
		'inc/class-outcome.php':
			'<?php\nclass Outcome {\n\tpublic function is_no_fill_reason() {}\n}\n',
		'inc/class-user.php': '<?php\n$o->is_no_fill_reason( $x );\n',
	} );

	const { status, output } = run( dir );

	assert.equal(
		status,
		1,
		'is_no_fill_reason() contains no_fill_reason as a substring, and a loose match would have called the dead one alive.'
	);
	assert.match( output, /no_fill_reason\(\)/ );
} );

test( 'a scan that matches nothing fails rather than passing over everything', async () => {
	const dir = await mkdtemp( path.join( tmpdir(), 'aggr-dead-empty-' ) );
	roots.push( dir );

	await mkdir( path.join( dir, 'inc' ), { recursive: true } );
	await writeFile( path.join( dir, 'inc', 'empty.php' ), '<?php\n', 'utf8' );

	const { status, output } = run( dir );

	assert.equal( status, 1 );
	assert.match( output, /protecting nothing/ );
} );
