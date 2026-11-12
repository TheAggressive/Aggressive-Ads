<?php
/**
 * One advertiser-owned CSV row with an oversized campaign name.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$aggr_user = get_user_by( 'login', 'advertiser' );
if ( ! $aggr_user instanceof WP_User ) {
	WP_CLI::error( 'The E2E advertiser is missing.' );
}
$aggr_orgs = Plugin::instance()->container()->get( Org_Repository::class )->org_ids_for_user( $aggr_user->ID );
$aggr_slot = get_page_by_path( 'e2e-browser-placement', OBJECT, Post_Types::PLACEMENT );
if ( array() === $aggr_orgs || ! $aggr_slot instanceof WP_Post ) {
	WP_CLI::error( 'The reporting fixture organization or placement is missing.' );
}
$aggr_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::CAMPAIGN,
		'post_status' => Post_Statuses::LIVE,
		'post_title'  => str_repeat( 'X', 1025 ),
		'post_author' => $aggr_user->ID,
	),
	true
);
if ( is_wp_error( $aggr_id ) ) {
	WP_CLI::error( $aggr_id->get_error_message() );
}
update_post_meta( $aggr_id, Campaign_Repository::META_ORG_ID, $aggr_orgs[0] );
if ( ! ( new Rollup_Repository() )->increment( 'impressions', $aggr_slot->ID, $aggr_id, '2026-08-12', 0, $aggr_orgs[0] ) ) {
	WP_CLI::error( 'The export fixture counter could not be written.' );
}
WP_CLI::line( (string) $aggr_id );
