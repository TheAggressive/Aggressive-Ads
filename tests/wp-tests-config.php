<?php
/**
 * Configuration consumed only by the WordPress core PHPUnit library.
 *
 * The database is a disposable schema created by compose.yml. Core drops every
 * table with this prefix while bootstrapping, so it must never point at the
 * browser-test database or at a developer's WordPress installation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

define( 'ABSPATH', '/var/www/html/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'database' );
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
