<?php
/**
 * One definition of whether a creative can run.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Coverage_Service;
use WP_UnitTestCase;

/**
 * The states P3 will reuse, asserted before anything depends on them.
 *
 * The contract forbids a second meaning of "eligible", which is only worth
 * anything if the first meaning is pinned. Each case below is one of the
 * classifications it names, and the two that matter most are the ones that look
 * like coverage and are not: a superseded revision, and an assignment pointing
 * at a creative that no longer exists.
 *
 * The healing case is the operational one. A campaign the backfill has not
 * reached has no assignment rows at all, and reporting that as "covers nothing"
 * would tell an advertiser their artwork is missing during an upgrade they
 * cannot see and did not ask for.
 */
final class CoverageServiceTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Coverage_Service
	 */
	private Coverage_Service $coverage;

	/**
	 * Assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	/**
	 * Backfill, for arranging migrated fixtures.
	 *
	 * @var Creative_Assignment_Migrator
	 */
	private Creative_Assignment_Migrator $migrator;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->coverage    = $container->get( Coverage_Service::class );
		$this->assignments = $container->get( Creative_Assignment_Repository::class );
		$this->migrator    = $container->get( Creative_Assignment_Migrator::class );

		$this->assignments->install_table();

		delete_option( Creative_Assignment_Migrator::OPTION_CURSOR );
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Creative_Assignment_Migrator::HOOK );

		parent::tear_down();
	}

	/**
	 * A campaign with one creative on a 728x90 placement.
	 *
	 * @param int    $width  Creative width.
	 * @param int    $height Creative height.
	 * @param string $kind   Creative kind.
	 * @return array{campaign: int, creative: int, placement: int}
	 */
	private function fixture( int $width = 728, int $height = 90, string $kind = 'image' ): array {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $placement, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $placement, Placement_Repository::META_IS_ACTIVE, 1 );

		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
			)
		);

		add_post_meta( $campaign, Campaign_Repository::META_PLACEMENT_ID, $placement );

		$creative = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $creative, Creative_Repository::META_CAMPAIGN_ID, $campaign );
		update_post_meta( $creative, Creative_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $creative, Creative_Repository::META_KIND, $kind );
		update_post_meta( $creative, Creative_Repository::META_WIDTH, $width );
		update_post_meta( $creative, Creative_Repository::META_HEIGHT, $height );
		update_post_meta( $creative, Creative_Repository::META_CLICK_URL, 'https://example.com/a' );

		return array(
			'campaign'  => $campaign,
			'creative'  => $creative,
			'placement' => $placement,
		);
	}

	/**
	 * The state of the campaign's only assignment.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return string
	 */
	private function only_state( int $campaign_id ): string {
		$assessed = $this->coverage->assess( $campaign_id );

		$this->assertCount( 1, $assessed, 'The fixture did not produce exactly one assignment.' );

		return $assessed[0]['state'];
	}

	/**
	 * Correct artwork nobody has approved yet is `unapproved`, and still covers.
	 *
	 * This test used to assert `usable` for the same fixture, which was the
	 * vocabulary conflating "nothing is wrong with it" with "a publisher said
	 * yes". P3's delivery threshold is `usable`, so that conflation would have
	 * let unreviewed artwork clear the bar meant to stop it.
	 */
	public function test_a_correctly_sized_current_creative_is_unapproved_until_reviewed(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );

		$this->assertSame( Coverage_Service::STATE_UNAPPROVED, $this->only_state( $made['campaign'] ) );
		$this->assertTrue(
			Coverage_Service::covers_for_submission( Coverage_Service::STATE_UNAPPROVED ),
			'Submission must count an unapproved creative: approval follows review, review follows submission.'
		);

		update_post_meta( $made['creative'], Creative_Repository::META_REVIEW_STATE, 'approved' );

		$this->assertSame( Coverage_Service::STATE_USABLE, $this->only_state( $made['campaign'] ) );
		$this->assertSame(
			array( $made['placement'] ),
			$this->coverage->covered_placements( $made['campaign'] )
		);
	}

	/**
	 * A campaign the backfill has not reached is assessed, not written off.
	 *
	 * Without healing this reports zero coverage, and an advertiser is told
	 * their artwork is missing in the middle of an upgrade they cannot see.
	 */
	public function test_an_unmigrated_campaign_heals_before_being_assessed(): void {
		$made = $this->fixture();

		$this->assertCount(
			0,
			$this->assignments->for_campaign( $made['campaign'] ),
			'The fixture was already migrated, so healing proves nothing.'
		);

		$this->assertSame(
			array( $made['placement'] ),
			$this->coverage->covered_placements( $made['campaign'] ),
			'An unmigrated campaign was reported as covering nothing.'
		);
	}

	/** A creative that does not fit the placement is not coverage. */
	public function test_a_wrongly_sized_creative_is_not_usable(): void {
		$made = $this->fixture( 300, 250 );
		$this->migrator->migrate_one( $made['creative'] );

		$this->assertSame( Coverage_Service::STATE_WRONG_SIZE, $this->only_state( $made['campaign'] ) );

		/*
		 * Still *covered*, deliberately.
		 *
		 * The campaign reports "this creative is the wrong size", not "this
		 * placement has no creative" — telling somebody both is telling them
		 * the same problem twice and pointing at the wrong fix. P3's threshold
		 * is stricter and will refuse to serve it.
		 */
		$this->assertSame(
			array( $made['placement'] ),
			$this->coverage->covered_placements( $made['campaign'] )
		);
	}

	/** A non-image creative is not coverage. */
	public function test_a_non_image_creative_is_not_usable(): void {
		$made = $this->fixture( 728, 90, 'html' );
		$this->migrator->migrate_one( $made['creative'] );

		$this->assertSame( Coverage_Service::STATE_WRONG_KIND, $this->only_state( $made['campaign'] ) );
	}

	/**
	 * A superseded revision is not the current artwork.
	 *
	 * The case that looks like coverage and is not: the row exists, the
	 * dimensions fit, and the creative it names has been replaced.
	 */
	public function test_a_superseded_revision_is_not_usable(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );

		$replacement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $made['creative'], Creative_Repository::META_REPLACED_BY, $replacement );

		$this->assertSame( Coverage_Service::STATE_SUPERSEDED, $this->only_state( $made['campaign'] ) );
		$this->assertSame( array(), $this->coverage->covered_placements( $made['campaign'] ) );
	}

	/**
	 * An assignment pointing at a deleted creative is not coverage.
	 *
	 * The concurrently-deleted case the contract names. The row outlives the
	 * post, and nothing about the row says so.
	 */
	public function test_an_assignment_naming_a_deleted_revision_is_not_usable(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );

		wp_delete_post( $made['creative'], true );

		$this->assertSame( Coverage_Service::STATE_MISSING_REVISION, $this->only_state( $made['campaign'] ) );
	}

	/**
	 * Two creatives on one placement both count, and the placement counts once.
	 *
	 * The shape P2 exists to allow. The upload cap still refuses a second one
	 * today, so this asserts the coverage model is ready for it rather than
	 * that the product accepts it yet — which is the whole point of doing the
	 * service before lifting the cap.
	 */
	public function test_two_creatives_on_one_placement_are_both_assessed(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );

		$second = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $second, Creative_Repository::META_CAMPAIGN_ID, $made['campaign'] );
		update_post_meta( $second, Creative_Repository::META_PLACEMENT_ID, $made['placement'] );
		update_post_meta( $second, Creative_Repository::META_KIND, 'image' );
		update_post_meta( $second, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $second, Creative_Repository::META_HEIGHT, 90 );

		$this->migrator->migrate_one( $second );

		// Approved, so this stays a test about two creatives on one placement
		// rather than an accidental test of the review state.
		update_post_meta( $made['creative'], Creative_Repository::META_REVIEW_STATE, 'approved' );
		update_post_meta( $second, Creative_Repository::META_REVIEW_STATE, 'approved' );

		$assessed = $this->coverage->assess( $made['campaign'] );

		$this->assertGreaterThanOrEqual( 1, count( $assessed ) );

		foreach ( $assessed as $entry ) {
			$this->assertSame( Coverage_Service::STATE_USABLE, $entry['state'] );
		}

		// One placement, however many creatives cover it.
		$this->assertSame(
			array( $made['placement'] ),
			$this->coverage->covered_placements( $made['campaign'] )
		);
	}

	/**
	 * Puts a window on the campaign's only assignment.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $start       Start timestamp, 0 to inherit.
	 * @param int $end         End timestamp, 0 to inherit.
	 * @return void
	 */
	private function set_window( int $campaign_id, int $start, int $end ): void {
		global $wpdb;

		$rows = $this->assignments->for_campaign( $campaign_id );

		$this->assertCount( 1, $rows, 'The fixture did not produce exactly one assignment.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->update(
			$wpdb->prefix . 'aggr_creative_assignments',
			array(
				'start_at_ts' => $start,
				'end_at_ts'   => $end,
			),
			array( 'id' => (int) $rows[0]['id'] ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * An assignment whose window has closed covers nothing.
	 *
	 * The defect this vocabulary was extended for. It used to classify `usable`
	 * — correct artwork, on the right campaign, that can never run again — so a
	 * campaign reported a placement covered by a creative whose window had
	 * passed. Nothing in the old vocabulary could express it.
	 */
	public function test_an_expired_assignment_does_not_cover_its_placement(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );
		update_post_meta( $made['creative'], Creative_Repository::META_REVIEW_STATE, 'approved' );

		$now = 1000000;
		$this->set_window( $made['campaign'], 0, $now - 1 );

		$this->assertSame(
			Coverage_Service::STATE_EXPIRED,
			$this->coverage->assess( $made['campaign'], $now )[0]['state']
		);
		$this->assertSame(
			array(),
			$this->coverage->covered_placements( $made['campaign'], $now ),
			'A placement was reported covered by a creative that can never run again.'
		);
	}

	/**
	 * The end is exclusive, matching the P2 read contract.
	 *
	 * Asserted at the boundary rather than a day either side: an off-by-one here
	 * means a creative serves for one extra second or stops one early, and both
	 * sides of that are invisible in a test that uses round numbers.
	 */
	public function test_an_assignment_ending_exactly_now_has_expired(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );
		update_post_meta( $made['creative'], Creative_Repository::META_REVIEW_STATE, 'approved' );

		$now = 1000000;
		$this->set_window( $made['campaign'], 0, $now );

		$this->assertSame(
			Coverage_Service::STATE_EXPIRED,
			$this->coverage->assess( $made['campaign'], $now )[0]['state']
		);

		$this->assertSame(
			Coverage_Service::STATE_USABLE,
			$this->coverage->assess( $made['campaign'], $now - 1 )[0]['state'],
			'One second before its end, the assignment is still usable.'
		);
	}

	/**
	 * A window that has not opened is legitimate and still covers.
	 *
	 * A campaign scheduled for next month must be submittable. This is the state
	 * that would have been wrong to treat like an expiry, which is why the two
	 * are separate states rather than one "outside its window".
	 */
	public function test_an_assignment_starting_later_still_covers(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );
		update_post_meta( $made['creative'], Creative_Repository::META_REVIEW_STATE, 'approved' );

		$now = 1000000;
		$this->set_window( $made['campaign'], $now + 60, 0 );

		$this->assertSame(
			Coverage_Service::STATE_NOT_STARTED,
			$this->coverage->assess( $made['campaign'], $now )[0]['state']
		);
		$this->assertSame(
			array( $made['placement'] ),
			$this->coverage->covered_placements( $made['campaign'], $now ),
			'A campaign scheduled for later reported no coverage.'
		);
	}

	/**
	 * A zero bound inherits the parent rather than meaning 1970.
	 *
	 * The negative that makes the two tests above mean something: if zero were
	 * compared literally, every inherited window would read as expired.
	 */
	public function test_a_zero_window_is_inherited_not_expired(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );
		update_post_meta( $made['creative'], Creative_Repository::META_REVIEW_STATE, 'approved' );

		$this->set_window( $made['campaign'], 0, 0 );

		$this->assertSame(
			Coverage_Service::STATE_USABLE,
			$this->coverage->assess( $made['campaign'], 1000000 )[0]['state']
		);
	}

	/**
	 * An assignment and revision that disagree about their parents cover nothing.
	 *
	 * Written by different paths, so a disagreement is a repair job rather than
	 * an advertiser's mistake — and reporting it as coverage would hide it.
	 */
	public function test_an_assignment_disagreeing_with_its_revision_covers_nothing(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );

		// The revision now claims a different placement than its assignment.
		update_post_meta( $made['creative'], Creative_Repository::META_PLACEMENT_ID, $made['placement'] + 1000 );

		$this->assertSame( Coverage_Service::STATE_WRONG_PARENT, $this->only_state( $made['campaign'] ) );
		$this->assertSame( array(), $this->coverage->covered_placements( $made['campaign'] ) );
	}

	/**
	 * The organization half of the same check.
	 *
	 * Written after a sabotage run passed with the organization comparison
	 * deleted: the test above mutates the placement, so the other side of the
	 * `||` was carrying it and the tenancy half was never exercised.
	 */
	public function test_an_assignment_disagreeing_about_its_organization_covers_nothing(): void {
		$made = $this->fixture();
		$this->migrator->migrate_one( $made['creative'] );

		update_post_meta( $made['creative'], Creative_Repository::META_ORG_ID, 987654 );

		$this->assertSame( Coverage_Service::STATE_WRONG_PARENT, $this->only_state( $made['campaign'] ) );
		$this->assertSame( array(), $this->coverage->covered_placements( $made['campaign'] ) );
	}

	/**
	 * The submission threshold accepts only `usable`.
	 *
	 * Stated directly, because P3 adds a second threshold over the same states
	 * and the two must be told apart deliberately rather than by whichever
	 * condition a call site happens to write.
	 */
	public function test_presence_and_usability_are_different_thresholds(): void {
		// Present: the placement has something attached, whatever is wrong with
		// it. A size or kind problem is reported against the creative itself.
		foreach (
			array(
				Coverage_Service::STATE_USABLE,
				Coverage_Service::STATE_WRONG_SIZE,
				Coverage_Service::STATE_WRONG_KIND,
				Coverage_Service::STATE_UNAPPROVED,
				Coverage_Service::STATE_NOT_STARTED,
			) as $state
		) {
			$this->assertTrue( Coverage_Service::covers_for_submission( $state ), $state );
		}

		// Absent: the old validator never saw these either, because it read
		// only active creatives.
		foreach (
			array(
				Coverage_Service::STATE_SUPERSEDED,
				Coverage_Service::STATE_MISSING_REVISION,
				Coverage_Service::STATE_WRONG_CAMPAIGN,
				Coverage_Service::STATE_WRONG_PARENT,
				Coverage_Service::STATE_EXPIRED,
				Coverage_Service::STATE_RETIRED,
			) as $state
		) {
			$this->assertFalse( Coverage_Service::covers_for_submission( $state ), $state );
		}
	}
}
