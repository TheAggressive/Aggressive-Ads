<?php
/**
 * The portal email confirmation screen.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_screen = LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/confirm-email.php';
$laao_ads_title  = __( 'Confirm your email', 'laao-advertiser-portal' );

require LAAO_ADS_PLUGIN_DIR . 'templates/portal/base-bare.php';
