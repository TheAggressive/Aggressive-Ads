<?php
/**
 * A running campaign to edit, on a fixed package with a bigger one on sale.
 *
 * The edit flow's browser test needs what the integration suite cannot give
 * it: a real browser posting real forms. It needs a campaign that is already
 * live, bought on a package, with one size that has an ad and one that does
 * not — and a second package that adds a size — so upgrading, the shared
 * link, renaming from the heading and filling an empty size are all on screen.
 *
 * Rebuilt from scratch on every run. The test submits a proposal and uploads
 * an ad, so a campaign reused as found would open on whatever the last run
 * left behind.
 *
 * The site's live-edit switches are turned on for the test and the previous
 * settings are printed with the ids, so the spec can put them back.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;

$aggr_advertiser = get_user_by( 'login', 'advertiser' );

if ( ! $aggr_advertiser instanceof WP_User ) {
	WP_CLI::error( 'The E2E advertiser does not exist; seed-users.php runs first.' );
}

$aggr_orgs = Plugin::instance()->container()->get( Org_Repository::class )->org_ids_for_user( $aggr_advertiser->ID );

if ( array() === $aggr_orgs ) {
	WP_CLI::error( 'The E2E advertiser has no organization.' );
}

$aggr_org = (int) $aggr_orgs[0];

/**
 * A fixture post found by slug, or made.
 *
 * @param string $type  Post type.
 * @param string $slug  Post name.
 * @param string $title Post title.
 * @return int
 */
$aggr_fixture = static function ( string $type, string $slug, string $title ): int {
	$found = get_page_by_path( $slug, OBJECT, $type );

	if ( $found instanceof WP_Post ) {
		return $found->ID;
	}

	return (int) wp_insert_post(
		array(
			'post_type'   => $type,
			'post_status' => 'publish',
			'post_name'   => $slug,
			'post_title'  => $title,
		)
	);
};

$aggr_sizes      = array(
	'e2e-edit-leaderboard' => array( 'E2E leaderboard', '728x90' ),
	'e2e-edit-sidebar'     => array( 'E2E sidebar', '300x250' ),
	'e2e-edit-skyscraper'  => array( 'E2E skyscraper', '160x600' ),
);
$aggr_placements = array();

foreach ( $aggr_sizes as $aggr_slug => $aggr_size ) {
	$aggr_placements[ $aggr_slug ] = $aggr_fixture( Post_Types::PLACEMENT, $aggr_slug, $aggr_size[0] );
	update_post_meta( $aggr_placements[ $aggr_slug ], Placement_Repository::META_IS_ACTIVE, 1 );
	update_post_meta( $aggr_placements[ $aggr_slug ], Placement_Repository::META_SIZE, $aggr_size[1] );
}

$aggr_package = static function ( string $slug, string $title, int $days, int $cents, array $placements ) use ( $aggr_fixture ): int {
	$id = $aggr_fixture( Post_Types::PACKAGE, $slug, $title );

	delete_post_meta( $id, Package_Repository::META_PLACEMENT_ID );

	foreach ( $placements as $placement ) {
		add_post_meta( $id, Package_Repository::META_PLACEMENT_ID, $placement );
	}

	update_post_meta( $id, Package_Repository::META_DURATION_DAYS, $days );
	update_post_meta( $id, Package_Repository::META_PRICE_CENTS, $cents );
	update_post_meta( $id, Package_Repository::META_CURRENCY, 'USD' );
	update_post_meta( $id, Package_Repository::META_IS_ACTIVE, 1 );

	return $id;
};

$aggr_launch  = $aggr_package( 'e2e-edit-launch', 'E2E Launch', 30, 45000, array( $aggr_placements['e2e-edit-leaderboard'], $aggr_placements['e2e-edit-sidebar'] ) );
$aggr_premium = $aggr_package( 'e2e-edit-premium', 'E2E Premium', 14, 90000, array_values( $aggr_placements ) );

// A fresh campaign every run.
$aggr_previous = get_page_by_path( 'e2e-edit-flight', OBJECT, Post_Types::CAMPAIGN );

if ( $aggr_previous instanceof WP_Post ) {
	foreach ( Plugin::instance()->container()->get( Creative_Repository::class )->for_campaign( $aggr_previous->ID ) as $aggr_old ) {
		wp_delete_post( (int) $aggr_old['id'], true );
	}

	wp_delete_post( $aggr_previous->ID, true );
}

$aggr_start    = ( new DateTimeImmutable( 'yesterday midnight', wp_timezone() ) )->getTimestamp();
$aggr_campaign = (int) wp_insert_post(
	array(
		'post_type'   => Post_Types::CAMPAIGN,
		'post_status' => Post_Statuses::LIVE,
		'post_name'   => 'e2e-edit-flight',
		'post_title'  => 'E2E edit flight',
		'post_author' => $aggr_advertiser->ID,
	)
);

update_post_meta( $aggr_campaign, Campaign_Repository::META_ORG_ID, $aggr_org );
update_post_meta( $aggr_campaign, Campaign_Repository::META_PACKAGE_ID, $aggr_launch );
update_post_meta( $aggr_campaign, Campaign_Repository::META_BUDGET_CENTS, 45000 );
update_post_meta( $aggr_campaign, Campaign_Repository::META_CURRENCY, 'USD' );
update_post_meta( $aggr_campaign, Campaign_Repository::META_START_TS, $aggr_start );
update_post_meta( $aggr_campaign, Campaign_Repository::META_END_TS, Campaign_Rules::fixed_end_ts( $aggr_start, 30, wp_timezone()->getName() ) );
update_post_meta( $aggr_campaign, Campaign_Repository::META_DEFAULT_CLICK_URL, 'https://example.com/e2e-edit' );
add_post_meta( $aggr_campaign, Campaign_Repository::META_PLACEMENT_ID, $aggr_placements['e2e-edit-leaderboard'] );
add_post_meta( $aggr_campaign, Campaign_Repository::META_PLACEMENT_ID, $aggr_placements['e2e-edit-sidebar'] );

$aggr_creative = Plugin::instance()->container()->get( Creative_Repository::class )->create(
	$aggr_campaign,
	$aggr_org,
	$aggr_placements['e2e-edit-leaderboard'],
	array(
		'kind'      => 'image',
		'click_url' => 'https://example.com/e2e-edit',
		'alt_text'  => 'E2E leaderboard ad',
		'size'      => '728x90',
	)
);

update_post_meta( $aggr_creative, Creative_Repository::META_WIDTH, 728 );
update_post_meta( $aggr_creative, Creative_Repository::META_HEIGHT, 90 );

Plugin::instance()->container()->get( Campaign_Request_Repository::class )->clear_pending_edits( $aggr_campaign );

// The switches, on for the test; what they were goes back to the spec.
$aggr_settings = Plugin::instance()->container()->get( Settings::class );
$aggr_document = $aggr_settings->get();
$aggr_saved    = $aggr_document['live_edits'];

foreach ( Settings_Schema::edit_keys() as $aggr_key ) {
	$aggr_document['live_edits'][ $aggr_key ] = Settings_Schema::EDIT_NOTES !== $aggr_key;
}

$aggr_settings->save( $aggr_document );

echo wp_json_encode(
	array(
		'campaign'  => $aggr_campaign,
		'launch'    => $aggr_launch,
		'premium'   => $aggr_premium,
		'liveEdits' => $aggr_saved,
	)
);
