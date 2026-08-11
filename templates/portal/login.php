<?php
/**
 * The sign-in screen.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_screen = LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/login.php';
$laao_ads_title  = __( 'Sign in', 'laao-advertiser-portal' );

require LAAO_ADS_PLUGIN_DIR . 'templates/portal/base-bare.php';
