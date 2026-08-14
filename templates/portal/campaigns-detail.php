<?php
/**
 * A single campaign.
 *
 * The campaign is resolved here rather than in the screen, because the answer
 * decides the response status and status_header() has to run before a byte of
 * the document is written. Resolving it one file later meant the not-found page
 * went out with 200 — it looked right and told every client the wrong thing.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\View_Data;

$aggr_request = Plugin::instance()->container()->get( Router::class )->request();

/*
 * A campaign the caller may not read resolves to exactly the same null as one
 * that does not exist. Distinguishing them would let anyone enumerate which ids
 * are real, and a 403 on a neighbouring organization's campaign confirms that
 * organization has one.
 */
$aggr_campaign = null === $aggr_request
	? null
	: Plugin::instance()->container()->get( View_Data::class )->campaign( $aggr_request->object_id );

if ( null === $aggr_campaign ) {
	status_header( 404 );
	nocache_headers();

	$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/campaign-not-found.php';
	$aggr_title  = __( 'Campaign not found', 'aggressive-ads' );
} else {
	$aggr_screen = AGGR_PLUGIN_DIR . 'templates/portal/screens/campaign.php';
	$aggr_title  = (string) $aggr_campaign['title'];
}

require AGGR_PLUGIN_DIR . 'templates/portal/base.php';
