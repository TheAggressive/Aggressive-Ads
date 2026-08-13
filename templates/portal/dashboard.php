<?php
/**
 * The dashboard screen.
 *
 * Prioritises action over decoration. Charts are not here because dashboards
 * often have charts; they are absent until there is a question a chart answers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/dashboard.php';
$aggr_title  = __( 'Dashboard', 'aggressive-ads' );

require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
