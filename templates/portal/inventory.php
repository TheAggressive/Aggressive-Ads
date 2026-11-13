<?php
/**
 * Advertiser inventory storefront.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/inventory.php';
$aggr_title  = __( 'Inventory', 'aggressive-ads' );
require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
