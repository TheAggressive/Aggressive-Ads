<?php
/**
 * Atomic rate-limit counter claims.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Repository\Rate_Limit_Repository;
use WP_UnitTestCase;

/**
 * Counter decisions are made inside one serialized repository operation.
 */
final class RateLimitRepositoryTest extends WP_UnitTestCase {

	/**
	 * One claim increments exactly once and refuses beyond the limit.
	 *
	 * @return void
	 */
	public function test_claim_serializes_increment_and_limit_decision(): void {
		$key        = 'aggr_test_atomic_rate_limit';
		$repository = new Rate_Limit_Repository();
		$now        = time();

		delete_transient( $key );

		$first  = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
		$second = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
		$denied = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertIsArray( $denied );
		$this->assertTrue( $first['allowed'] );
		$this->assertSame( 1, $first['count'] );
		$this->assertTrue( $second['allowed'] );
		$this->assertSame( 2, $second['count'] );
		$this->assertFalse( $denied['allowed'] );
		$this->assertSame( 2, $denied['count'] );

		delete_transient( $key );
	}
}
