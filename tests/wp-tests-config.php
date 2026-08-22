<?php
/**
 * Configuration consumed only by the WordPress core PHPUnit library.
 *
 * The database is a disposable schema. Core drops every table with this prefix
 * while bootstrapping, so it must never point at the browser-test database or
 * at a developer's WordPress installation.
 *
 * Every value defaults to the Compose stack, so the container path is unchanged
 * and CI reads exactly what it always did. bin/local/wp-tests.sh overrides them
 * to run the same suites natively against a local MySQL. One file rather than
 * two, because two copies of a database configuration is how the runners start
 * testing different things.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

/**
 * Reads an override, falling back to the Compose stack's value.
 *
 * @param string $name    Environment variable name.
 * @param string $default Value used by the container.
 * @return string
 */
function aggr_tests_env( string $name, string $default ): string {
	$value = getenv( $name );

	return is_string( $value ) && '' !== $value ? $value : $default;
}

define( 'ABSPATH', rtrim( aggr_tests_env( 'AGGR_TESTS_ABSPATH', '/var/www/html' ), '/' ) . '/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

/*
 * Only the native runner sets this, and it must.
 *
 * The multisite suite calls activate_plugin( plugin_basename( AGGR_PLUGIN_FILE ) ).
 * plugin_basename() resolves a path by stripping WP_PLUGIN_DIR, and the
 * bootstrap loads this plugin straight from the checkout rather than through
 * wp_register_plugin_realpath() — so when the checkout is not inside ABSPATH a
 * symlink does not help and five tests die with "Plugin file does not exist".
 * The container needs none of this: the checkout is bind-mounted at exactly
 * wp-content/plugins/aggressive-ads.
 */
$aggr_tests_plugin_dir = getenv( 'AGGR_TESTS_PLUGIN_DIR' );

if ( is_string( $aggr_tests_plugin_dir ) && '' !== $aggr_tests_plugin_dir ) {
	define( 'WP_PLUGIN_DIR', rtrim( $aggr_tests_plugin_dir, '/' ) );
	define( 'WP_PLUGIN_URL', 'http://example.test/wp-content/plugins' );
}

define( 'DB_NAME', aggr_tests_env( 'AGGR_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', aggr_tests_env( 'AGGR_TESTS_DB_USER', 'wordpress' ) );
define( 'DB_PASSWORD', aggr_tests_env( 'AGGR_TESTS_DB_PASSWORD', 'wordpress' ) );
define( 'DB_HOST', aggr_tests_env( 'AGGR_TESTS_DB_HOST', 'database' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'FS_CHMOD_DIR', 0755 );
define( 'FS_CHMOD_FILE', 0644 );
define( 'FS_METHOD', 'direct' );

define( 'AUTH_KEY', 'aggressive-ads-tests-auth' );
define( 'SECURE_AUTH_KEY', 'aggressive-ads-tests-secure-auth' );
define( 'LOGGED_IN_KEY', 'aggressive-ads-tests-logged-in' );
define( 'NONCE_KEY', 'aggressive-ads-tests-nonce' );
define( 'AUTH_SALT', 'aggressive-ads-tests-auth-salt' );
define( 'SECURE_AUTH_SALT', 'aggressive-ads-tests-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'aggressive-ads-tests-logged-in-salt' );
define( 'NONCE_SALT', 'aggressive-ads-tests-nonce-salt' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.test' );
define( 'WP_TESTS_EMAIL', 'admin@example.test' );
define( 'WP_TESTS_TITLE', 'Aggressive Ads Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
