<?php
/**
 * The refresh policy, and the behaviour its default must not change.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Refresh_Policy;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Placement_Repository;
use WP_UnitTestCase;

/**
 * The dangerous half of this phase is not the new policy, it is the default.
 *
 * Refresh is off for a placement nobody has configured, which is right for
 * anything created from now on and catastrophic if applied to what already
 * exists: rotation ships and works, so an upgraded site would find its ads had
 * quietly stopped changing. Nothing errors. Nothing logs. The symptom is
 * "the ads look stuck", which nobody attributes to a database migration.
 *
 * So these assert the seam between the two, and the negatives are the point.
 */
final class RefreshPolicyMigrationTest extends WP_UnitTestCase {

	/**
	 * The repository under test.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	/**
	 * Resolves the repository per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->placements = Plugin::instance()->container()->get( Placement_Repository::class );
	}

	/**
	 * **An existing placement keeps exactly what the client already permitted.**
	 *
	 * @return void
	 */
	public function test_the_backfill_preserves_what_a_placement_could_already_do(): void {
		$placement_id = $this->placement( 'legacy-leaderboard' );

		// Before: no policy recorded at all, so the strict default applies.
		$this->assertFalse( $this->placements->refresh_policy( $placement_id )->enabled );

		$granted = $this->placements->backfill_refresh_policies(
			1,
			Refresh_Policy::LEGACY_CLIENT_MAX_PER_VIEW
		);

		$this->assertSame( 1, $granted, 'The backfill did not report the work it did.' );

		$policy = $this->placements->refresh_policy( $placement_id );

		$this->assertTrue( $policy->enabled, 'An existing placement stopped being allowed to rotate.' );
		$this->assertSame( 1, $policy->interval_seconds );
		$this->assertSame( Refresh_Policy::LEGACY_CLIENT_MAX_PER_VIEW, $policy->max_per_view );

		// And the block's request is now honoured rather than raised, because
		// the placement permits the floor the client already used.
		$this->assertSame( 10, $policy->interval_for( 10 ) );
	}

	/**
	 * A publisher who already tightened their policy is not overwritten.
	 *
	 * A migration that ran twice, or ran after somebody made a decision, must
	 * not hand their inventory back to the old permissive numbers.
	 *
	 * @return void
	 */
	public function test_the_backfill_does_not_overwrite_a_decision_somebody_made(): void {
		$placement_id = $this->placement( 'tightened-leaderboard' );

		$this->assertTrue( $this->placements->set_refresh_policy( $placement_id, false, 60, 2 ) );

		$this->assertSame( 0, $this->placements->backfill_refresh_policies( 1, 100 ), 'It claimed work it must not do.' );

		$policy = $this->placements->refresh_policy( $placement_id );

		$this->assertFalse( $policy->enabled, 'A publisher decision was overwritten by the migration.' );
		$this->assertSame( 60, $policy->interval_seconds );
		$this->assertSame( 2, $policy->max_per_view );
	}

	/**
	 * Running it twice writes the same answer, because a migration is retried.
	 *
	 * @return void
	 */
	public function test_the_backfill_is_idempotent(): void {
		$this->placement( 'first-leaderboard' );
		$this->placement( 'second-leaderboard' );

		$this->assertSame( 2, $this->placements->backfill_refresh_policies( 1, 100 ) );
		$this->assertSame( 0, $this->placements->backfill_refresh_policies( 1, 100 ) );
	}

	/**
	 * A placement created after the migration gets the strict default.
	 *
	 * This is the half the default exists for, and it only means anything
	 * beside the assertion above: if the backfill also caught new placements,
	 * the policy would be permissive for ever and nobody would notice.
	 *
	 * @return void
	 */
	public function test_a_placement_created_afterwards_does_not_refresh(): void {
		$this->placements->backfill_refresh_policies( 1, 100 );

		$fresh = $this->placement( 'brand-new-leaderboard' );

		$policy = $this->placements->refresh_policy( $fresh );

		$this->assertFalse( $policy->enabled );
		$this->assertFalse( $policy->permits_sequence( 1 ) );
		$this->assertTrue( $policy->permits_sequence( 0 ), 'Its first fill must still be served.' );
	}

	/**
	 * Saving a policy reads it back rather than trusting the write.
	 *
	 * @return void
	 */
	public function test_a_saved_policy_is_read_back_before_it_is_reported_saved(): void {
		$placement_id = $this->placement( 'read-back-leaderboard' );

		$this->assertTrue( $this->placements->set_refresh_policy( $placement_id, true, 45, 4 ) );

		$policy = $this->placements->refresh_policy( $placement_id );

		$this->assertTrue( $policy->enabled );
		$this->assertSame( 45, $policy->interval_seconds );
		$this->assertSame( 4, $policy->max_per_view );

		// A placement that does not exist cannot be given a policy.
		$this->assertFalse( $this->placements->set_refresh_policy( 999999, true, 30, 6 ) );
	}

	/**
	 * **A write that did not land is not reported as saved.**
	 *
	 * `update_post_meta()` answers false both for a failed write and for a value
	 * that was already what you asked for, so the only honest answer comes from
	 * reading the policy back. Collapsing that verification to `true` changed no
	 * test until this one existed — the same shape as the replacement
	 * activation recorded in `open-work.md`, where every existing test took the
	 * path where the writes succeed.
	 *
	 * The consequence is worth stating: a publisher tightens a placement's
	 * policy, the screen says saved, and the inventory keeps refreshing on the
	 * old numbers.
	 *
	 * @return void
	 */
	public function test_a_policy_that_did_not_persist_is_not_reported_saved(): void {
		$placement_id = $this->placement( 'swallowed-leaderboard' );

		$swallow = static function ( $check, $object_id, $meta_key ) use ( $placement_id ) {
			if ( $object_id === $placement_id && Placement_Repository::META_REFRESH_SECONDS === $meta_key ) {
				// Claim the write was handled, and write nothing.
				return true;
			}

			return $check;
		};

		add_filter( 'update_post_metadata', $swallow, 10, 3 );
		$saved = $this->placements->set_refresh_policy( $placement_id, true, 45, 4 );
		remove_filter( 'update_post_metadata', $swallow, 10 );

		$this->assertFalse( $saved, 'A policy that never reached the database was reported saved.' );

		// And the control: the same call without the swallow does land.
		$this->assertTrue( $this->placements->set_refresh_policy( $placement_id, true, 45, 4 ) );
	}

	/**
	 * An interval below the floor is raised on the way in.
	 *
	 * @return void
	 */
	public function test_a_policy_cannot_be_saved_below_the_floor(): void {
		$placement_id = $this->placement( 'floored-leaderboard' );

		$this->assertTrue( $this->placements->set_refresh_policy( $placement_id, true, 0, -3 ) );

		$policy = $this->placements->refresh_policy( $placement_id );

		$this->assertSame( Refresh_Policy::MIN_INTERVAL_SECONDS, $policy->interval_seconds );
		$this->assertSame( 0, $policy->max_per_view );
	}

	/**
	 * Creates a placement.
	 *
	 * @param string $slug Placement post_name.
	 * @return int Placement post id.
	 */
	private function placement( string $slug ): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => $slug,
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		return $placement_id;
	}
}
