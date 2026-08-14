<?php
/**
 * The campaigns screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/campaigns.php';
$aggr_title  = __( 'Campaigns', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
