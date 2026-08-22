<?php
/**
 * Keeping delivered advertising out of the Media Library.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Media_Library;
use Aggressive\Ads\Repository\Creative_Repository;
use WP_Query;
use WP_UnitTestCase;

/**
 * The library lists the site's media, not its advertising.
 */
final class MediaLibraryTest extends WP_UnitTestCase {

	/**
	 * An ordinary attachment, unrelated to advertising.
	 */
	private function site_attachment(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'Site photo',
			)
		);
	}

	/**
	 * An attachment a creative was promoted into.
	 */
	private function creative_attachment(): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'Leaderboard creative',
			)
		);

		update_post_meta( $id, Creative_Repository::META_IS_CREATIVE, 4242 );

		return $id;
	}

	/**
	 * Runs an attachment query through the modal filter.
	 *
	 * @param array<string, mixed> $args Extra query arguments.
	 * @return array<int, int>
	 */
	private function modal_results( array $args = array() ): array {
		$library = new Media_Library();

		$query = new WP_Query(
			$library->filter_modal_query(
				array_merge(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'fields'         => 'ids',
						'posts_per_page' => 50,
					),
					$args
				)
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * The site's own media is listed; creative is not.
	 *
	 * @return void
	 */
	public function test_creative_is_hidden_and_site_media_is_not(): void {
		$site     = $this->site_attachment();
		$creative = $this->creative_attachment();

		$results = $this->modal_results();

		$this->assertContains( $site, $results, 'The library must still list ordinary media.' );
		$this->assertNotContains( $creative, $results, 'A promoted creative must not appear in the library.' );
	}

	/**
	 * Another plugin's meta_query survives alongside the exclusion.
	 *
	 * Dropping someone else's clause would be a filter that quietly changes
	 * what a different screen shows.
	 *
	 * @return void
	 */
	public function test_an_existing_meta_query_is_preserved(): void {
		$wanted = $this->site_attachment();
		update_post_meta( $wanted, '_other_plugin_flag', '1' );

		$unwanted = $this->site_attachment();
		$creative = $this->creative_attachment();
		update_post_meta( $creative, '_other_plugin_flag', '1' );

		$results = $this->modal_results(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Asserting that a caller's own meta_query survives the filter.
					array(
						'key'     => '_other_plugin_flag',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$this->assertContains( $wanted, $results, "The caller's own condition must still apply." );
		$this->assertNotContains( $unwanted, $results, "The caller's own condition must still exclude." );
		$this->assertNotContains( $creative, $results, 'Creative stays hidden even when another clause is present.' );
	}

	/**
	 * The marker the promoter writes is the one the filter looks for.
	 *
	 * Asserted directly because the two live in different classes, and a rename
	 * on one side would otherwise show up as a library that silently lists
	 * every creative again.
	 *
	 * @return void
	 */
	public function test_the_marker_key_is_shared(): void {
		$creative = $this->creative_attachment();

		$this->assertSame(
			'4242',
			(string) get_post_meta( $creative, Creative_Repository::META_IS_CREATIVE, true )
		);
	}
}
