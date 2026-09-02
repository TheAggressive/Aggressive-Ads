<?php
/**
 * Decision outcome counters, through the production decision path.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Array_Frequency_Store;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Workflow\Decision_Metrics;
use Aggressive\Ads\Workflow\Fill_Cache;
use WP_UnitTestCase;

/**
 * **These go through the engine on purpose.**
 *
 * A counter test that arranges its own numbers tests arithmetic. The defect
 * this phase replaced was not arithmetic — it was a counter nothing wrote on
 * one of the two decision paths, and a store that lost increments under load.
 * Both are only visible from the production path, which is why every assertion
 * below drives `Decision_Engine` rather than the metrics object.
 */
final class DecisionMetricsTest extends WP_UnitTestCase {

	/**
	 * Metrics service under test.
	 *
	 * @var Decision_Metrics
	 */
	private Decision_Metrics $metrics;

	/**
	 * Durable counters the service writes through.
	 *
	 * @var Decision_Rollup_Repository
	 */
	private Decision_Rollup_Repository $rollups;

	public function set_up(): void {
		parent::set_up();

		$this->rollups = new Decision_Rollup_Repository();
		$this->rollups->install_table();

		$this->metrics = new Decision_Metrics( $this->rollups );

		Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->install_table();
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );
	}

	/**
	 * An engine wired to this test's metrics.
	 */
	private function engine(): Decision_Engine {
		$container = Plugin::instance()->container();

		return new Decision_Engine(
			$container->get( Creative_Assignment_Repository::class ),
			$container->get( Creative_Assignment_Migrator::class ),
			$this->metrics,
			Decision_Pipeline::standard(),
			$container->get( Fill_Cache::class ),
			new Array_Frequency_Store(),
			$container->get( \Aggressive\Ads\Repository\Line_Item_Repository::class )
		);
	}

	/**
	 * A candidate that cannot serve: no attachment to render.
	 *
	 * @param int $id Candidate id.
	 * @return array<string, mixed>
	 */
	private static function unservable( int $id = 1 ): array {
		return array(
			'id'            => $id,
			'line_item_id'  => $id,
			'campaign_id'   => $id,
			'revision_id'   => $id,
			'weight'        => 100,
			'attachment_id' => 0,
			'click_url'     => 'https://example.com/ad',
		);
	}

	/**
	 * Today's durable totals for one placement.
	 *
	 * **Durable only, never a buffer.** A reader sees what survived the
	 * request, so a test cannot pass by reading back something nothing wrote.
	 *
	 * @param int $placement Placement id.
	 * @return array<string, int>
	 */
	private function totals( int $placement ): array {
		$this->metrics->flush();

		return $this->rollups->totals_for_placement( $placement, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
	}

	/**
	 * An opportunity that returns nothing is counted, and says why.
	 */
	public function test_an_unfilled_decision_records_a_request_and_a_reason(): void {
		$this->engine()->decide( 99, time(), 1, array( self::unservable() ) );

		$totals = $this->totals( 99 );

		$this->assertSame( 1, $totals[ Decision_Outcome::REQUEST ] ?? 0, 'The opportunity was not counted, so fill rate has no denominator.' );
		$this->assertSame( 0, $totals[ Decision_Outcome::FILL ] ?? 0 );
		$reasons = array_filter(
			$totals,
			static fn ( string $outcome ): bool => Decision_Outcome::is_no_fill_reason( $outcome ),
			ARRAY_FILTER_USE_KEY
		);

		$this->assertCount( 1, $reasons, 'An unfilled opportunity must record exactly one reason: ' . wp_json_encode( $totals ) );
		$this->assertSame( 1, (int) reset( $reasons ) );
		$this->assertContains(
			(string) key( $reasons ),
			No_Fill_Reason::all(),
			'The stored reason must come from the P10 taxonomy, not from an internal exclusion code.'
		);
	}

	/**
	 * **The invariant that makes the three numbers reconcile.**
	 *
	 * Requests equals fills plus every no-fill reason. The counters this
	 * replaced could not satisfy it: they counted each losing candidate's
	 * exclusion alongside the slot's own outcome, so a busy placement that
	 * filled every time still accumulated reasons and the totals summed to
	 * nothing meaningful.
	 */
	public function test_requests_equal_fills_plus_no_fill_reasons(): void {
		$engine = $this->engine();

		foreach ( range( 1, 4 ) as $ignored ) {
			$engine->decide( 99, time(), 1, array( self::unservable() ) );
		}

		$totals = $this->totals( 99 );

		$requests = $totals[ Decision_Outcome::REQUEST ] ?? 0;
		$fills    = $totals[ Decision_Outcome::FILL ] ?? 0;
		$reasons  = 0;

		foreach ( $totals as $outcome => $count ) {
			if ( Decision_Outcome::is_no_fill_reason( (string) $outcome ) ) {
				$reasons += $count;
			}
		}

		$this->assertSame( 4, $requests );
		$this->assertSame( $requests, $fills + $reasons, 'Requests must equal fills plus reasons, or no rate computed from these is meaningful.' );
	}

	/**
	 * **The batch path counted nothing before P13.**
	 *
	 * `decide_page()` goes through a pure-domain coordinator that may not call
	 * a WordPress function, so nothing recorded there. A page served through
	 * the batch decision produced no counters while the same page served slot
	 * by slot produced them — an answer that depended on the code path taken,
	 * which is worse than no answer.
	 */
	public function test_the_batch_page_path_counts_every_slot(): void {
		$this->engine()->decide_page(
			array(
				'top'    => array(
					'placement_id' => 101,
					'candidates'   => array( self::unservable( 1 ) ),
				),
				'bottom' => array(
					'placement_id' => 102,
					'candidates'   => array( self::unservable( 2 ) ),
				),
			),
			time(),
			1
		);

		$this->metrics->flush();

		$this->assertSame(
			1,
			$this->rollups->totals_for_placement( 101, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) )[ Decision_Outcome::REQUEST ] ?? 0,
			'The batch path did not count the first slot.'
		);
		$this->assertSame(
			1,
			$this->rollups->totals_for_placement( 102, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) )[ Decision_Outcome::REQUEST ] ?? 0,
			'The batch path did not count the second slot.'
		);
	}

	/**
	 * Looking at a decision is not making one.
	 */
	public function test_a_decision_made_with_recording_disabled_counts_nothing(): void {
		$this->engine()->decide( 99, time(), 1, array( self::unservable() ), false );

		$this->assertSame( array(), $this->totals( 99 ) );
	}

	/**
	 * **Nothing is durable until the request ends.**
	 *
	 * The cost stays off the path the client waits on, which is the whole
	 * reason this buffers. A test that read the buffer would not notice if the
	 * flush stopped writing.
	 */
	public function test_counts_are_buffered_until_the_request_ends(): void {
		$this->engine()->decide( 99, time(), 1, array( self::unservable() ) );

		$this->assertSame(
			array(),
			$this->rollups->totals_for_placement( 99, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ),
			'A decision wrote to the database before the response had gone.'
		);

		$this->metrics->flush();

		$this->assertNotSame( array(), $this->rollups->totals_for_placement( 99, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ) );
	}

	/**
	 * And a page with many slots costs one write per placement, not one per
	 * outcome. Asserted as a number, because the number is what regresses.
	 */
	public function test_a_whole_page_flushes_in_one_write_per_placement(): void {
		global $wpdb;

		$engine = $this->engine();

		// Six decisions across two placements, each producing a request and a reason.
		foreach ( range( 1, 3 ) as $ignored ) {
			$engine->decide( 201, time(), 1, array( self::unservable() ) );
			$engine->decide( 202, time(), 1, array( self::unservable() ) );
		}

		// Warm the table-name memoisation so this measures the flush.
		$this->rollups->totals( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );

		$before = $wpdb->num_queries;

		$this->metrics->flush();

		$this->assertSame(
			2,
			$wpdb->num_queries - $before,
			'Twelve counts across two placements must cost two statements, not twelve.'
		);
	}

	/**
	 * Flushing twice does not double anything, and an empty flush is free.
	 */
	public function test_flushing_twice_changes_nothing(): void {
		global $wpdb;

		$this->engine()->decide( 99, time(), 1, array( self::unservable() ) );

		$this->metrics->flush();
		$after_first = $this->rollups->totals_for_placement( 99, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );

		$before = $wpdb->num_queries;
		$this->metrics->flush();

		$this->assertSame( 0, $wpdb->num_queries - $before, 'A second flush must write nothing at all.' );
		$this->assertSame( $after_first, $this->rollups->totals_for_placement( 99, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ) );
	}

	/**
	 * The hook is what makes any of this durable in production.
	 *
	 * Without it the buffer is discarded at the end of every request and every
	 * assertion above passes on a counter that never persists.
	 */
	public function test_the_shutdown_hook_is_what_writes_the_counts(): void {
		$metrics = new Decision_Metrics( $this->rollups );
		$metrics->init();

		$this->assertNotFalse( has_action( 'shutdown', array( $metrics, 'flush' ) ) );
	}
}
