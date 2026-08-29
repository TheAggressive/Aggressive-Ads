<?php
/**
 * One live, serving advertisement on a page tall enough to scroll.
 *
 * Viewability is the first behaviour whose correctness lives in the browser,
 * and it cannot be observed without a real fill: a real `IntersectionObserver`
 * against a real image that really enters the viewport. Every other seed here
 * stops short of that — the mapping seed makes an empty slot and the review
 * seed makes a campaign nobody approved.
 *
 * The page deliberately opens with the slot far below the fold, so "not seen"
 * is the starting state and scrolling is what changes it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;

$aggr_placement = get_posts(
	array(
		'post_type'      => Post_Types::PLACEMENT,
		'post_status'    => 'publish',
		'name'           => 'e2e-browser-placement',
		'fields'         => 'ids',
		'posts_per_page' => 1,
	)
);

$aggr_org = get_posts(
	array(
		'post_type'      => Post_Types::ORGANIZATION,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => 1,
	)
);

if ( array() === $aggr_placement || array() === $aggr_org ) {
	echo '0';

	return;
}

$aggr_placement_id = (int) $aggr_placement[0];
$aggr_org_id       = (int) $aggr_org[0];

// Reset rather than reuse: a previous run recorded events against the old
// creative's tokens, and a viewability assertion has to start from nothing seen.
$aggr_existing = get_page_by_path( 'e2e-live-ad', OBJECT, Post_Types::CAMPAIGN );

if ( $aggr_existing instanceof WP_Post ) {
	wp_delete_post( $aggr_existing->ID, true );
}

$aggr_campaign_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::CAMPAIGN,
		'post_status' => Post_Statuses::LIVE,
		'post_title'  => 'E2E live advertisement',
		'post_name'   => 'e2e-live-ad',
	)
);

update_post_meta( $aggr_campaign_id, Campaign_Repository::META_ORG_ID, $aggr_org_id );
add_post_meta( $aggr_campaign_id, Campaign_Repository::META_PLACEMENT_ID, $aggr_placement_id );

/*
 * An approved creative, which means the artwork is a Media Library attachment
 * and the private original is gone. That is the shape a serving ad actually has.
 *
 * Real bytes on disk, not a bare attachment post: `wp_get_attachment_image_src()`
 * reads the generated metadata, and without a file it returns false — the fill
 * then produces no payload and the slot stays empty, which looks exactly like a
 * decision that excluded the candidate.
 */
require_once ABSPATH . 'wp-admin/includes/image.php';

$aggr_uploads = wp_upload_dir();
$aggr_file    = trailingslashit( $aggr_uploads['path'] ) . 'e2e-live-ad.png';

if ( ! file_exists( $aggr_file ) ) {
	// GD frees the image itself since PHP 8; `imagedestroy()` is deprecated.
	$aggr_canvas = imagecreatetruecolor( 728, 90 );
	imagefill( $aggr_canvas, 0, 0, imagecolorallocate( $aggr_canvas, 32, 96, 160 ) );
	imagepng( $aggr_canvas, $aggr_file );
}

$aggr_image = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'E2E live creative',
		'post_status'    => 'inherit',
	),
	$aggr_file
);

wp_update_attachment_metadata( $aggr_image, wp_generate_attachment_metadata( $aggr_image, $aggr_file ) );

$aggr_creative_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::CREATIVE,
		'post_status' => 'publish',
		'post_title'  => 'E2E live creative',
	)
);

update_post_meta( $aggr_creative_id, Creative_Repository::META_CAMPAIGN_ID, (int) $aggr_campaign_id );
update_post_meta( $aggr_creative_id, Creative_Repository::META_ORG_ID, $aggr_org_id );
update_post_meta( $aggr_creative_id, Creative_Repository::META_PLACEMENT_ID, $aggr_placement_id );
update_post_meta( $aggr_creative_id, Creative_Repository::META_SIZE, ( new Placement_Repository() )->size( $aggr_placement_id ) );
update_post_meta( $aggr_creative_id, Creative_Repository::META_KIND, 'image' );
update_post_meta( $aggr_creative_id, Creative_Repository::META_WIDTH, 728 );
update_post_meta( $aggr_creative_id, Creative_Repository::META_HEIGHT, 90 );
update_post_meta( $aggr_creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/e2e-live' );
update_post_meta( $aggr_creative_id, Creative_Repository::META_ALT_TEXT, 'E2E live advertisement' );
update_post_meta( $aggr_creative_id, Creative_Repository::META_ATTACHMENT_ID, (int) $aggr_image );
update_post_meta( $aggr_creative_id, Creative_Repository::META_REVIEW_STATE, 'approved' );

$aggr_assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );
$aggr_assignments->install_table();

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding this plugin's own table for a browser fixture.
$wpdb->insert(
	$aggr_assignments->table_name(),
	array(
		'line_item_id'  => (int) $aggr_campaign_id,
		'campaign_id'   => (int) $aggr_campaign_id,
		'placement_id'  => $aggr_placement_id,
		'revision_id'   => (int) $aggr_creative_id,
		'status'        => Assignment_Rules::LIVE,
		'weight'        => 100,
		'click_url'     => 'https://example.com/e2e-live',
		'attachment_id' => (int) $aggr_image,
		'alt_text'      => 'E2E live advertisement',
		'width'         => 728,
		'height'        => 90,
		'revision'      => 1,
	)
);

// Serving reads assignments only once the backfill reports finished.
update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

/*
 * Two slots on one page: one that a scroll can reach and one that a scroll
 * cannot, so the negative half of the assertion is a real page state rather
 * than a mocked one.
 */
$aggr_spacer = str_repeat( '<!-- wp:paragraph --><p>Scrolling filler.</p><!-- /wp:paragraph -->', 60 );
$aggr_slot   = '<!-- wp:aggr/placement {"slot":"e2e-browser-placement"} /-->';

$aggr_page = get_page_by_path( 'e2e-viewability', OBJECT, 'page' );

if ( $aggr_page instanceof WP_Post ) {
	wp_delete_post( $aggr_page->ID, true );
}

wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'E2E viewability',
		'post_name'    => 'e2e-viewability',
		'post_content' => $aggr_spacer . $aggr_slot . $aggr_spacer,
	)
);

echo (int) $aggr_campaign_id;
