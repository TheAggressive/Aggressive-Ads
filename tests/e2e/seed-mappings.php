<?php
/**
 * Deterministic inventory fixture for browser tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Placement_Repository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$existing = get_page_by_path( 'e2e-browser-placement', OBJECT, Post_Types::PLACEMENT );

if ( $existing instanceof WP_Post ) {
	WP_CLI::log( 'E2E inventory fixture already present.' );
	return;
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

WP_CLI::log( 'Seeded E2E inventory fixture.' );
