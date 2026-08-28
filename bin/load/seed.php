<?php
/**
 * Seed the isolated HTTP load-test catalogue.
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
use Aggressive\Ads\Repository\Placement_Repository;

const AGGR_LOAD_ADS         = 1_000;
const AGGR_LOAD_FIXTURE_KEY = 'aggr_load_fixture';
const AGGR_LOAD_SLUG        = 'aggr-load-leaderboard';

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$environment = wp_get_environment_type();
$host        = wp_parse_url( home_url(), PHP_URL_HOST );
$is_local    = is_string( $host ) && ( 'localhost' === $host || '127.0.0.1' === $host || str_ends_with( $host, '.test' ) );

if ( 'production' === $environment ) {
	WP_CLI::error( 'Refusing to seed a WordPress environment marked production.' );
}

if ( ! $is_local && '1' !== getenv( 'AGGR_LOAD_ALLOW_REMOTE_STAGING' ) ) {
	WP_CLI::error( 'Set AGGR_LOAD_ALLOW_REMOTE_STAGING=1 to seed a non-local staging host.' );
}

/**
 * Insert one fixture post and fail with its exact WordPress error.
 *
 * @param array<string, mixed> $post Post fields.
 */
function aggr_load_insert_post( array $post ): int {
	$post_id = wp_insert_post( $post, true );

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $post_id->get_error_message() );
	}

	return (int) $post_id;
}

$existing = get_option( AGGR_LOAD_FIXTURE_KEY, array() );

if ( is_array( $existing ) && AGGR_LOAD_ADS === (int) ( $existing['ads'] ?? 0 ) ) {
	$placement_id = (int) ( $existing['placement_id'] ?? 0 );

	if ( $placement_id > 0 && Post_Types::PLACEMENT === get_post_type( $placement_id ) ) {
		WP_CLI::success( sprintf( 'The %s-ad load fixture already exists.', number_format_i18n( AGGR_LOAD_ADS ) ) );
		return;
	}
}

$attachment_id = aggr_load_insert_post(
	array(
		'post_title'     => 'Load-test creative',
		'post_name'      => 'aggr-load-creative',
		'post_status'    => 'inherit',
		'post_type'      => 'attachment',
		'post_mime_type' => 'image/png',
		'guid'           => content_url( 'uploads/aggr-load-creative.png' ),
	)
);

update_post_meta( $attachment_id, '_wp_attached_file', 'aggr-load-creative.png' );
update_post_meta(
	$attachment_id,
	'_wp_attachment_metadata',
	array(
		'width'  => 728,
		'height' => 90,
		'file'   => 'aggr-load-creative.png',
		'sizes'  => array(),
	)
);

$placement_id = aggr_load_insert_post(
	array(
		'post_title'  => 'Load-test leaderboard',
		'post_name'   => AGGR_LOAD_SLUG,
		'post_status' => 'publish',
		'post_type'   => Post_Types::PLACEMENT,
	)
);

update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );
$assignments->install_table();
update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

wp_defer_term_counting( true );
wp_defer_comment_counting( true );
wp_suspend_cache_invalidation( true );

$first_campaign_id = 0;
$first_creative_id = 0;

try {
	for ( $index = 1; $index <= AGGR_LOAD_ADS; ++$index ) {
		$campaign_id = aggr_load_insert_post(
			array(
				'post_title'  => sprintf( 'Load campaign %04d', $index ),
				'post_name'   => sprintf( 'aggr-load-campaign-%04d', $index ),
				'post_status' => Post_Statuses::LIVE,
				'post_type'   => Post_Types::CAMPAIGN,
			)
		);
		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );

		$creative_id = aggr_load_insert_post(
			array(
				'post_title'  => sprintf( 'Load creative %04d', $index ),
				'post_name'   => sprintf( 'aggr-load-creative-%04d', $index ),
				'post_status' => 'publish',
				'post_type'   => Post_Types::CREATIVE,
			)
		);

		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/load/' . $index );
		update_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, 'Load-test advertisement' );
		update_post_meta( $creative_id, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative_id, Creative_Repository::META_HEIGHT, 90 );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Load-test fixture for this plugin's own table.
		$wpdb->insert(
			$assignments->table_name(),
			array(
				'line_item_id'  => $index,
				'campaign_id'   => $campaign_id,
				'placement_id'  => $placement_id,
				'revision_id'   => $creative_id,
				'status'        => Assignment_Rules::LIVE,
				'weight'        => 100,
				'click_url'     => 'https://example.com/load/' . $index,
				'attachment_id' => $attachment_id,
				'alt_text'      => 'Load-test advertisement',
				'width'         => 728,
				'height'        => 90,
				'revision'      => 1,
			)
		);

		$first_campaign_id = 0 === $first_campaign_id ? $campaign_id : $first_campaign_id;
		$first_creative_id = 0 === $first_creative_id ? $creative_id : $first_creative_id;
	}
} finally {
	wp_suspend_cache_invalidation( false );
	wp_defer_comment_counting( false );
	wp_defer_term_counting( false );
}

update_option(
	AGGR_LOAD_FIXTURE_KEY,
	array(
		'ads'           => AGGR_LOAD_ADS,
		'placement_id'  => $placement_id,
		'campaign_id'   => $first_campaign_id,
		'creative_id'   => $first_creative_id,
		'attachment_id' => $attachment_id,
		'slug'          => AGGR_LOAD_SLUG,
		'seeded_at_gmt' => gmdate( 'c' ),
	),
	false
);

wp_cache_flush();

WP_CLI::success( sprintf( 'Seeded %s live campaigns and creatives on placement %d.', number_format_i18n( AGGR_LOAD_ADS ), $placement_id ) );
