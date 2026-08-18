<?php
/**
 * Bootstrap for the unit suite.
 *
 * Loads Composer's dev autoloader (PHPUnit, polyfills, Brain\Monkey) and the
 * plugin's own production autoloader. WordPress is deliberately absent — a
 * unit test that needs it belongs in the integration suite.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

$aggr_root = dirname( __DIR__, 2 );

if ( ! is_file( $aggr_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Composer dependencies are missing. Run: composer install\n" );
	exit( 1 );
}

require_once $aggr_root . '/vendor/autoload.php';
require_once $aggr_root . '/inc/class-autoloader.php';

Aggressive\Ads\Autoloader::register( $aggr_root . '/inc' );

// The constants the root plugin file would have defined. Unit tests never load
// that file, because loading it boots the plugin.
define( 'AGGR_VERSION', '1.3.0' );
define( 'AGGR_PLUGIN_FILE', $aggr_root . '/aggressive-ads.php' );
define( 'AGGR_PLUGIN_DIR', $aggr_root . '/' );
define( 'AGGR_PLUGIN_URL', 'https://example.test/wp-content/plugins/aggressive-ads/' );
define( 'AGGR_MIN_PHP', '8.4' );
define( 'AGGR_MIN_WP', '6.7' );
