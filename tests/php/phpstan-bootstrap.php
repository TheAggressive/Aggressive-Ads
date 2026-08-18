<?php
/**
 * Bootstrap for static analysis.
 *
 * Defines the constants the root plugin file defines at runtime, so PHPStan
 * knows their types rather than inferring mixed. Nothing here executes in
 * production; it exists only to describe the runtime to the analyser.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

/**
 * The real plugin root, not this file's directory.
 *
 * PHPStan resolves `require AGGR_PLUGIN_DIR . 'templates/…'` against this
 * constant, so pointing it at tests/php made every template include report as
 * a missing file. Pointed at the real root it does the opposite and useful
 * thing: a template that requires a partial nobody has written fails analysis.
 */
define( 'AGGR_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'AGGR_VERSION', '1.2.1' );
define( 'AGGR_PLUGIN_FILE', AGGR_PLUGIN_DIR . 'aggressive-ads.php' );
define( 'AGGR_PLUGIN_URL', 'https://example.test/wp-content/plugins/aggressive-ads/' );
define( 'AGGR_MIN_PHP', '8.4' );
define( 'AGGR_MIN_WP', '6.7' );
