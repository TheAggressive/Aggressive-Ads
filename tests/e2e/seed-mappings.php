<?php
/**
 * Deterministic placement-mapping fixture for browser tests.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Integration\Adsanity\Adsanity;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( ! Adsanity::is_available() ) {
	WP_CLI::error( 'The AdSanity contract fixture is unavailable in the browser environment.' );
}

$term = wp_insert_term(
	'E2E browser group',
	Adsanity::TAXONOMY,
	array( 'slug' => 'e2e-browser-group' )
);

if ( is_wp_error( $term ) ) {
	WP_CLI::error( $term->get_error_message() );
}

$placement_id = wp_insert_post(
	array(
		'post_type'   => Post_Types::PLACEMENT,
		'post_status' => 'publish',
		'post_name'   => 'e2e-browser-placement',
		'post_title'  => 'E2E browser placement',
	),
	true
);

if ( is_wp_error( $placement_id ) ) {
	WP_CLI::error( $placement_id->get_error_message() );
}

update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );
update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
update_post_meta( $placement_id, Placement_Repository::META_SORT_ORDER, 999 );
delete_post_meta( $placement_id, Placement_Repository::META_ADGROUP_TERM );

WP_CLI::log( 'Seeded E2E placement mapping fixture.' );
