<?php
/**
 * The named slices of a campaign list.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Campaign_Filter;
use PHPUnit\Framework\TestCase;

/**
 * Tested here as well as through the portal, because the repository has a
 * safety net that hides a wrong answer from an integration test: it intersects
 * whatever it is given with the known statuses and falls back to all of them
 * when nothing survives. That net is wanted — an unknown status reaching
 * `WP_Query` widens to `publish`, which no campaign ever has — but it means a
 * broken rule here still produces a correct-looking list. A mutation that made
 * an unknown slug resolve to a status that does not exist survived the portal
 * suite for exactly that reason.
 */
final class CampaignFilterTest extends TestCase {

	/** Every slice names statuses that actually exist. */
	public function test_every_slice_resolves_to_real_statuses(): void {
		foreach ( Campaign_Filter::all() as $filter ) {
			$statuses = Campaign_Filter::statuses( $filter );

			$this->assertNotSame( array(), $statuses, "The {$filter} slice covers no status." );

			foreach ( $statuses as $status ) {
				$this->assertTrue(
					Post_Statuses::is_valid( $status ),
					"The {$filter} slice names {$status}, which is not a status."
				);
			}
		}
	}

	/**
	 * The slices do not overlap.
	 *
	 * A campaign counted in two tiles would make the three add up to more than
	 * the list they come from, and `counts()` classifies with the first match,
	 * so the overlap would be silent rather than doubled.
	 */
	public function test_the_slices_do_not_overlap(): void {
		$seen = array();

		foreach ( Campaign_Filter::all() as $filter ) {
			foreach ( Campaign_Filter::statuses( $filter ) as $status ) {
				$this->assertArrayNotHasKey(
					$status,
					$seen,
					sprintf(
						'%s is in both %s and %s.',
						$status,
						$seen[ $status ] ?? '',
						$filter
					)
				);

				$seen[ $status ] = $filter;
			}
		}
	}

	/**
	 * An unknown or absent slug widens rather than narrows.
	 *
	 * The direction is the whole point. Narrowing to nothing would show an
	 * advertiser an empty page, which reads as "you have no campaigns" and is
	 * believed; widening shows them their campaigns, which is the answer they
	 * can act on. A stale bookmark should cost nothing.
	 */
	public function test_an_unknown_slug_widens_to_every_status(): void {
		$this->assertSame( Post_Statuses::all(), Campaign_Filter::statuses( '' ) );
		$this->assertSame( Post_Statuses::all(), Campaign_Filter::statuses( 'not-a-slice' ) );
		$this->assertSame( Post_Statuses::all(), Campaign_Filter::statuses( Post_Statuses::LIVE ) );
	}

	/** Only the three slugs are slices. */
	public function test_is_valid_accepts_the_slices_and_nothing_else(): void {
		foreach ( Campaign_Filter::all() as $filter ) {
			$this->assertTrue( Campaign_Filter::is_valid( $filter ) );
		}

		$this->assertFalse( Campaign_Filter::is_valid( '' ) );
		$this->assertFalse( Campaign_Filter::is_valid( 'running ' ) );
		$this->assertFalse( Campaign_Filter::is_valid( Post_Statuses::LIVE ) );
	}
}
