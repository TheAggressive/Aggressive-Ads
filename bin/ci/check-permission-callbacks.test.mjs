/**
 * Tests for the REST permission gate.
 *
 * This guard protects the highest-value asset in the system: another
 * organization's unpublished creative. It had no test, and writing one found
 * that it did not work — the grep it used matched exactly one spelling of one
 * mistake, and four ways past it are things a person writes without thinking.
 *
 * So the cases below are not a description of the implementation. They are the
 * five holes, each one a way an unauthenticated endpoint reached production
 * with this gate reporting "ok".
 *
 * The last of them is the one CLAUDE.md warns about in general terms: a guard
 * that stops matching does not fail, it reports success over code it is no
 * longer reading. Here that was a missing directory and a `|| true`.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-permission-callbacks.php' );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root containing the given files.
 *
 * @param {Record<string, string>} files Relative path to contents.
 * @return {Promise<string>} Absolute scan root.
 */
async function fixture( files ) {
	const root = await mkdtemp( path.join( tmpdir(), 'aggr-perm-' ) );
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
	const result = spawnSync( 'php', [ CHECKER ], {
		encoding: 'utf8',
		env: { ...process.env, AGGR_PERMISSION_SCAN_DIR: root },
	} );

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

/** A route registration with the given permission callback expression. */
function route( callback ) {
	return `<?php
register_route(
	'/campaigns',
	array(
		'methods'             => 'GET',
		'callback'            => array( $this, 'index' ),
		'permission_callback' => ${ callback },
	)
);
`;
}

test( 'a real permission callback passes', async () => {
	const root = await fixture( {
		'REST/class-campaigns-controller.php': route(
			"array( $this, 'permission' )"
		),
	} );

	const { status, stdout } = run( root );

	assert.equal( status, 0, 'A legitimate callback must not fail the build.' );
	// The count is part of the contract: it is what makes a vacuous run visible.
	assert.match( stdout, /ok \(1 files?\)/ );
} );

test( "the literal '__return_true' is refused", async () => {
	const root = await fixture( {
		'REST/class-campaigns-controller.php': route( "'__return_true'" ),
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /__return_true/ );
} );

test( 'the literal is still refused when the array wraps onto the next line', async () => {
	/*
	 * Hole 1. The grep was line-based, so this identical mistake passed purely
	 * because of where the line broke — which is a formatting decision, not a
	 * security one.
	 */
	const root = await fixture( {
		'REST/class-campaigns-controller.php': `<?php
register_route(
	'/campaigns',
	array(
		'permission_callback' =>
			'__return_true',
	)
);
`,
	} );

	assert.equal( run( root ).status, 1 );
} );

test( 'an arrow function returning true is refused', async () => {
	// Hole 2, and one of the two that matter most: "I'll just return true for
	// now" is how a debugging shortcut gets committed, and it reads as code
	// rather than as a stub.
	const root = await fixture( {
		'REST/class-campaigns-controller.php': route(
			'static fn (): bool => true'
		),
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /arrow function returning true/ );
} );

test( 'a closure returning true is refused', async () => {
	// Hole 3.
	const root = await fixture( {
		'REST/class-campaigns-controller.php': route(
			'function () { return true; }'
		),
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /closure returning true/ );
} );

test( 'a route registered with no permission_callback at all is refused', async () => {
	/*
	 * Hole 4, and the worst of them: it leaves nothing in the source to grep
	 * for. WordPress has emitted a _doing_it_wrong notice for this since 5.5
	 * and still registers the route, publicly.
	 */
	const root = await fixture( {
		'REST/class-campaigns-controller.php': `<?php
register_route(
	'/campaigns',
	array(
		'methods'  => 'GET',
		'callback' => array( $this, 'index' ),
	)
);
`,
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no permission_callback/ );
} );

test( 'the wrapper that forwards its options is not itself a violation', async () => {
	/*
	 * Both halves of the one shape this gate cannot judge, and must not guess
	 * about.
	 *
	 * `register_route()`'s *declaration* is not a registration at all, and its
	 * single call site forwards `$args` straight through, so the key is not
	 * lexically present and could not be. Flagging either would fire the gate
	 * on the correct code that every real route depends on — and a gate that
	 * fires on correct code is itself a defect.
	 *
	 * This case exists because sabotaging the declaration skip changed nothing:
	 * no fixture contained a declaration, so the branch was never read. The
	 * guard's behaviour here was carried entirely by the real codebase passing.
	 */
	const root = await fixture( {
		'REST/class-creative-file-controller.php': `<?php
final class Creative_File_Controller {
	public static function register_route( string $route, array $args ): void {
		register_rest_route( self::NAMESPACE, $route, $args );
	}
}
`,
	} );

	const { status, stderr } = run( root );

	assert.equal(
		status,
		0,
		`The wrapper must not be flagged; got: ${ stderr }`
	);
} );

test( 'forwarding is excused only when the options are exactly a variable', async () => {
	// The narrow reading of the rule above. An ordinary registration contains
	// variables — `array( $this, 'index' )` for one — and the first version of
	// this check asked only whether a variable appeared anywhere in the call,
	// so every real route excused itself and the missing-key hole stayed open.
	const root = await fixture( {
		'REST/class-campaigns-controller.php': `<?php
register_route(
	'/campaigns',
	array(
		'methods'  => 'GET',
		'callback' => array( $this, 'index' ),
	)
);
`,
	} );

	assert.equal( run( root ).status, 1 );
} );

test( 'a missing scan directory fails instead of reporting ok', async () => {
	/*
	 * Hole 5. The old guard's `|| true` swallowed grep's error, so a renamed or
	 * moved inc/ produced an empty result set, which is indistinguishable from
	 * a clean one. This is the failure mode CLAUDE.md names: reporting success
	 * over code it is no longer reading.
	 */
	const { status, stderr } = run(
		path.join( tmpdir(), 'aggr-perm-does-not-exist' )
	);

	assert.equal( status, 1 );
	assert.match( stderr, /does not exist/ );
} );

test( 'an empty scan directory fails instead of reporting ok', async () => {
	// The same hole from the other side: the directory is there and holds no
	// PHP. "No violations found" over nothing examined is not a pass.
	const root = await fixture( { 'README.md': 'not php' } );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no PHP files/ );
} );

test( 'prose naming __return_true does not fail the build', async () => {
	/*
	 * The negative half, and the reason this is a tokenizer rather than a grep.
	 * check-boundaries.php records the same lesson: its first version reported
	 * every docblock that named a forbidden function, including the comment
	 * explaining why the function was not called. A gate that fires on correct
	 * code teaches people to work around the gate.
	 *
	 * The guard's own docblock names `__return_true` six times, so this case is
	 * load-bearing for the guard being able to explain itself.
	 */
	const root = await fixture( {
		'REST/class-campaigns-controller.php': `<?php
/**
 * Never write 'permission_callback' => '__return_true' here.
 *
 * A closure returning true, or fn () => true, is the same mistake.
 */
// '__return_true' is banned; see the docblock above.
${ route( "array( $this, 'permission' )" ).replace( '<?php\n', '' ) }`,
	} );

	const { status, stdout } = run( root );

	assert.equal(
		status,
		0,
		'Prose about the mistake is not the mistake, and must not fail the build.'
	);
	assert.match( stdout, /ok/ );
} );

test( 'a callback with real logic is left alone', async () => {
	// The gate refuses empty callbacks, not callbacks it cannot understand.
	// Judging arbitrary logic is not its job and would make it unusable.
	const root = await fixture( {
		'REST/class-campaigns-controller.php': route(
			'function () { return is_user_logged_in() && current_user_can( "read" ); }'
		),
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'every violation is reported, not just the first', async () => {
	// A gate naming one of three sends somebody back for a second run to
	// discover the next one.
	const root = await fixture( {
		'REST/a.php': route( "'__return_true'" ),
		'REST/b.php': route( 'fn () => true' ),
		'REST/c.php': route( 'function () { return true; }' ),
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /a\.php/ );
	assert.match( stderr, /b\.php/ );
	assert.match( stderr, /c\.php/ );
} );
