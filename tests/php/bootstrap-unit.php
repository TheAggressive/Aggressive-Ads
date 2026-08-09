<?php
/**
 * Bootstrap for the unit suite.
 *
 * Loads Composer's dev autoloader (PHPUnit, polyfills, Brain\Monkey) and the
 * plugin's own production autoloader. WordPress is deliberately absent — a
 * unit test that needs it belongs in the integration suite.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

$laao_ads_root = dirname( __DIR__, 2 );

if ( ! is_file( $laao_ads_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Composer dependencies are missing. Run: composer install\n" );
	exit( 1 );
}

require_once $laao_ads_root . '/vendor/autoload.php';
require_once $laao_ads_root . '/inc/class-autoloader.php';

LAAO_Advertiser_Portal\Autoloader::register( $laao_ads_root . '/inc' );

// The constants the root plugin file would have defined. Unit tests never load
// that file, because loading it boots the plugin.
define( 'LAAO_ADS_VERSION', '0.1.0' );
define( 'LAAO_ADS_PLUGIN_FILE', $laao_ads_root . '/laao-advertiser-portal.php' );
define( 'LAAO_ADS_PLUGIN_DIR', $laao_ads_root . '/' );
define( 'LAAO_ADS_PLUGIN_URL', 'https://example.test/wp-content/plugins/laao-advertiser-portal/' );
define( 'LAAO_ADS_MIN_PHP', '8.4' );
define( 'LAAO_ADS_MIN_WP', '6.7' );
