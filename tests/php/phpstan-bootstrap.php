<?php
/**
 * Bootstrap for static analysis.
 *
 * Defines the constants the root plugin file defines at runtime, so PHPStan
 * knows their types rather than inferring mixed. Nothing here executes in
 * production; it exists only to describe the runtime to the analyser.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

/**
 * The real plugin root, not this file's directory.
 *
 * PHPStan resolves `require LAAO_ADS_PLUGIN_DIR . 'templates/…'` against this
 * constant, so pointing it at tests/php made every template include report as
 * a missing file. Pointed at the real root it does the opposite and useful
 * thing: a template that requires a partial nobody has written fails analysis.
 */
define( 'LAAO_ADS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'LAAO_ADS_VERSION', '0.1.0' );
define( 'LAAO_ADS_PLUGIN_FILE', LAAO_ADS_PLUGIN_DIR . 'laao-advertiser-portal.php' );
define( 'LAAO_ADS_PLUGIN_URL', 'https://example.test/wp-content/plugins/laao-advertiser-portal/' );
define( 'LAAO_ADS_MIN_PHP', '8.4' );
define( 'LAAO_ADS_MIN_WP', '6.7' );
