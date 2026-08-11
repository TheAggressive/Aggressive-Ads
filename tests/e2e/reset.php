<?php
/**
 * Removes browser-test campaigns and their private files.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Integration\Adsanity\Adsanity;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Storage\Private_Storage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
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

if ( taxonomy_exists( Adsanity::TAXONOMY ) ) {
	$mapping_term = get_term_by( 'slug', 'e2e-browser-group', Adsanity::TAXONOMY );

	if ( $mapping_term instanceof WP_Term ) {
		wp_delete_term( $mapping_term->term_id, Adsanity::TAXONOMY );
	}
}

WP_CLI::log( sprintf( 'E2E reset removed %d campaign(s).', $removed ) );
