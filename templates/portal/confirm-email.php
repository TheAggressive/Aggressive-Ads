<?php
/**
 * The portal email confirmation screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/confirm-email.php';
$aggr_title  = __( 'Confirm your email', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base-bare.php';
