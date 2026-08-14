<?php
/**
 * The sign-in screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/login.php';
$aggr_title  = __( 'Sign in', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base-bare.php';
