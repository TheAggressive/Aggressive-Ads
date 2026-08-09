<?php
/**
 * Campaign status registration tests.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Core;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The eleven statuses and the flags that keep them off the front end.
 */
final class PostStatusesTest extends TestCase {

	/**
	 * Every slug fits wp_posts.post_status.
	 *
	 * This is the whole reason for the lap_ prefix: laao_ads_changes_requested
	 * is 26 characters, which would truncate on write and then never match on
	 * read, producing campaigns in no status at all.
	 *
	 * @return void
	 */
	public function test_every_slug_fits_the_post_status_column(): void {
		foreach ( Post_Statuses::all() as $slug ) {
			$this->assertLessThanOrEqual(
				Post_Statuses::MAX_SLUG_LENGTH,
				strlen( $slug ),
				sprintf( 'Status "%s" is %d characters; the column holds %d.', $slug, strlen( $slug ), Post_Statuses::MAX_SLUG_LENGTH )
			);
		}
	}

	/**
	 * Eleven statuses, all lap_-prefixed, none duplicated.
	 *
	 * @return void
	 */
	public function test_declares_eleven_distinct_statuses(): void {
		$all = Post_Statuses::all();

		$this->assertCount( 11, $all );
		$this->assertSame( $all, array_unique( $all ) );

		foreach ( $all as $slug ) {
			$this->assertStringStartsWith( 'lap_', $slug );
		}
	}

	/**
	 * A campaign is never a front-end URL, and must not surface in a search.
	 *
	 * @return void
	 */
	public function test_no_status_is_public(): void {
		foreach ( Post_Statuses::registration_args() as $slug => $args ) {
			$this->assertFalse( $args['public'], "{$slug}: public" );
			$this->assertTrue( $args['exclude_from_search'], "{$slug}: exclude_from_search" );
			$this->assertTrue( $args['protected'], "{$slug}: protected" );
		}
	}

	/**
	 * Statuses are protected rather than internal.
	 *
	 * An internal status (auto-draft, inherit) is hidden from admin list
	 * filters, and staff need to filter the review queue by status — so
	 * marking these internal would quietly remove the queue's main control.
	 *
	 * @return void
	 */
	public function test_statuses_are_not_internal(): void {
		foreach ( Post_Statuses::registration_args() as $slug => $args ) {
			$this->assertFalse( $args['internal'], "{$slug}: internal" );
			$this->assertTrue( $args['show_in_admin_status_list'], "{$slug}: show_in_admin_status_list" );
		}
	}

	/**
	 * Registration args exist for every declared status and for nothing else.
	 *
	 * @return void
	 */
	public function test_registration_args_cover_exactly_the_declared_statuses(): void {
		$this->assertSame( Post_Statuses::all(), array_keys( Post_Statuses::registration_args() ) );
	}

	/**
	 * Completed and cancelled are the terminal states, and they are real
	 * statuses rather than strings that drifted.
	 *
	 * @return void
	 */
	public function test_terminal_statuses_are_complete_and_cancelled(): void {
		$this->assertSame(
			array( Post_Statuses::COMPLETE, Post_Statuses::CANCELLED ),
			Post_Statuses::terminal()
		);

		foreach ( Post_Statuses::terminal() as $slug ) {
			$this->assertTrue( Post_Statuses::is_valid( $slug ) );
		}
	}

	/**
	 * An advertiser may edit only in draft and changes-requested. Anything
	 * else is either in the staff queue or already published.
	 *
	 * @return void
	 */
	public function test_advertiser_editable_states_are_draft_and_changes(): void {
		$this->assertSame(
			array( Post_Statuses::DRAFT, Post_Statuses::CHANGES ),
			Post_Statuses::advertiser_editable()
		);
	}

	/**
	 * The published set is exactly the states with AdSanity objects behind
	 * them — the ones a cancellation has to unpublish.
	 *
	 * @return void
	 */
	public function test_published_states_are_the_ones_with_provider_objects(): void {
		$this->assertSame(
			array( Post_Statuses::SCHEDULED, Post_Statuses::LIVE, Post_Statuses::PAUSED ),
			Post_Statuses::published()
		);

		$this->assertNotContains( Post_Statuses::APPROVED, Post_Statuses::published() );
	}

	/**
	 * Every subset names real statuses. A typo in one of these lists would
	 * otherwise silently exclude a state from a guard.
	 *
	 * @return void
	 */
	public function test_every_subset_contains_only_declared_statuses(): void {
		$subsets = array(
			'terminal'            => Post_Statuses::terminal(),
			'advertiser_editable' => Post_Statuses::advertiser_editable(),
			'published'           => Post_Statuses::published(),
		);

		foreach ( $subsets as $name => $subset ) {
			foreach ( $subset as $slug ) {
				$this->assertContains( $slug, Post_Statuses::all(), "{$name} references unknown status {$slug}" );
			}
		}
	}

	/**
	 * A status from another plugin, or a client-supplied string, is not ours.
	 *
	 * @return void
	 */
	public function test_foreign_statuses_are_rejected(): void {
		$this->assertFalse( Post_Statuses::is_valid( 'publish' ) );
		$this->assertFalse( Post_Statuses::is_valid( 'draft' ) );
		$this->assertFalse( Post_Statuses::is_valid( 'lap_' ) );
		$this->assertFalse( Post_Statuses::is_valid( '' ) );
		$this->assertFalse( Post_Statuses::is_valid( 'lap_approved_by_me' ) );
	}
}
