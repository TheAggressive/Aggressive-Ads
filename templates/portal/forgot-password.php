<?php
/**
 * The portal password-recovery request screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/forgot-password.php';
$aggr_title  = __( 'Reset your password', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base-bare.php';
