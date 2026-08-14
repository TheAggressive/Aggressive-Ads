<?php
/**
 * Removes browser-test campaigns and their private files.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Storage\Private_Storage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/*
 * Clear the sign-in rate limiter.
 *
 * The browser suite signs in several times per run, and the limiter counts per
 * client — so a few consecutive runs from one machine trip it and every spec
 * then fails on a "too many attempts" message that has nothing to do with what
 * it was testing. Resetting the counter is test isolation; raising the limit to
 * accommodate the tests would be weakening the control the tests exist to
 * protect.
 */
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Development fixture: transients have no lookup API by prefix, and this file never ships.
$aggr_limiter_keys = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_aggr_rl_%'"
);

foreach ( (array) $aggr_limiter_keys as $aggr_option ) {
	delete_transient( str_replace( '_transient_', '', (string) $aggr_option ) );
}

$campaign_ids = get_posts(
	array(
		'post_type'      => Post_Types::CAMPAIGN,
		'post_status'    => Post_Statuses::all(),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$creatives = Plugin::instance()->container()->get( Creative_Repository::class );
$orgs       = Plugin::instance()->container()->get( Org_Repository::class );
$storage   = Plugin::instance()->container()->get( Private_Storage::class );
$removed   = 0;

foreach ( $campaign_ids as $campaign_id ) {
	$campaign_id = (int) $campaign_id;

	if ( ! str_starts_with( get_the_title( $campaign_id ), 'E2E browser campaign ' ) ) {
		continue;
	}

	foreach ( $creatives->for_campaign( $campaign_id ) as $creative ) {
		$stored = $creatives->storage_details( $creative['id'] );

		if ( null !== $stored && '' !== $stored['path'] ) {
			$storage->delete( $stored['path'] );
		}

		wp_delete_post( $creative['id'], true );
	}

	wp_delete_post( $campaign_id, true );
	++$removed;
}

$mapping_placement = get_page_by_path( 'e2e-browser-placement', OBJECT, Post_Types::PLACEMENT );

if ( $mapping_placement instanceof WP_Post ) {
	wp_delete_post( $mapping_placement->ID, true );
}

$custom_placement = get_page_by_path( 'e2e-custom-slot', OBJECT, Post_Types::PLACEMENT );

if ( $custom_placement instanceof WP_Post ) {
	wp_delete_post( $custom_placement->ID, true );
}

$sizing_page = get_page_by_path( 'e2e-ad-sizing', OBJECT, 'page' );

if ( $sizing_page instanceof WP_Post ) {
	wp_delete_post( $sizing_page->ID, true );
}

$signup_user = get_user_by( 'email', 'e2e-signup@example.test' );

if ( $signup_user instanceof WP_User ) {
	$signup_orgs = get_posts(
		array(
			'post_type'      => Post_Types::ORGANIZATION,
			'post_status'    => 'any',
			'posts_per_page' => 10,
			'fields'         => 'ids',
			'meta_key'       => Aggressive\Ads\Repository\Org_Repository::META_OWNER_USER,
			'meta_value'     => $signup_user->ID,
		)
	);

	foreach ( $signup_orgs as $signup_org ) {
		$orgs->delete_registration_org( (int) $signup_org );
	}

	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	wp_delete_user( $signup_user->ID );
}

$aggr_signup_requester = get_user_by( 'email', 'e2e-org-requester@example.test' );

if ( $aggr_signup_requester instanceof WP_User ) {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	wp_delete_user( $aggr_signup_requester->ID );
}

$aggr_existing_invitee = get_user_by( 'email', 'e2e-existing-invitee@example.test' );

if ( $aggr_existing_invitee instanceof WP_User ) {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	wp_delete_user( $aggr_existing_invitee->ID );
}

WP_CLI::log( sprintf( 'E2E reset removed %d campaign(s).', $removed ) );
