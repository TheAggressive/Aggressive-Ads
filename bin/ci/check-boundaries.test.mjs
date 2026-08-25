/**
 * Tests for the architecture boundary gate.
 *
 * This is the guard behind `check-repository-boundary.sh`, and it enforces the
 * three rules in docs/architecture.md: data access only in `inc/Repository/`,
 * no AdSanity identifiers anywhere, and no WordPress calls at all from
 * `inc/Domain/`.
 *
 * It had no test, which matters more here than it looks. The guard is the only
 * thing making `inc/Domain/` cheap to test exhaustively — that layer's rules are
 * asserted in milliseconds precisely because nothing in it can call WordPress,
 * and the day something does, the fast suite stops being able to run at all.
 *
 * Its own history is the argument for testing it. The first version was grep
 * and reported every docblock that *named* a forbidden function, including the
 * comment explaining why the domain layer deliberately does not call
 * `wp_parse_url()`. A later fix records that checking only `T_STRING` let
 * `new \AdSanity_Ads_CPT()` through, "found by testing the gate rather than
 * trusting it" — by hand, once, with nothing to stop it regressing. Both of
 * those are cases below.
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test, { after } from 'node:test';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const CHECKER = path.join( HERE, 'check-boundaries.php' );

const roots = [];

after( async () => {
	await Promise.all(
		roots.map( ( dir ) => rm( dir, { recursive: true, force: true } ) )
	);
} );

/**
 * A scan root containing the given files, relative to the fake plugin root.
 *
 * @param {Record<string, string>} files Relative path to contents.
 * @return {Promise<string>} Absolute scan root.
 */
async function fixture( files ) {
	const root = await mkdtemp( path.join( tmpdir(), 'aggr-bounds-' ) );
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
		env: { ...process.env, AGGR_BOUNDARY_SCAN_DIR: root },
	} );

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

test( 'clean code passes, and says how much it read', async () => {
	const root = await fixture( {
		'inc/Workflow/class-editor.php': `<?php
final class Editor {
	public function run(): string {
		return $this->repository->title();
	}
}
`,
	} );

	const { status, stdout } = run( root );

	assert.equal( status, 0 );
	// The count is the difference between "found no violations" and "read no
	// files", which are the same output without it.
	assert.match( stdout, /ok \(1 files?\)/ );
} );

test( '$wpdb outside inc/Repository/ is refused', async () => {
	const root = await fixture( {
		'inc/Workflow/class-editor.php':
			'<?php global $wpdb; $wpdb->query( "" );',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /\$wpdb outside inc\/Repository\// );
} );

test( '$wpdb inside inc/Repository/ is allowed', async () => {
	// The negative half. A gate that refused this would refuse the entire
	// persistence layer, which is the one place the rule exists to permit.
	const root = await fixture( {
		'inc/Repository/class-campaign-repository.php':
			'<?php global $wpdb; $wpdb->query( "" );',
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'a data-access call outside inc/Repository/ is refused', async () => {
	const root = await fixture( {
		'inc/Workflow/class-editor.php':
			'<?php $x = get_post_meta( 1, "k", true );',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /get_post_meta\(\) outside inc\/Repository\// );
} );

test( 'templates/ is held to the same rule as inc/', async () => {
	/*
	 * The guard's own docblock says templates/ is "the layer most likely to
	 * reach for get_post_meta() — the data is right there and the template is
	 * already rendering the post — and the layer where doing so is least
	 * visible in review". That claim was load-bearing and unasserted.
	 */
	const root = await fixture( {
		'inc/Workflow/class-editor.php': '<?php final class Editor {}',
		'templates/campaign.php': '<?php echo get_post_meta( 1, "k", true );',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /templates\/campaign\.php/ );
} );

test( 'inc/Domain/ may not call WordPress at all', async () => {
	// Domain's whole value is being testable with no bootstrap. One esc_html()
	// in it and the fast suite needs WordPress loaded.
	const root = await fixture( {
		'inc/Domain/class-rules.php':
			'<?php final class Rules { public function f() { return esc_html( "x" ); } }',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /inc\/Domain\/ calls no WordPress/ );
} );

test( 'inc/Domain/ may call its own functions and other layers may call WordPress', async () => {
	// Two negatives in one: the rule is scoped to Domain, and scoped to
	// WordPress. A gate that refused either half would be unusable.
	const root = await fixture( {
		'inc/Domain/class-rules.php':
			'<?php final class Rules { public function f() { return normalize_slug( "x" ); } }',
		'inc/Workflow/class-editor.php':
			'<?php final class Editor { public function f() { return esc_html( "x" ); } }',
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'a method named like a forbidden function is not a call to it', async () => {
	/*
	 * `$this->get_post()` and `Foo::get_post()` are our own methods. A gate
	 * that read them as core calls would fire on correct code, which teaches
	 * people to work around the gate — the failure the tokenizer rewrite was
	 * for.
	 */
	const root = await fixture( {
		'inc/Workflow/class-editor.php': `<?php
final class Editor {
	public function get_post( int $id ): string {
		return $this->get_post( $id ) . Helper::get_post( $id );
	}
}
`,
	} );

	assert.equal( run( root ).status, 0 );
} );

test( 'a docblock naming a forbidden function does not fail the build', async () => {
	/*
	 * The documented regression, verbatim: the first grep version reported the
	 * comment explaining why the domain layer deliberately does not call
	 * wp_parse_url(). Reading code as text cannot tell a call from prose about
	 * a call.
	 */
	const root = await fixture( {
		'inc/Domain/class-rules.php': `<?php
/**
 * This layer deliberately does not call wp_parse_url(), get_post_meta() or
 * esc_html(). See docs/architecture.md.
 */
final class Rules {
	// Nor adsanity_get_ads(), and never '_start_date'.
	public function f(): int {
		return 1;
	}
}
`,
	} );

	assert.equal(
		run( root ).status,
		0,
		'Prose about a forbidden call is not a forbidden call.'
	);
} );

test( 'an AdSanity identifier is refused as a bare name', async () => {
	const root = await fixture( {
		'inc/Workflow/class-editor.php': '<?php $x = adsanity_get_ads();',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /AdSanity identifier adsanity_get_ads/ );
} );

test( 'an AdSanity identifier is refused when fully qualified', async () => {
	/*
	 * The bug the guard's own comment records: `\AdSanity_Ads_CPT` is
	 * T_NAME_FULLY_QUALIFIED, not T_STRING, so a T_STRING-only check let
	 * `new \AdSanity_Ads_CPT()` straight through. It was found by hand once,
	 * and nothing kept it found.
	 */
	const root = await fixture( {
		'inc/Workflow/class-editor.php': '<?php $x = new \\AdSanity_Ads_CPT();',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /AdSanity identifier/ );
} );

test( 'an AdSanity namespace is refused even with no underscore to match', async () => {
	// `Adsanity\Meta_Data` has no prefixed segment at all, so prefix matching
	// alone misses it. Only the root segment identifies it.
	const root = await fixture( {
		'inc/Workflow/class-editor.php':
			'<?php $x = new Adsanity\\Meta_Data();',
	} );

	assert.equal( run( root ).status, 1 );
} );

test( 'an AdSanity data literal is refused', async () => {
	const root = await fixture( {
		'inc/Workflow/class-editor.php': '<?php $x = get_terms( "ad-group" );',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /AdSanity literal "ad-group"/ );
} );

test( 'a missing scan root fails instead of reporting ok', async () => {
	/*
	 * This branch used to print "ok (nothing to scan yet)" and exit 0. It was
	 * written when inc/ genuinely did not exist, and it outlived that by the
	 * entire life of the plugin: afterwards it could only be reached by inc/
	 * and templates/ both vanishing — a rename, a move, a bad merge — and its
	 * answer to that was to report success.
	 */
	const { status, stderr } = run(
		path.join( tmpdir(), 'aggr-bounds-does-not-exist' )
	);

	assert.equal( status, 1 );
	assert.match( stderr, /neither .* exists/ );
} );

test( 'a scan root with no PHP files fails instead of reporting ok', async () => {
	// The same hole from the other side: the directories are there and empty.
	const root = await fixture( {
		'inc/.gitkeep': '',
		'templates/README.md': 'x',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /no PHP files/ );
} );

test( 'every violation is reported, not just the first', async () => {
	const root = await fixture( {
		'inc/Workflow/a.php': '<?php $x = get_post_meta( 1, "k", true );',
		'inc/Domain/b.php': '<?php $y = esc_html( "x" );',
		'inc/Workflow/c.php': '<?php $z = adsanity_get_ads();',
	} );

	const { status, stderr } = run( root );

	assert.equal( status, 1 );
	assert.match( stderr, /a\.php/ );
	assert.match( stderr, /b\.php/ );
	assert.match( stderr, /c\.php/ );
} );
