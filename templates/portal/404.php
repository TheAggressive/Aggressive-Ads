<?php
/**
 * A portal URL that names nothing.
 *
 * Rendered in the portal's own chrome rather than the theme's 404, for the same
 * reason every other screen is: the portal looks the same regardless of which
 * theme is active, and a person who mistyped a URL inside their account should
 * not be dropped into a different-looking site.
 *
 * The 404 status itself is sent by Router::gate(), before this file runs.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/404.php';
$aggr_title  = __( 'Page not found', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
