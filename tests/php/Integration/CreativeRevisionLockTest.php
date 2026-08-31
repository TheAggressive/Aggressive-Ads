<?php
/**
 * Creative replacement advisory locking.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use wpdb;
use WP_UnitTestCase;

/**
 * Replacement writes serialize across independent database connections.
 */
final class CreativeRevisionLockTest extends WP_UnitTestCase {

	/**
	 * A lock held by another connection refuses the repository claim.
	 *
	 * @return void
	 */
	public function test_change_lock_is_atomic_across_requests(): void {
		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'private',
			)
		);
		$lock_name   = 'aggr_creative_change_' . get_current_blog_id() . '_' . $creative_id;
		$other       = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		$this->assertSame( 1, (int) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) );

		try {
			$this->assertSame( '', $this->repository()->claim_change_lock( $creative_id ) );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			$other->close();
		}
	}

	/**
	 * The owner can release the lock and a later request can claim it.
	 *
	 * @return void
	 */
	public function test_change_lock_releases_only_with_its_exact_token(): void {
		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'private',
			)
		);
		$repository  = $this->repository();
		$lock        = $repository->claim_change_lock( $creative_id );

		$this->assertNotSame( '', $lock );
		$this->assertSame( '', $repository->claim_change_lock( $creative_id ) );

		$repository->release_change_lock( $creative_id, 'not-the-owner' );
		$this->assertSame( '', $repository->claim_change_lock( $creative_id ) );

		$repository->release_change_lock( $creative_id, $lock );
		$next = $repository->claim_change_lock( $creative_id );
		$this->assertNotSame( '', $next );
		$repository->release_change_lock( $creative_id, $next );
	}

	/**
	 * The repository that owns the replacement locks.
	 *
	 * They moved here with the rest of the replacement lifecycle: a lock over a
	 * proposed swap belongs beside the swap, not beside the creative record.
	 *
	 * @return Creative_Revision_Repository
	 */
	private function repository(): Creative_Revision_Repository {
		return new Creative_Revision_Repository( new Creative_Repository() );
	}
}
