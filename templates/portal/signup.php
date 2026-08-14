<?php
/**
 * The advertiser signup screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/signup.php';
$aggr_title  = __( 'Create an advertiser account', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base-bare.php';
