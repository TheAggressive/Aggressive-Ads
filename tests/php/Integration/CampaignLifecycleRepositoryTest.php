<?php
/**
 * Durable lifecycle sweep pagination.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Campaign_Lifecycle_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use WP_UnitTestCase;

/**
 * Bounded cron queries advance beyond a full first page.
 */
final class CampaignLifecycleRepositoryTest extends WP_UnitTestCase {

	/**
	 * Clears durable cursors between tests.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->clear_cursors();
	}

	/**
	 * Clears durable cursors after tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->clear_cursors();
		parent::tear_down();
	}

	/**
	 * Clock candidates beyond the first batch are returned on the next run.
	 *
	 * @return void
	 */
	public function test_status_sweep_advances_past_a_full_batch(): void {
		$ids        = array(
			$this->campaign( Post_Statuses::APPROVED ),
			$this->campaign( Post_Statuses::APPROVED ),
			$this->campaign( Post_Statuses::APPROVED ),
		);
		$repository = new Campaign_Lifecycle_Repository();

		$this->assertSame( array_slice( $ids, 0, 2 ), $repository->ids_in_status( array( Post_Statuses::APPROVED ), 2 ) );
		$this->assertSame( array( $ids[2] ), $repository->ids_in_status( array( Post_Statuses::APPROVED ), 2 ) );
	}

	/**
	 * Ending-soon candidates beyond the first batch are not starved.
	 *
	 * @return void
	 */
	public function test_ending_soon_sweep_advances_past_a_full_batch(): void {
		$end        = time() + HOUR_IN_SECONDS;
		$ids        = array(
			$this->campaign( Post_Statuses::LIVE, $end ),
			$this->campaign( Post_Statuses::LIVE, $end ),
			$this->campaign( Post_Statuses::LIVE, $end ),
		);
		$repository = new Campaign_Lifecycle_Repository();

		$this->assertSame( array_slice( $ids, 0, 2 ), $repository->ids_ending_between( time(), $end + 1, 2 ) );
		$this->assertSame( array( $ids[2] ), $repository->ids_ending_between( time(), $end + 1, 2 ) );
	}

	/**
	 * Retention candidates beyond already-purged rows are eventually reached.
	 *
	 * @return void
	 */
	public function test_retention_sweep_advances_past_a_full_batch(): void {
		$end        = time() - DAY_IN_SECONDS;
		$ids        = array(
			$this->campaign( Post_Statuses::CANCELLED, $end ),
			$this->campaign( Post_Statuses::CANCELLED, $end ),
			$this->campaign( Post_Statuses::CANCELLED, $end ),
		);
		$repository = new Campaign_Lifecycle_Repository();

		$this->assertSame( array_slice( $ids, 0, 2 ), $repository->ids_terminal_before( time(), 2 ) );
		$this->assertSame( array( $ids[2] ), $repository->ids_terminal_before( time(), 2 ) );
	}

	/**
	 * Creates a campaign candidate.
	 *
	 * @param string $status Campaign status.
	 * @param int    $end_ts Optional end timestamp.
	 * @return int
	 */
	private function campaign( string $status, int $end_ts = 0 ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
			)
		);

		if ( $end_ts > 0 ) {
			update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, $end_ts );
		}

		return $campaign_id;
	}

	/**
	 * Removes repository cursor options.
	 *
	 * @return void
	 */
	private function clear_cursors(): void {
		delete_option( 'aggr_lifecycle_cursor_clock' );
		delete_option( 'aggr_lifecycle_cursor_ending_soon' );
		delete_option( 'aggr_lifecycle_cursor_retention' );
	}
}
