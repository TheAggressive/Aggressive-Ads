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
	$placement_id = $existing->ID;
} else {
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
}

update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );
update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
update_post_meta( $placement_id, Placement_Repository::META_SORT_ORDER, 999 );

$sizing_page = get_page_by_path( 'e2e-ad-sizing', OBJECT, 'page' );

if ( ! $sizing_page instanceof WP_Post ) {
	$sizing_page = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'e2e-ad-sizing',
			'post_title'   => 'E2E ad sizing',
			'post_content' => '<!-- wp:aggr/ad-slot {"slot":"e2e-browser-placement","align":"wide","style":{"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}},"color":{"background":"#999999"}}} /-->',
		),
		true
	);

	if ( is_wp_error( $sizing_page ) ) {
		WP_CLI::error( $sizing_page->get_error_message() );
	}
}

WP_CLI::log( 'Seeded E2E inventory and sizing fixtures.' );
