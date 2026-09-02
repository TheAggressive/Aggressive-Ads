<?php
/**
 * Decision counter growth and query plans at the supported catalogue size.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use WP_UnitTestCase;

/**
 * The measurement P13 requires before it can exit, rather than the assumption
 * it would otherwise ship on.
 *
 * The counters this phase introduced replaced an option that grew without
 * bound. "A table instead" is only an improvement if the table's own growth is
 * known and its reads stay bounded as it fills — so both are asserted here as
 * numbers, at the catalogue size `DeliveryScaleTest` already treats as
 * supported.
 *
 * **Rows are written directly rather than through the engine.** The production
 * write path is proven in `DecisionMetricsTest`; what this file needs is
 * volume, and driving a thousand placements through the pipeline would measure
 * the pipeline instead of the storage.
 */
final class DecisionRollupScaleTest extends WP_UnitTestCase {

	/**
	 * Placements in the supported catalogue.
	 */
	private const PLACEMENTS = 1_000;

	/**
	 * Days of history the retention window keeps.
	 */
	private const DAYS = 30;

	/**
	 * Repository under test.
	 *
	 * @var Decision_Rollup_Repository
	 */
	private Decision_Rollup_Repository $rollups;

	public function set_up(): void {
		parent::set_up();

		$this->rollups = new Decision_Rollup_Repository();
		$this->rollups->install_table();
	}

	/**
	 * Fills the table with a realistic spread of outcomes.
	 *
	 * Every placement records a request and a fill; a third of them also record
	 * two distinct no-fill reasons. A row per placement per day per *possible*
	 * outcome would be the pessimistic ceiling and is not what a real site
	 * produces — only outcomes that actually occur are ever written.
	 *
	 * @return int Rows written.
	 */
	private function seed(): int {
		$rows    = 0;
		$reasons = array( No_Fill_Reason::TARGETING_MISMATCH, No_Fill_Reason::FREQUENCY_CAPPED );

		for ( $day = 0; $day < self::DAYS; $day++ ) {
			$day_utc = gmdate( 'Y-m-d', strtotime( "-{$day} days" ) );

			for ( $placement = 1; $placement <= self::PLACEMENTS; $placement++ ) {
				$increments = array(
					Decision_Outcome::REQUEST => 40,
					Decision_Outcome::FILL    => 32,
				);

				if ( 0 === $placement % 3 ) {
					foreach ( $reasons as $reason ) {
						$increments[ $reason ] = 4;
					}
				}

				$this->rollups->add( $day_utc, $placement, $increments );

				$rows += count( $increments );
			}
		}

		return $rows;
	}

	/**
	 * Rows, bytes and read plans at the supported size.
	 *
	 * One test rather than several because the fixture costs thirty thousand
	 * writes to build, and rebuilding it per assertion would trade minutes for
	 * nothing.
	 */
	public function test_growth_and_read_plans_at_one_thousand_placements(): void {
		global $wpdb;

		$expected_rows = $this->seed();

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Measuring this plugin's own table.
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->assertSame(
			$expected_rows,
			$rows,
			'Row count must be exactly one per (placement, day, outcome) that occurred — anything more means the unique key is not deduplicating.'
		);

		/*
		 * **Growth is bounded by outcomes that happen, not outcomes that
		 * exist.** Twenty-eight codes are storable; a placement that always
		 * fills writes two. The ceiling that matters is what a site produces,
		 * and asserting it here is what would catch a change that started
		 * writing a row per possible reason.
		 */
		$per_placement_day = $rows / ( self::PLACEMENTS * self::DAYS );

		$this->assertLessThan(
			4.0,
			$per_placement_day,
			sprintf( 'Each placement-day cost %.2f rows; the counters are recording outcomes that did not occur.', $per_placement_day )
		);

		/*
		 * A per-placement read is the one a publisher makes to ask why a slot
		 * is empty, and it must not grow with the table. The unique key leads
		 * on `placement_id`, so this is an index range however full the table
		 * gets.
		 */
		$first = gmdate( 'Y-m-d', strtotime( '-' . ( self::DAYS - 1 ) . ' days' ) );
		$today = gmdate( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query plan introspection in a test.
		$plan = $wpdb->get_row(
			$wpdb->prepare(
				"EXPLAIN SELECT outcome, SUM(events) FROM {$table} WHERE placement_id = %d AND day_utc BETWEEN %s AND %s GROUP BY outcome",
				7,
				$first,
				$today
			),
			ARRAY_A
		);

		$this->assertIsArray( $plan );
		$this->assertSame(
			'slot_day_outcome',
			$plan['key'] ?? null,
			'The per-placement read fell off its index, so answering "why is this slot empty" scans the table.'
		);

		$examined = (int) ( $plan['rows'] ?? PHP_INT_MAX );

		$this->assertLessThan(
			$rows / 10,
			$examined,
			sprintf( 'The per-placement read examined %d of %d rows, which is not a bounded scope.', $examined, $rows )
		);

		/*
		 * The site-wide read is deliberately allowed to touch every placement —
		 * it is a whole-day question — but it must still be bounded by the day
		 * range rather than reading history. `day_outcome` exists for exactly
		 * this shape, and its absence is what would turn an operator's glance
		 * into a full scan.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query plan introspection in a test.
		$site_plan = $wpdb->get_row(
			$wpdb->prepare(
				"EXPLAIN SELECT outcome, SUM(events) FROM {$table} WHERE day_utc BETWEEN %s AND %s GROUP BY outcome",
				$today,
				$today
			),
			ARRAY_A
		);

		$this->assertIsArray( $site_plan );
		$this->assertSame(
			'day_outcome',
			$site_plan['key'] ?? null,
			'The site-wide read is not using the day index, so a one-day question reads all history.'
		);

		/*
		 * **And that the index is doing work, not merely being named.**
		 *
		 * The name assertion above is not enough, and this was proven rather
		 * than assumed: rebuilding `day_outcome` as `(outcome)` alone — useless
		 * for a day range — left the plan still *naming* `day_outcome`, because
		 * MySQL will happily use it for the GROUP BY while scanning every row
		 * for the WHERE. The guard reported success over an index it was no
		 * longer reading.
		 *
		 * Rows examined is the signal that survives. One day out of thirty must
		 * touch roughly a thirtieth of the table; a generous bound still fails
		 * an index that cannot serve the range.
		 */
		$site_examined = (int) ( $site_plan['rows'] ?? PHP_INT_MAX );

		$this->assertLessThan(
			$rows / 5,
			$site_examined,
			sprintf(
				'A one-day read examined %d of %d rows. The day index is named in the plan but not serving the range.',
				$site_examined,
				$rows
			)
		);

		if ( '1' === getenv( 'AGGR_REPORT_PERFORMANCE' ) ) {
			/*
			 * Rows, not bytes. `SHOW TABLE STATUS` reports InnoDB's estimate,
			 * and this suite runs inside a transaction that is rolled back, so
			 * the byte figures it gives here are not the table's real size —
			 * reporting them would put a wrong number in a document somebody
			 * later plans capacity from. Row counts are exact because they are
			 * counted, and the projection below is arithmetic on them.
			 */
			fwrite(
				STDOUT,
				sprintf(
					"\ndecision rollups: %d placements x %d days = %s rows (%.2f per placement-day); at %d-day retention that is ~%s rows\n",
					self::PLACEMENTS,
					self::DAYS,
					number_format( $rows ),
					$per_placement_day,
					400,
					number_format( (int) round( $per_placement_day * self::PLACEMENTS * 400 ) )
				)
			);
		}
	}

	/**
	 * Retention keeps the table bounded in time, in batches that do not lock it.
	 *
	 * Without this the counters are the option again: correct, useful, and
	 * growing for ever.
	 */
	public function test_retention_bounds_the_table_in_bounded_batches(): void {
		$this->seed();

		$cutoff = gmdate( 'Y-m-d', strtotime( '-15 days' ) );

		$deleted = $this->rollups->purge_through( $cutoff, 5_000 );

		$this->assertSame( 5_000, $deleted, 'The purge must stop at its limit rather than deleting an unbounded span.' );

		$total = 0;

		do {
			$batch  = $this->rollups->purge_through( $cutoff, 5_000 );
			$total += $batch;
		} while ( $batch > 0 );

		$this->assertGreaterThan( 0, $total );

		$this->assertSame(
			array(),
			$this->rollups->totals( '2000-01-01', $cutoff ),
			'Retention left rows behind its own cutoff.'
		);

		$this->assertNotSame(
			array(),
			$this->rollups->totals( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ),
			'Retention deleted past its cutoff.'
		);
	}
}
