<?php
/**
 * Native-delivery repository input boundary.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Repository;

use Aggressive\Ads\Repository\Delivery_Repository;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/** Invalid identifiers never reach WordPress or SQL. */
final class DeliveryRepositoryTest extends TestCase {

	/** Zero and negative placement ids have no candidate set. */
	public function test_candidate_ids_rejects_nonpositive_placements(): void {
		$repository = new Delivery_Repository();

		$this->assertSame( array(), $repository->candidate_ids( 0 ) );
		$this->assertSame( array(), $repository->candidate_ids( -1 ) );
	}

	/** An invalid exact identity cannot become a database lookup. */
	public function test_candidate_rejects_invalid_identity_parts(): void {
		$repository = new Delivery_Repository();

		$this->assertNull( $repository->candidate( 0, 1 ) );
		$this->assertNull( $repository->candidate( 1, 0 ) );
		$this->assertNull( $repository->candidate( 1, 1, -1 ) );
	}
}
