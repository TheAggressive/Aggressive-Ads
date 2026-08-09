<?php
/**
 * Bootstrap for the integration, security, rest and upgrade suites.
 *
 * Loads real WordPress from the core test suite. The assertions these suites
 * carry — org-scoped map_meta_cap, dbDelta idempotence, real REST
 * authorization — are not expressible against mocks, which is the entire
 * reason PHPUnit is pinned to 9.6 here. See
 * docs/adr/0013-phpunit-9-with-wp-test-suite.md.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

$laao_ads_root = dirname( __DIR__, 2 );

// wp-env mounts the WordPress PHPUnit suite here and exports WP_TESTS_DIR.
$laao_ads_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! is_string( $laao_ads_tests_dir ) || '' === $laao_ads_tests_dir ) {
	$laao_ads_tests_dir = '/wordpress-phpunit';
}

$laao_ads_tests_dir = rtrim( $laao_ads_tests_dir, '/\\' );

if ( ! file_exists( $laao_ads_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite at {$laao_ads_tests_dir}.\n"
		. "These suites run inside wp-env: pnpm test:php:integration\n"
	);
	exit( 1 );
}

require_once $laao_ads_root . '/vendor/autoload.php';
require_once $laao_ads_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before WordPress finishes booting.
 *
 * Guarded by the constant as well as require_once, because wp-env activates the
 * plugin in the tests environment too — loading it twice would fatal on the
 * constant definitions rather than on anything informative.
 *
 * @return void
 */
function laao_ads_manually_load_plugin(): void {
	if ( defined( 'LAAO_ADS_VERSION' ) ) {
		return;
	}

	require_once dirname( __DIR__, 2 ) . '/laao-advertiser-portal.php';
}

tests_add_filter( 'muplugins_loaded', 'laao_ads_manually_load_plugin' );

require $laao_ads_tests_dir . '/includes/bootstrap.php';

/*
 * WordPress is fully loaded from here.
 *
 * Install once, so every suite starts against a real schema. The core test
 * suite wraps each test in a transaction and rolls it back, but DDL causes an
 * implicit commit — so the table has to be created here rather than per-test,
 * and a test that wants to exercise a fresh install drops it explicitly.
 */
( new LAAO_Advertiser_Portal\Install\Installer(
	new LAAO_Advertiser_Portal\Repository\Audit_Repository(),
	new LAAO_Advertiser_Portal\Security\Roles()
) )->install();
