<?php
/**
 * The organization screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/organization.php';
$aggr_title  = __( 'Organization', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
