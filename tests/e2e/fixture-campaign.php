<?php
/**
 * Deleting a fixture campaign with what it owns.
 *
 * Required by the seeds that rebuild their campaign every run. Deleting the
 * campaign post takes its line items (Line_Item_Lifecycle) and nothing else,
 * and each seed deleted only the post — or its *active* creatives — so every
 * run stranded the rest: a Studio site that had run the suite a few dozen
 * times held 476 creatives whose campaign no longer existed, with their
 * private files still on disk.
 *
 * Media Library attachments stay. The live ad's is shared by the coordination
 * fixture, and an attachment is not something a creative owns alone.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Storage\Private_Storage;

if ( ! function_exists( 'aggr_e2e_delete_campaign' ) ) {
	/**
	 * Deletes the campaign with this slug, every creative it has ever had,
	 * and their private files.
	 *
	 * @param string $slug Campaign post name.
	 * @return void
	 */
	function aggr_e2e_delete_campaign( string $slug ): void {
		$campaign = get_page_by_path( $slug, OBJECT, Post_Types::CAMPAIGN );

		if ( ! $campaign instanceof WP_Post ) {
			return;
		}

		$storage = new Private_Storage();

		/*
		 * Every creative, replaced revisions included, not only the active
		 * ones — and those tied to the campaign as its child post rather than
		 * by meta, which is how seed-page-coordination.php links its own.
		 */
		$creative_ids = array_unique(
			array_merge(
				( new Creative_Repository() )->ids_for_campaign( $campaign->ID ),
				array_map(
					'intval',
					get_children(
						array(
							'post_parent' => $campaign->ID,
							'post_type'   => Post_Types::CREATIVE,
							'post_status' => 'any',
							'fields'      => 'ids',
						)
					)
				)
			)
		);

		foreach ( $creative_ids as $creative_id ) {
			$path = (string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, true );

			if ( '' !== $path ) {
				$storage->delete( $path );
			}

			wp_delete_post( $creative_id, true );
		}

		// Its assignment rows, which nothing else removes.
		Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->delete_for_campaign( $campaign->ID );

		wp_delete_post( $campaign->ID, true );
	}
}
