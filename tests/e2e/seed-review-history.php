<?php
/**
 * One recorded refusal on the review-preview campaign.
 *
 * The preview seed makes the campaign and the ad. This records the decision
 * the review screen is supposed to show, through the same repository the
 * approval workflow writes. Rebuilt every run so a previous decision does not
 * become a second line.
 *
 * Echoes JSON: the campaign id, and the display name the screen will show.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Decision_Repository;
use Aggressive\Ads\Repository\Creative_Repository;

$aggr_post = get_page_by_path( 'e2e-review-preview', OBJECT, Post_Types::CAMPAIGN );

if ( ! $aggr_post instanceof WP_Post ) {
	echo '0';

	return;
}

$aggr_creatives = ( new Creative_Repository() )->for_campaign( (int) $aggr_post->ID );

if ( array() === $aggr_creatives ) {
	echo '0';

	return;
}

$aggr_decisions = new Creative_Decision_Repository();
$aggr_decisions->install_table();
$aggr_decisions->delete_for_campaign( (int) $aggr_post->ID );

$aggr_admin   = get_user_by( 'login', 'admin' );
$aggr_actor   = $aggr_admin instanceof WP_User ? (string) $aggr_admin->display_name : '';
$aggr_written = $aggr_decisions->record(
	array(
		'revision_id'     => (int) $aggr_creatives[0]['id'],
		'campaign_id'     => (int) $aggr_post->ID,
		'organization_id' => (int) get_post_meta( $aggr_post->ID, Campaign_Repository::META_ORG_ID, true ),
		'decision'        => Creative_Decision_Repository::REJECTED,
		'reason'          => 'The logo is stretched.',
		'actor_user_id'   => $aggr_admin instanceof WP_User ? (int) $aggr_admin->ID : 0,
	)
);

if ( $aggr_written <= 0 || '' === $aggr_actor ) {
	echo '0';

	return;
}

echo wp_json_encode(
	array(
		'campaign' => (int) $aggr_post->ID,
		'actor'    => $aggr_actor,
	)
);
