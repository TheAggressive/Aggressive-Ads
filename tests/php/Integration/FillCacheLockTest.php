<?php
/**
 * Native-delivery cache rebuild serialization.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Workflow\Fill_Cache;
use WP_UnitTestCase;

/** One request owns a placement rebuild at a time. */
final class FillCacheLockTest extends WP_UnitTestCase {

	/** A non-owner cannot claim or release an active rebuild lock. */
	public function test_rebuild_lock_is_owned_and_reclaimable_after_release(): void {
		$cache        = Plugin::instance()->container()->get( Fill_Cache::class );
		$placement_id = 987_654_321;
		$cache->delete( $placement_id );

		$owner = $cache->claim_rebuild( $placement_id );
		$this->assertNotSame( '', $owner );
		$this->assertSame( '', $cache->claim_rebuild( $placement_id ) );

		$cache->release_rebuild( $placement_id, 'not-the-owner' );
		$this->assertSame( '', $cache->claim_rebuild( $placement_id ) );

		$cache->release_rebuild( $placement_id, $owner );
		$next_owner = $cache->claim_rebuild( $placement_id );
		$this->assertNotSame( '', $next_owner );
		$cache->release_rebuild( $placement_id, $next_owner );
	}
}
