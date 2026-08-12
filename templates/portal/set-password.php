<?php
/**
 * The portal set-password screen.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_screen = LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/set-password.php';
$laao_ads_title  = __( 'Choose your password', 'laao-advertiser-portal' );

require LAAO_ADS_PLUGIN_DIR . 'templates/portal/base-bare.php';
