<?php
/**
 * The account screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/account.php';
$aggr_title  = __( 'Account', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
