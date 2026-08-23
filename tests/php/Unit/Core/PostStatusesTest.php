<?php
/**
 * Campaign status registration tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Core;

use Aggressive\Ads\Core\Post_Statuses;
use PHPUnit\Framework\TestCase;

/**
 * The eleven statuses and the flags that keep them off the front end.
 */
final class PostStatusesTest extends TestCase {

	/**
	 * Every slug fits wp_posts.post_status.
	 *
	 * `aggr_scheduled` is 14 characters. A longer invented slug would
	 * truncate on write and then never match on read.
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
	 * Eleven statuses, all aggr_-prefixed, none duplicated.
	 *
	 * @return void
	 */
	public function test_declares_eleven_distinct_statuses(): void {
		$all = Post_Statuses::all();

		$this->assertCount( 11, $all );
		$this->assertSame( $all, array_unique( $all ) );

		foreach ( $all as $slug ) {
			$this->assertStringStartsWith( 'aggr_', $slug );
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
	 * The published set is the states native fill treats as occupying a slot.
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
		$this->assertFalse( Post_Statuses::is_valid( 'aggr_' ) );
		$this->assertFalse( Post_Statuses::is_valid( '' ) );
		$this->assertFalse( Post_Statuses::is_valid( 'aggr_approved_by_me' ) );
	}

	/**
	 * An advertiser edits a draft, or one with changes requested.
	 *
	 * @return void
	 */
	public function test_the_advertiser_window_is_draft_and_changes(): void {
		$this->assertSame(
			array( Post_Statuses::DRAFT, Post_Statuses::CHANGES ),
			Post_Statuses::advertiser_editable()
		);
	}

	/**
	 * Staff edit in every status, acting on the client's behalf.
	 *
	 * Asserted against `all()` rather than a written-out list, so a twelfth
	 * status is included by definition instead of being forgotten here.
	 *
	 * @return void
	 */
	public function test_the_staff_window_is_every_status(): void {
		$this->assertSame( Post_Statuses::all(), Post_Statuses::staff_editable() );
	}

	/**
	 * The resolver returns one window or the other, and nothing else.
	 *
	 * @return void
	 */
	public function test_editable_for_selects_by_role(): void {
		$this->assertSame(
			Post_Statuses::staff_editable(),
			Post_Statuses::editable_for( true )
		);
		$this->assertSame(
			Post_Statuses::advertiser_editable(),
			Post_Statuses::editable_for( false )
		);
	}

	/**
	 * The advertiser window is a subset of the staff one.
	 *
	 * Staff must never be refused something an advertiser is allowed, which a
	 * written-out list could silently break.
	 *
	 * @return void
	 */
	public function test_the_advertiser_window_is_a_subset_of_the_staff_window(): void {
		$this->assertSame(
			array(),
			array_diff( Post_Statuses::advertiser_editable(), Post_Statuses::staff_editable() )
		);
	}
}
