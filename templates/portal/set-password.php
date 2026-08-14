<?php
/**
 * The portal set-password screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/set-password.php';
$aggr_title  = __( 'Choose your password', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base-bare.php';
