<?php
/**
 * The dashboard screen.
 *
 * Prioritises action over decoration. Charts are not here because dashboards
 * often have charts; they are absent until there is a question a chart answers.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_screen = LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/dashboard.php';
$laao_ads_title  = __( 'Dashboard', 'laao-advertiser-portal' );

require LAAO_ADS_PLUGIN_DIR . 'templates/portal/base.php';
