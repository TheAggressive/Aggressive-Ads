<?php
/**
 * A route that exists in the grammar but has no screen yet.
 *
 * Deliberately a real portal page rather than a 404: the route is legitimate,
 * the screen is simply not built, and saying so is more useful than pretending
 * the URL is wrong.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/placeholder.php';
$aggr_title  = __( 'Coming soon', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
