<?php
/**
 * A route that exists in the grammar but has no screen yet.
 *
 * Deliberately a real portal page rather than a 404: the route is legitimate,
 * the screen is simply not built, and saying so is more useful than pretending
 * the URL is wrong.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_screen = LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/placeholder.php';
$laao_ads_title  = __( 'Coming soon', 'laao-advertiser-portal' );

require LAAO_ADS_PLUGIN_DIR . 'templates/portal/base.php';
