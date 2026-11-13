<?php
/**
 * Current goals and historical selected-period delivery for one fixture member.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Report_Cadence;
use Aggressive\Ads\Notification\Scheduled_Report_Mailer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
$aggr_goals_services = Plugin::instance()->container();
$aggr_goals_user     = get_user_by( 'login', 'advertiser' );
if ( ! $aggr_goals_user instanceof WP_User ) {
	WP_CLI::error( 'The fixture advertiser is missing.' );
}
$aggr_goals_orgs   = $aggr_goals_services->get( Org_Repository::class )->org_ids_for_user( $aggr_goals_user->ID );
$aggr_goals_period = Report_Cadence::period( 'weekly', time(), 'UTC' );
if ( array() === $aggr_goals_orgs || null === $aggr_goals_period ) {
	WP_CLI::error( 'The report fixture needs a member and a released period.' );
}
$aggr_goals_ids   = array();
$aggr_goals_items = $aggr_goals_services->get( Line_Item_Repository::class );
$aggr_goals_last  = null;
for ( $aggr_goals_i = 0; $aggr_goals_i < 12; ++$aggr_goals_i ) {
	$aggr_goals_id = wp_insert_post(
		array(
			'post_type'   => Post_Types::CAMPAIGN,
			'post_status' => Post_Statuses::LIVE,
			'post_author' => $aggr_goals_user->ID,
			'post_title'  => 'E2E browser campaign goals ' . $aggr_goals_i,
		),
		true
	);
	if ( is_wp_error( $aggr_goals_id ) ) {
		WP_CLI::error( 'The fixture campaign could not be created.' );
	}
	$aggr_goals_ids[] = $aggr_goals_id;
	update_post_meta( $aggr_goals_id, Campaign_Repository::META_ORG_ID, $aggr_goals_orgs[0] );
	update_post_meta( $aggr_goals_id, Campaign_Repository::META_START_TS, $aggr_goals_period->utc_start()->getTimestamp() );
	update_post_meta( $aggr_goals_id, Campaign_Repository::META_END_TS, time() + 30 * DAY_IN_SECONDS );
	$aggr_goals_last = $aggr_goals_items->ensure_default( $aggr_goals_id );
	if ( null === $aggr_goals_last || false === $aggr_goals_items->update(
		$aggr_goals_last['id'],
		$aggr_goals_id,
		array(
			'goal_type'   => 'impressions',
			'goal_amount' => 100,
		),
		$aggr_goals_last['revision']
	) ) {
		WP_CLI::error( 'The fixture goal could not be saved.' );
	}
}
if ( null === $aggr_goals_last ) {
	WP_CLI::error( 'The fixture plan is missing.' );
}
$aggr_goals_writer = new Rollup_Repository();
foreach ( array(
	$aggr_goals_period->start => 20,
	gmdate( 'Y-m-d' )         => 30,
) as $aggr_goals_day => $aggr_goals_count ) {
	for ( $aggr_goals_i = 0; $aggr_goals_i < $aggr_goals_count; ++$aggr_goals_i ) {
		if ( ! $aggr_goals_writer->increment( 'impressions', 1, $aggr_goals_last['campaign_id'], $aggr_goals_day, $aggr_goals_last['id'], $aggr_goals_orgs[0] ) ) {
			WP_CLI::error( 'The fixture delivery could not be recorded.' );
		}
	}
}
$aggr_goals_mail = $aggr_goals_services->get( Scheduled_Report_Mailer::class )->message( $aggr_goals_orgs[0], $aggr_goals_period, time() );
if ( is_wp_error( $aggr_goals_mail ) ) {
	WP_CLI::error( $aggr_goals_mail->get_error_message() );
}
WP_CLI::line(
	(string) wp_json_encode(
		array(
			'campaigns' => $aggr_goals_ids,
			'from'      => $aggr_goals_period->start,
			'to'        => $aggr_goals_period->end,
			'body'      => $aggr_goals_mail['body'],
		)
	)
);
