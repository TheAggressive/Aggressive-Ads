<?php
/**
 * One campaign carrying one creative, for the review preview test.
 *
 * The dev seed makes campaigns and the mapping seed makes placements, but
 * nothing makes a creative — the only one in the suite is uploaded by the
 * wizard spec, and depending on that would make the review spec fail or pass
 * according to which specs ran before it.
 *
 * Echoes the campaign id so the caller can navigate straight to it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;

$aggr_slug = 'e2e-review-preview';
$aggr_post = get_page_by_path( $aggr_slug, OBJECT, Post_Types::CAMPAIGN );

if ( $aggr_post instanceof WP_Post ) {
	/*
	 * Reset rather than reuse as found. A review test moves this campaign's
	 * status, so a second run would open on whatever the first left behind and
	 * find none of the actions it came for — which is a test that passes or
	 * fails by how recently it last ran.
	 */
	wp_update_post(
		array(
			'ID'          => $aggr_post->ID,
			'post_status' => Post_Statuses::SUBMITTED,
		)
	);

	delete_post_meta( $aggr_post->ID, Campaign_Repository::META_REVIEW_NOTES );
	delete_post_meta( $aggr_post->ID, Campaign_Repository::META_REVIEWED_BY );

	echo (int) $aggr_post->ID;

	return;
}

$aggr_org = get_posts(
	array(
		'post_type'   => Post_Types::ORGANIZATION,
		'post_status' => 'publish',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);

$aggr_placement = get_posts(
	array(
		'post_type'   => Post_Types::PLACEMENT,
		'post_status' => 'publish',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);

if ( array() === $aggr_org || array() === $aggr_placement ) {
	echo '0';

	return;
}

$aggr_campaign_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::CAMPAIGN,
		'post_status' => Post_Statuses::SUBMITTED,
		'post_title'  => 'E2E review preview',
		'post_name'   => $aggr_slug,
	)
);

update_post_meta( $aggr_campaign_id, Campaign_Repository::META_ORG_ID, (int) $aggr_org[0] );
add_post_meta( $aggr_campaign_id, Campaign_Repository::META_PLACEMENT_ID, (int) $aggr_placement[0] );
update_post_meta( $aggr_campaign_id, Campaign_Repository::META_SUBMITTED_AT, time() );

$aggr_creative_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::CREATIVE,
		'post_status' => 'publish',
		'post_title'  => 'E2E review preview creative',
	)
);

update_post_meta( $aggr_creative_id, Creative_Repository::META_CAMPAIGN_ID, (int) $aggr_campaign_id );
update_post_meta( $aggr_creative_id, Creative_Repository::META_ORG_ID, (int) $aggr_org[0] );
update_post_meta( $aggr_creative_id, Creative_Repository::META_PLACEMENT_ID, (int) $aggr_placement[0] );
update_post_meta( $aggr_creative_id, Creative_Repository::META_SIZE, ( new Placement_Repository() )->size( (int) $aggr_placement[0] ) );
update_post_meta( $aggr_creative_id, Creative_Repository::META_KIND, 'image' );
update_post_meta( $aggr_creative_id, Creative_Repository::META_WIDTH, 160 );
update_post_meta( $aggr_creative_id, Creative_Repository::META_HEIGHT, 600 );
update_post_meta( $aggr_creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/e2e-preview' );
update_post_meta( $aggr_creative_id, Creative_Repository::META_ALT_TEXT, 'E2E tall creative' );

echo (int) $aggr_campaign_id;
