<?php
/**
 * Bootstrap for the integration, security, rest and upgrade suites.
 *
 * Loads real WordPress from the core test suite. The assertions these suites
 * carry — org-scoped map_meta_cap, dbDelta idempotence, real REST
 * authorization — are not expressible against mocks, which is the entire
 * reason these suites still run on PHPUnit 9.6. See docs/testing-strategy.md.
 *
 * **The autoloader is tests/wp/vendor, not the plugin's.** The plugin runs
 * PHPUnit 13 and this runner runs 9.6; registering both autoloaders would let a
 * `PHPUnit\…` class that is not already declared resolve out of 13 in the
 * middle of a 9.6 run. tests/wp/composer.json maps the test namespace at
 * ../php/ for this reason, so one autoloader serves the whole run. See
 * tests/wp/README.md.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

$aggr_root   = dirname( __DIR__, 2 );
$aggr_runner = $aggr_root . '/tests/wp/vendor/autoload.php';

if ( ! is_file( $aggr_runner ) ) {
	fwrite(
		STDERR,
		"The WordPress test runner is not installed.\n"
		. "Run: bash bin/ci/install-wp-runner.sh\n"
	);
	exit( 1 );
}

require_once $aggr_runner;

$aggr_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

if ( ! is_string( $aggr_tests_dir ) || '' === $aggr_tests_dir ) {
	$aggr_tests_dir = $aggr_root . '/tests/wp/vendor/wp-phpunit/wp-phpunit';
}

$aggr_tests_dir = rtrim( $aggr_tests_dir, '/\\' );

if ( ! file_exists( $aggr_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite at {$aggr_tests_dir}.\n"
		. "Run bash bin/ci/install-wp-runner.sh, pnpm env:start, then pnpm test:php:integration.\n"
	);
	exit( 1 );
}

require_once $aggr_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before WordPress finishes booting.
 *
 * Guarded by the constant as well as require_once so a bootstrap failure is
 * reported by PHPUnit rather than as duplicate plugin constants.
 *
 * @return void
 */
function aggr_manually_load_plugin(): void {
	if ( defined( 'AGGR_VERSION' ) ) {
		return;
	}

	require_once dirname( __DIR__, 2 ) . '/aggressive-ads.php';
}

tests_add_filter( 'muplugins_loaded', 'aggr_manually_load_plugin' );

require $aggr_tests_dir . '/includes/bootstrap.php';

/*
 * WordPress is fully loaded from here.
 *
 * Install once, so every suite starts against a real schema. The core test
 * suite wraps each test in a transaction and rolls it back, but DDL causes an
 * implicit commit — so the table has to be created here rather than per-test,
 * and a test that wants to exercise a fresh install drops it explicitly.
 */
( new Aggressive\Ads\Install\Installer(
	new Aggressive\Ads\Repository\Audit_Repository(),
	new Aggressive\Ads\Security\Roles()
) )->install();
