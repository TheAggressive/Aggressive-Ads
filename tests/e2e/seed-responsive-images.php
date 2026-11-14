<?php
/**
 * Dedicated responsive placement and empty draft; actual artwork arrives through the browser form.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;

$aggr_user = get_user_by( 'email', 'advertiser@example.test' );
if ( ! $aggr_user instanceof WP_User ) {
	throw new RuntimeException( 'Responsive image browser owner is missing.' );
}
$aggr_orgs = ( new Org_Repository() )->org_ids_for_user( $aggr_user->ID );
if ( array() === $aggr_orgs ) {
	throw new RuntimeException( 'Responsive image browser organization is missing.' );
}
$aggr_placement = wp_insert_post(
	array(
		'post_type'   => Post_Types::PLACEMENT,
		'post_status' => 'publish',
		'post_title'  => 'Responsive browser banner',
		'post_name'   => 'responsive-browser-' . wp_generate_uuid4(),
	)
);
update_post_meta( $aggr_placement, Placement_Repository::META_IS_ACTIVE, 1 );
update_post_meta( $aggr_placement, Placement_Repository::META_SIZE, '728x90' );
if ( ! ( new Placement_Repository() )->set_size_map(
	$aggr_placement,
	array(
		0   => '320x50',
		768 => '728x90',
	)
) ) {
	throw new RuntimeException( 'Responsive browser placement map could not be saved.' );
}
$aggr_package = wp_insert_post(
	array(
		'post_type'   => Post_Types::PACKAGE,
		'post_status' => 'publish',
		'post_title'  => 'Responsive browser package',
	)
);
foreach ( array(
	Package_Repository::META_IS_ACTIVE     => 1,
	Package_Repository::META_PLACEMENT_ID  => $aggr_placement,
	Package_Repository::META_DURATION_DAYS => 14,
	Package_Repository::META_PRICE_CENTS   => 15000,
	Package_Repository::META_CURRENCY      => 'USD',
) as $aggr_key => $aggr_value ) {
	update_post_meta( $aggr_package, $aggr_key, $aggr_value );
}
$aggr_campaign = wp_insert_post(
	array(
		'post_type'   => Post_Types::CAMPAIGN,
		'post_status' => Post_Statuses::DRAFT,
		'post_author' => $aggr_user->ID,
		'post_title'  => 'Responsive browser campaign',
	)
);
foreach ( array(
	Campaign_Repository::META_ORG_ID       => $aggr_orgs[0],
	Campaign_Repository::META_PACKAGE_ID   => $aggr_package,
	Campaign_Repository::META_PLACEMENT_ID => $aggr_placement,
	Campaign_Repository::META_START_TS     => time() - MINUTE_IN_SECONDS,
	Campaign_Repository::META_END_TS       => time() + 14 * DAY_IN_SECONDS,
	Campaign_Repository::META_WIZARD_STEP  => 'creative',
	Campaign_Repository::META_BUDGET_CENTS => 15000,
	Campaign_Repository::META_CURRENCY     => 'USD',
) as $aggr_key => $aggr_value ) {
	update_post_meta( $aggr_campaign, $aggr_key, $aggr_value );
}
$aggr_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Responsive image delivery',
		'post_content' => '<!-- wp:aggr/ad-slot ' . wp_json_encode( array( 'slot' => get_post_field( 'post_name', $aggr_placement ) ) ) . ' /-->',
	)
);
echo wp_json_encode(
	array(
		'campaign'  => $aggr_campaign,
		'placement' => $aggr_placement,
		'page'      => get_permalink( $aggr_page ),
	)
);
