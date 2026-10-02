<?php
/**
 * One paused campaign, for the test that a cancellation asks first.
 *
 * Its own fixture because the test cancels it, and cancelling is final: a
 * shared campaign — the live ad the viewability spec measures, the creative
 * the review spec previews — would be gone for every spec that ran after.
 *
 * Reset to paused on every run, so a second run finds Cancel on offer rather
 * than the cancelled campaign the first one left behind.
 *
 * Echoes the campaign id, or 0 when there is no organization to own it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Campaign_Repository;

$aggr_slug = 'e2e-cancellable';
$aggr_post = get_page_by_path( $aggr_slug, OBJECT, Post_Types::CAMPAIGN );

if ( $aggr_post instanceof WP_Post ) {
	wp_update_post(
		array(
			'ID'          => $aggr_post->ID,
			'post_status' => Post_Statuses::PAUSED,
		)
	);

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

if ( array() === $aggr_org ) {
	echo '0';

	return;
}

$aggr_campaign_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::CAMPAIGN,
		'post_status' => Post_Statuses::PAUSED,
		'post_title'  => 'E2E cancellable',
		'post_name'   => $aggr_slug,
	)
);

if ( ! is_int( $aggr_campaign_id ) || $aggr_campaign_id <= 0 ) {
	echo '0';

	return;
}

update_post_meta( $aggr_campaign_id, Campaign_Repository::META_ORG_ID, (int) $aggr_org[0] );

echo (int) $aggr_campaign_id;
