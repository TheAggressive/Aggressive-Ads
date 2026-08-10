<?php
/**
 * A single campaign.
 *
 * The campaign is resolved here rather than in the screen, because the answer
 * decides the response status and status_header() has to run before a byte of
 * the document is written. Resolving it one file later meant the not-found page
 * went out with 200 — it looked right and told every client the wrong thing.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Router;
use LAAO_Advertiser_Portal\Portal\View_Data;

$laao_ads_request = Plugin::instance()->container()->get( Router::class )->request();

/*
 * A campaign the caller may not read resolves to exactly the same null as one
 * that does not exist. Distinguishing them would let anyone enumerate which ids
 * are real, and a 403 on a neighbouring organization's campaign confirms that
 * organization has one.
 */
$laao_ads_campaign = null === $laao_ads_request
	? null
	: Plugin::instance()->container()->get( View_Data::class )->campaign( $laao_ads_request->object_id );

if ( null === $laao_ads_campaign ) {
	status_header( 404 );
	nocache_headers();

	$laao_ads_screen = LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/campaign-not-found.php';
	$laao_ads_title  = __( 'Campaign not found', 'laao-advertiser-portal' );
} else {
	$laao_ads_screen = LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/campaign.php';
	$laao_ads_title  = (string) $laao_ads_campaign['title'];
}

require LAAO_ADS_PLUGIN_DIR . 'templates/portal/base.php';
