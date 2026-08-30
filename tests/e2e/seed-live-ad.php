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
use Aggressive\Ads\Workflow\Campaign_State_Machine;
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

/*
 * Scheduled, not live — and that difference is the point of this fixture.
 *
 * An earlier version of this seeder created the campaign already `aggr_live`
 * and `$wpdb->insert`ed the assignment already `live` with its attachment
 * already set. That is the *output* of the delivery pipeline, hand-written, so
 * the browser test could only ever prove the renderer draws a creative it was
 * handed. It proved nothing about how a row reaches that state — and for a long
 * time nothing did: `Assignment_Rules::status_for_campaign()` had exactly one
 * production caller, the one-time P2 backfill, so every campaign that went live
 * afterwards kept assignments at `draft` and served nothing. The suite stayed
 * green throughout, this fixture included.
 *
 * So the campaign now starts one legal edge short of live, with a window that
 * has already opened, and the transition below is driven through the real state
 * machine. If the projection stops running, this fixture stops serving and the
 * viewability spec goes red — which is what it should always have done.
 */
$aggr_campaign_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::CAMPAIGN,
		'post_status' => Post_Statuses::SCHEDULED,
		'post_title'  => 'E2E live advertisement',
		'post_name'   => 'e2e-live-ad',
	)
);

update_post_meta( $aggr_campaign_id, Campaign_Repository::META_ORG_ID, $aggr_org_id );
add_post_meta( $aggr_campaign_id, Campaign_Repository::META_PLACEMENT_ID, $aggr_placement_id );

// `scheduled → live` carries GUARD_STARTED, so the window has to have opened.
update_post_meta( $aggr_campaign_id, Campaign_Repository::META_START_TS, time() - DAY_IN_SECONDS );
update_post_meta( $aggr_campaign_id, Campaign_Repository::META_END_TS, time() + ( 30 * DAY_IN_SECONDS ) );

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
		'status'        => Assignment_Rules::READY,
		'weight'        => 100,
		'click_url'     => 'https://example.com/e2e-live',
		'attachment_id' => 0,
		'alt_text'      => 'E2E live advertisement',
		'width'         => 728,
		'height'        => 90,
		'revision'      => 1,
	)
);

// Serving reads assignments only once the backfill reports finished.
update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

/*
 * The transition under test. `apply_system()` because `scheduled → live` is a
 * system edge — the campaign clock's, not a person's.
 */
Plugin::instance()->container()->get( Campaign_State_Machine::class )
	->apply_system( (int) $aggr_campaign_id, Post_Statuses::LIVE );

/*
 * Assert the fixture actually serves before the browser is asked to look at it.
 *
 * A seeder that silently produces a non-serving ad turns a broken pipeline into
 * a confusing screenshot diff twenty seconds later. Failing here names the
 * cause instead.
 */
$aggr_seeded = $wpdb->get_row(
	$wpdb->prepare(
		'SELECT status, attachment_id FROM %i WHERE campaign_id = %d LIMIT 1',
		$aggr_assignments->table_name(),
		(int) $aggr_campaign_id
	),
	ARRAY_A
);

if ( Assignment_Rules::LIVE !== ( $aggr_seeded['status'] ?? '' ) ) {
	throw new RuntimeException(
		'Seed failed: the campaign went live and its assignment did not. Assignment_Projection is not running.'
	);
}

if ( (int) ( $aggr_seeded['attachment_id'] ?? 0 ) !== (int) $aggr_image ) {
	throw new RuntimeException(
		'Seed failed: the promoted attachment was not projected onto the assignment, so the slot cannot fill.'
	);
}

/*
 * Two slots on one page: one that a scroll can reach and one that a scroll
 * cannot, so the negative half of the assertion is a real page state rather
 * than a mocked one.
 */
$aggr_spacer = str_repeat( '<!-- wp:paragraph --><p>Scrolling filler.</p><!-- /wp:paragraph -->', 60 );
$aggr_slot   = '<!-- wp:aggr/ad-slot {"slot":"e2e-browser-placement"} /-->';

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

/*
 * A second page whose slot rotates, and whose slot is at the top.
 *
 * Rotation is gated on the slot being on screen, so the viewability page —
 * which deliberately opens with its slot far below the fold — would never
 * rotate at all. Two pages rather than two slots on one, because the negative
 * half of the rotation assertion is the viewability page's static slot: same
 * placement, same creative, no interval.
 */
$aggr_rotating = '<!-- wp:aggr/ad-slot {"slot":"e2e-browser-placement","rotate":true,"rotateSeconds":30} /-->';

/*
 * A placement nothing can ever fill: active, correctly sized, and with no
 * campaign pointing at it. That is an unsold slot, which is the ordinary state
 * of most inventory most of the time — not an error, and the case the slot has
 * to collapse for.
 */
$aggr_empty = get_page_by_path( 'e2e-empty-placement', OBJECT, Post_Types::PLACEMENT );

if ( $aggr_empty instanceof WP_Post ) {
	wp_delete_post( $aggr_empty->ID, true );
}

$aggr_empty_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::PLACEMENT,
		'post_status' => 'publish',
		'post_title'  => 'E2E empty placement',
		'post_name'   => 'e2e-empty-placement',
	)
);

update_post_meta( $aggr_empty_id, Placement_Repository::META_IS_ACTIVE, 1 );
update_post_meta( $aggr_empty_id, Placement_Repository::META_SIZE, '728x90' );

$aggr_unsold = '<!-- wp:aggr/ad-slot {"slot":"e2e-empty-placement"} /-->';

/*
 * The same placement as the rotating slot, deliberately. Both fill from one
 * URL, so counting requests to it distinguishes "one slot rotated" from "both
 * slots refetched" without either slot needing a marker the production markup
 * would not carry.
 */
$aggr_static = '<!-- wp:aggr/ad-slot {"slot":"e2e-browser-placement"} /-->';

$aggr_rotation_page = get_page_by_path( 'e2e-rotation', OBJECT, 'page' );

if ( $aggr_rotation_page instanceof WP_Post ) {
	wp_delete_post( $aggr_rotation_page->ID, true );
}

wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'E2E rotation',
		'post_name'    => 'e2e-rotation',
		/*
		 * A rotating slot, a static one on the same placement, and an unsold
		 * one. Three slots so a single wait proves all three behaviours at
		 * once: the rotating slot refetches, the static slot does not, and the
		 * unsold slot removes itself.
		 */
		'post_content' => $aggr_rotating . $aggr_static . $aggr_unsold . $aggr_spacer,
	)
);

echo (int) $aggr_campaign_id;
