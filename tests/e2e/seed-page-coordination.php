<?php
/**
 * Two slots on one page, and a rule that only a page decision can apply.
 *
 * Competitive separation asks whether a candidate may appear beside what
 * another slot on the same page has already been awarded. Nothing filling one
 * slot at a time can answer that, which is exactly how the rule came to be
 * fully implemented, fully tested, and never once executed for a visitor.
 *
 * The fixture is built so the two answers differ visibly. Slot A is sold to one
 * organization; slot B's only candidate names that organization as a
 * competitor. Decided as a page, B is excluded and stays empty. Decided slot by
 * slot, B has no idea A exists and fills. So a browser that quietly went back
 * to per-slot requests does not merely skip a rule — it renders a different
 * page, and the spec sees it.
 *
 * The artwork is the attachment `seed-live-ad.php` already approved. Minting a
 * second set of real image bytes would prove nothing this does not, and a
 * creative without a file on disk produces no payload at all — which looks
 * exactly like the exclusion under test.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Campaign_State_Machine;

$aggr_source = get_page_by_path( 'e2e-live-ad', OBJECT, Post_Types::CAMPAIGN );

if ( ! $aggr_source instanceof WP_Post ) {
	echo '0';

	return;
}

$aggr_image = 0;

foreach ( get_posts(
	array(
		'post_type'      => Post_Types::CREATIVE,
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => 20,
	)
) as $aggr_candidate ) {
	$aggr_found = (int) get_post_meta( (int) $aggr_candidate, Creative_Repository::META_ATTACHMENT_ID, true );

	if ( $aggr_found > 0 ) {
		$aggr_image = $aggr_found;

		break;
	}
}

if ( 0 === $aggr_image ) {
	echo '0';

	return;
}

/**
 * A publishable organization.
 *
 * @param string $slug  Post name.
 * @param string $title Display name.
 * @return int Organization post id.
 */
function aggr_coord_org( string $slug, string $title ): int {
	$existing = get_page_by_path( $slug, OBJECT, Post_Types::ORGANIZATION );

	if ( $existing instanceof WP_Post ) {
		wp_delete_post( $existing->ID, true );
	}

	return (int) wp_insert_post(
		array(
			'post_type'   => Post_Types::ORGANIZATION,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => $slug,
		)
	);
}

/**
 * An active placement of the fixture's own size.
 *
 * @param string $slug  Post name, which is also the slot slug.
 * @param string $title Display name.
 * @return int Placement post id.
 */
function aggr_coord_placement( string $slug, string $title ): int {
	$existing = get_page_by_path( $slug, OBJECT, Post_Types::PLACEMENT );

	if ( $existing instanceof WP_Post ) {
		wp_delete_post( $existing->ID, true );
	}

	$id = (int) wp_insert_post(
		array(
			'post_type'   => Post_Types::PLACEMENT,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => $slug,
		)
	);

	update_post_meta( $id, Placement_Repository::META_IS_ACTIVE, 1 );
	update_post_meta( $id, Placement_Repository::META_SIZE, '728x90' );

	return $id;
}

$aggr_org_a = aggr_coord_org( 'e2e-coord-org-a', 'E2E coordination org A' );
$aggr_org_b = aggr_coord_org( 'e2e-coord-org-b', 'E2E coordination org B' );

$aggr_slot_a = aggr_coord_placement( 'e2e-coord-a', 'E2E coordination slot A' );
$aggr_slot_b = aggr_coord_placement( 'e2e-coord-b', 'E2E coordination slot B' );

global $wpdb;

$aggr_assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );
$aggr_assignments->install_table();

$aggr_line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );

/**
 * A live campaign holding one placement, with its artwork already approved.
 *
 * @param string $slug         Post name.
 * @param string $title        Display name.
 * @param int    $org_id       Owning organization.
 * @param int    $placement_id Placement it fills.
 * @param int    $image        Attachment id for the artwork.
 * @return int Campaign post id.
 */
function aggr_coord_campaign( string $slug, string $title, int $org_id, int $placement_id, int $image ): int {
	global $wpdb;

	$existing = get_page_by_path( $slug, OBJECT, Post_Types::CAMPAIGN );

	if ( $existing instanceof WP_Post ) {
		wp_delete_post( $existing->ID, true );
	}

	$campaign_id = (int) wp_insert_post(
		array(
			'post_type'   => Post_Types::CAMPAIGN,
			'post_status' => Post_Statuses::SCHEDULED,
			'post_title'  => $title,
			'post_name'   => $slug,
		)
	);

	update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $org_id );
	add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );
	update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, time() - DAY_IN_SECONDS );
	update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, time() + ( 30 * DAY_IN_SECONDS ) );

	$creative_id = (int) wp_insert_post(
		array(
			'post_type'   => Post_Types::CREATIVE,
			'post_status' => 'publish',
			'post_title'  => $title . ' creative',
			'post_parent' => $campaign_id,
		)
	);

	update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $placement_id );
	update_post_meta( $creative_id, Creative_Repository::META_SIZE, '728x90' );
	update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $image );

	$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );

	/*
	 * Inserted before the transition, because `Assignment_Projection` promotes
	 * artwork onto the assignments that exist when the campaign goes live. One
	 * added afterwards keeps `attachment_id` at zero and is refused with
	 * `eligibility_missing_attachment`, which empties the slot for a reason
	 * that looks exactly like the exclusion this fixture is about.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding this plugin's own table for a browser fixture.
	$wpdb->insert(
		$assignments->table_name(),
		array(
			'line_item_id'  => $campaign_id,
			'campaign_id'   => $campaign_id,
			'placement_id'  => $placement_id,
			'revision_id'   => $creative_id,
			'status'        => Assignment_Rules::READY,
			'weight'        => 100,
			'click_url'     => home_url( '/e2e-click-landing/' ),
			'attachment_id' => 0,
			'alt_text'      => $title,
			'width'         => 728,
			'height'        => 90,
			'revision'      => 1,
		)
	);

	return $campaign_id;
}

$aggr_campaign_a = aggr_coord_campaign( 'e2e-coord-campaign-a', 'E2E coordination A', $aggr_org_a, $aggr_slot_a, $aggr_image );
$aggr_campaign_b = aggr_coord_campaign( 'e2e-coord-campaign-b', 'E2E coordination B', $aggr_org_b, $aggr_slot_b, $aggr_image );

/*
 * The rule itself, written to the row the decision path actually reads.
 *
 * `enrich()` joins candidates to `aggr_line_items`, so a delivery setting put
 * anywhere else sits on a row the pipeline never loads and the campaign serves
 * everywhere — passing a test that only checks slot A.
 */
foreach ( array( $aggr_campaign_a, $aggr_campaign_b ) as $aggr_campaign ) {
	$aggr_default = $aggr_line_items->ensure_default( $aggr_campaign );

	if ( ! is_array( $aggr_default ) ) {
		echo '0';

		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pointing the seeded assignment at the real line item.
	$wpdb->update(
		$aggr_assignments->table_name(),
		array( 'line_item_id' => (int) $aggr_default['id'] ),
		array( 'campaign_id' => $aggr_campaign )
	);

	if ( $aggr_campaign === $aggr_campaign_b ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding the page rule under test.
		$wpdb->update(
			$aggr_line_items->table_name(),
			array( 'delivery_settings' => (string) wp_json_encode( array( 'competing_orgs' => array( $aggr_org_a ) ) ) ),
			array( 'id' => (int) $aggr_default['id'] )
		);
	}
}

foreach ( array( $aggr_campaign_a, $aggr_campaign_b ) as $aggr_campaign ) {
	Plugin::instance()->container()->get( Campaign_State_Machine::class )
		->apply_system( $aggr_campaign, Post_Statuses::LIVE );
}

$aggr_page = get_page_by_path( 'e2e-page-coordination' );

if ( $aggr_page instanceof WP_Post ) {
	wp_delete_post( $aggr_page->ID, true );
}

wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'E2E page coordination',
		'post_name'    => 'e2e-page-coordination',
		'post_content' => '<!-- wp:aggr/ad-slot {"slot":"e2e-coord-a"} /--><!-- wp:aggr/ad-slot {"slot":"e2e-coord-b"} /-->',
	)
);

/*
 * Both assignments must be servable before the browser is asked to look, or a
 * broken pipeline becomes a confusing screenshot twenty seconds later instead
 * of a named failure here.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifying the fixture serves.
$aggr_ready = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE campaign_id IN ( %d, %d ) AND attachment_id > 0',
		$aggr_assignments->table_name(),
		$aggr_campaign_a,
		$aggr_campaign_b
	)
);

echo 2 === $aggr_ready ? '1' : '0';
