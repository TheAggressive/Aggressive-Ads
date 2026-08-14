<?php
/**
 * Shown to a signed-in user who may not reach the portal.
 *
 * A real page rather than wp_die(): somebody who has an account but not the
 * capability is usually a staff member or a former advertiser, and the useful
 * response is an explanation and a way out, not a stack of bare text.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/403.php';
$aggr_title  = __( 'No access', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
