<?php
/**
 * What a publication attempt reports about partial failure.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Publication_Result;
use PHPUnit\Framework\TestCase;

/**
 * The class exists because partial failure is the normal case, so the tests are
 * about the distinctions a retry depends on: complete versus incomplete, and
 * "nothing worked" versus "some of it is already live on a public site".
 */
final class PublicationResultTest extends TestCase {

	/**
	 * A fresh result has published nothing and failed nothing.
	 *
	 * Complete-and-empty is deliberate: a campaign with no creatives has no
	 * failures, and reporting it as incomplete would block a legal transition.
	 *
	 * @return void
	 */
	public function test_an_empty_result_is_complete_and_has_published_nothing(): void {
		$result = new Publication_Result();

		$this->assertTrue( $result->is_complete() );
		$this->assertFalse( $result->has_published() );
		$this->assertSame( array(), $result->ad_ids() );
	}

	/**
	 * Created and reused are tracked apart, which is what proves idempotence.
	 *
	 * @return void
	 */
	public function test_created_and_reused_are_reported_separately(): void {
		$result = new Publication_Result();
		$result->created( 11, 101 );
		$result->reused( 22, 202 );

		$this->assertSame( array( 11 => 101 ), $result->created_ids() );
		$this->assertSame( array( 22 => 202 ), $result->reused_ids() );
		$this->assertTrue( $result->has_published() );
		$this->assertTrue( $result->is_complete() );
	}

	/**
	 * A retry that creates nothing and reuses everything still published.
	 *
	 * @return void
	 */
	public function test_a_fully_reused_result_counts_as_published(): void {
		$result = new Publication_Result();
		$result->reused( 22, 202 );

		$this->assertSame( array(), $result->created_ids() );
		$this->assertTrue( $result->has_published() );
	}

	/**
	 * One failure makes the attempt incomplete without erasing the successes.
	 *
	 * This is the case the class was written for: two creatives are live and
	 * the third is not, and a caller that only sees "failed" either duplicates
	 * the live two on retry or refuses to retry at all.
	 *
	 * @return void
	 */
	public function test_a_partial_failure_keeps_the_successes(): void {
		$result = new Publication_Result();
		$result->created( 11, 101 );
		$result->failed( 33, 'aggr_upload_missing' );

		$this->assertFalse( $result->is_complete() );
		$this->assertTrue( $result->has_published() );
		$this->assertSame( array( 33 => 'aggr_upload_missing' ), $result->failures() );
		$this->assertSame( array( 101 ), $result->ad_ids() );
	}

	/**
	 * Total failure is distinguishable from partial failure.
	 *
	 * @return void
	 */
	public function test_a_total_failure_reports_nothing_published(): void {
		$result = new Publication_Result();
		$result->failed( 33, 'aggr_upload_missing' );
		$result->failed( 44, 'aggr_provider_error' );

		$this->assertFalse( $result->is_complete() );
		$this->assertFalse( $result->has_published() );
		$this->assertCount( 2, $result->failures() );
	}

	/**
	 * Every id the campaign now has is returned, created and reused alike.
	 *
	 * The union is keyed by creative id, so a creative recorded both ways
	 * contributes once rather than twice — a duplicate ad id here would be
	 * published twice on the next reconcile.
	 *
	 * @return void
	 */
	public function test_ad_ids_unions_created_and_reused_without_duplicating(): void {
		$result = new Publication_Result();
		$result->created( 11, 101 );
		$result->reused( 22, 202 );

		$ids = $result->ad_ids();
		sort( $ids );

		$this->assertSame( array( 101, 202 ), $ids );
		$this->assertSame( $ids, array_unique( $ids ) );
	}

	/**
	 * Recording the same creative twice keeps the latest id, not both.
	 *
	 * @return void
	 */
	public function test_recording_a_creative_twice_replaces_rather_than_appends(): void {
		$result = new Publication_Result();
		$result->created( 11, 101 );
		$result->created( 11, 999 );

		$this->assertSame( array( 11 => 999 ), $result->created_ids() );
		$this->assertSame( array( 999 ), $result->ad_ids() );
	}
}
