<?php
/**
 * The campaigns screen.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_screen = LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/campaigns.php';
$laao_ads_title  = __( 'Campaigns', 'laao-advertiser-portal' );

require LAAO_ADS_PLUGIN_DIR . 'templates/portal/base.php';
