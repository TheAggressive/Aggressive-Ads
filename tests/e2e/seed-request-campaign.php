<?php
/**
 * A live campaign whose page can ask staff to pause or cancel it.
 *
 * Rebuilt on every run. The browser test opens the dialog from More actions,
 * and a campaign left over from a send would be showing the waiting card
 * instead.
 *
 * It carries one ad on purpose. The ad cards are what print the overlays,
 * and they are what used to replace the list after this dialog had been
 * queued — the link stayed, and the hash opened nothing.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
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

$aggr_placement = get_page_by_path( 'e2e-request-leaderboard', OBJECT, Post_Types::PLACEMENT );

if ( ! $aggr_placement instanceof WP_Post ) {
	$aggr_placement_id = (int) wp_insert_post(
		array(
			'post_type'   => Post_Types::PLACEMENT,
			'post_status' => 'publish',
			'post_name'   => 'e2e-request-leaderboard',
			'post_title'  => 'E2E request leaderboard',
		)
	);
} else {
	$aggr_placement_id = $aggr_placement->ID;
}

update_post_meta( $aggr_placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
update_post_meta( $aggr_placement_id, Placement_Repository::META_SIZE, '728x90' );

$aggr_package = get_page_by_path( 'e2e-request-launch', OBJECT, Post_Types::PACKAGE );

if ( ! $aggr_package instanceof WP_Post ) {
	$aggr_package_id = (int) wp_insert_post(
		array(
			'post_type'   => Post_Types::PACKAGE,
			'post_status' => 'publish',
			'post_name'   => 'e2e-request-launch',
			'post_title'  => 'E2E request launch',
		)
	);
} else {
	$aggr_package_id = $aggr_package->ID;
}

delete_post_meta( $aggr_package_id, Package_Repository::META_PLACEMENT_ID );
add_post_meta( $aggr_package_id, Package_Repository::META_PLACEMENT_ID, $aggr_placement_id );
update_post_meta( $aggr_package_id, Package_Repository::META_DURATION_DAYS, 30 );
update_post_meta( $aggr_package_id, Package_Repository::META_PRICE_CENTS, 45000 );
update_post_meta( $aggr_package_id, Package_Repository::META_CURRENCY, 'USD' );
update_post_meta( $aggr_package_id, Package_Repository::META_IS_ACTIVE, 1 );

$aggr_previous = get_page_by_path( 'e2e-request-flight', OBJECT, Post_Types::CAMPAIGN );

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
		'post_name'   => 'e2e-request-flight',
		'post_title'  => 'E2E request flight',
		'post_author' => $aggr_advertiser->ID,
	)
);

update_post_meta( $aggr_campaign, Campaign_Repository::META_ORG_ID, $aggr_org );
update_post_meta( $aggr_campaign, Campaign_Repository::META_PACKAGE_ID, $aggr_package_id );
update_post_meta( $aggr_campaign, Campaign_Repository::META_BUDGET_CENTS, 45000 );
update_post_meta( $aggr_campaign, Campaign_Repository::META_CURRENCY, 'USD' );
update_post_meta( $aggr_campaign, Campaign_Repository::META_START_TS, $aggr_start );
update_post_meta( $aggr_campaign, Campaign_Repository::META_END_TS, Campaign_Rules::fixed_end_ts( $aggr_start, 30, wp_timezone()->getName() ) );
update_post_meta( $aggr_campaign, Campaign_Repository::META_DEFAULT_CLICK_URL, 'https://example.com/e2e-request' );
add_post_meta( $aggr_campaign, Campaign_Repository::META_PLACEMENT_ID, $aggr_placement_id );

$aggr_creative = Plugin::instance()->container()->get( Creative_Repository::class )->create(
	$aggr_campaign,
	$aggr_org,
	$aggr_placement_id,
	array(
		'kind'      => 'image',
		'click_url' => 'https://example.com/e2e-request',
		'alt_text'  => 'E2E request ad',
		'size'      => '728x90',
	)
);

update_post_meta( $aggr_creative, Creative_Repository::META_WIDTH, 728 );
update_post_meta( $aggr_creative, Creative_Repository::META_HEIGHT, 90 );

echo wp_json_encode( array( 'campaign' => $aggr_campaign ) );
