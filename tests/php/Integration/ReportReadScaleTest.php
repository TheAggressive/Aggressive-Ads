<?php
/**
 * Org-scoped report reads stay bounded as history accumulates.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Repository\Rollup_Report_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

/**
 * The measurement that made P14's first slice necessary, kept as a guard.
 *
 * Before this phase the advertiser dashboard's first tile summed
 * `aggr_rollups` for an organization with **no date predicate at all**, so it
 * grew with everything that organization had ever delivered and was bounded
 * only by retention — which defaults to keeping every day. Measured on this
 * fixture, one year of a modest advertiser's history, the plan examined 12,775
 * rows against a 30-day read's 1,500, and the gap widens for as long as the
 * site keeps running.
 *
 * **The query under test is the one production issued, not a copy of it.**
 * Each read is made through the repository and `$wpdb->last_query` is what gets
 * EXPLAINed. A guard that EXPLAINs a hand-written string keeps passing after
 * the code it is supposed to be watching has changed — the failure mode P13
 * hit twice and wrote down in `testing-strategy.md`.
 */
final class ReportReadScaleTest extends WP_UnitTestCase {

	/** Campaigns the reporting organization has run. */
	private const CAMPAIGNS = 10;

	/** Placements each campaign delivered on. */
	private const PLACEMENTS = 5;

	/** A year of history, well inside a default retention of "forever". */
	private const DAYS = 365;

	/** The organization being reported on. */
	private const ORG_ID = 4242;

	/** Other tenants, so the org predicate has something to exclude. */
	private const OTHER_ORGS = 4;

	/**
	 * Reads under test.
	 *
	 * @var Rollup_Report_Repository
	 */
	private Rollup_Report_Repository $reports;

	public function set_up(): void {
		parent::set_up();

		( new Rollup_Repository() )->install_table();

		$this->reports = new Rollup_Report_Repository();
	}

	/**
	 * Fills the projection with a year of delivery for several tenants.
	 *
	 * Written directly rather than through the recorder: the production write
	 * path is proven in `FrozenTenancyTest` and `ReportingTest`, and what this
	 * file needs is volume.
	 *
	 * @return array{total: int, mine: int} Rows written, and rows for the reporting org.
	 */
	private function seed(): array {
		global $wpdb;

		$table  = ( new Rollup_Repository() )->table_name();
		$values = array();
		$mine   = 0;

		for ( $day = 0; $day < self::DAYS; $day++ ) {
			$day_utc = gmdate( 'Y-m-d', strtotime( "-{$day} days" ) );

			for ( $campaign = 1; $campaign <= self::CAMPAIGNS; $campaign++ ) {
				for ( $placement = 1; $placement <= self::PLACEMENTS; $placement++ ) {
					$values[] = $wpdb->prepare(
						'(%s,%d,%d,%d,%d,%d,%d,%d)',
						$day_utc,
						$placement,
						1000 + $campaign,
						2000 + $campaign,
						self::ORG_ID,
						40,
						2,
						30
					);
					++$mine;
				}
			}

			for ( $org = 1; $org <= self::OTHER_ORGS; $org++ ) {
				for ( $placement = 1; $placement <= self::PLACEMENTS; $placement++ ) {
					$values[] = $wpdb->prepare(
						'(%s,%d,%d,%d,%d,%d,%d,%d)',
						$day_utc,
						$placement,
						5000 + $org,
						6000 + $org,
						7000 + $org,
						40,
						2,
						30
					);
				}
			}
		}

		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bulk fixture for this plugin's own table; every value is prepared above.
			$wpdb->query( "INSERT INTO {$table} (day_utc,placement_id,campaign_id,line_item_id,org_id,impressions,clicks,viewables) VALUES " . implode( ',', $chunk ) );
		}

		return array(
			'total' => count( $values ),
			'mine'  => $mine,
		);
	}

	/**
	 * The plan for the query the repository just ran.
	 *
	 * @return array<string, mixed>
	 */
	private function plan_for_last_query(): array {
		global $wpdb;

		$sql = (string) $wpdb->last_query;

		$this->assertStringContainsString( 'day_utc', $sql, 'The read issued no date predicate at all, so it is bounded by nothing.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query-plan introspection on a query this test just caused.
		$plan = $wpdb->get_row( 'EXPLAIN ' . $sql, ARRAY_A );

		$this->assertIsArray( $plan );

		return $plan;
	}

	/**
	 * Totals and series over a range read a slice of history, not all of it.
	 *
	 * One test rather than several: the fixture costs a year of rows to build
	 * and rebuilding it per assertion would trade minutes for nothing.
	 */
	public function test_org_reads_stay_bounded_at_a_year_of_history(): void {
		$seeded = $this->seed();
		$period = Report_Period::ending( 30, gmdate( 'Y-m-d' ) );

		$this->assertNotNull( $period );

		$totals = $this->reports->totals_for_org( self::ORG_ID, $period );

		/*
		 * The numbers first. A bounded read that returns the wrong sum is not
		 * an improvement, and asserting only the plan is how a guard ends up
		 * watching a query nobody would want the answer from.
		 */
		$expected_rows_in_range = self::CAMPAIGNS * self::PLACEMENTS * 30;

		$this->assertSame( $expected_rows_in_range * 40, $totals['impressions'], 'The ranged total did not sum the days in range.' );
		$this->assertSame( $expected_rows_in_range * 2, $totals['clicks'] );
		$this->assertSame( $expected_rows_in_range * 30, $totals['viewables'] );

		$plan     = $this->plan_for_last_query();
		$examined = (int) ( $plan['rows'] ?? PHP_INT_MAX );

		$this->assertSame(
			'org_day',
			$plan['key'] ?? null,
			'The org total fell off org_day, so a tenant read scans the table.'
		);

		/*
		 * **And that the index is bounding the range, not merely being named.**
		 *
		 * P13 proved the name alone is not enough: an index rebuilt uselessly
		 * still appeared in the plan, because MySQL will use it for one clause
		 * while scanning every row for another. Rows examined is the signal
		 * that survives. Thirty days out of three hundred and sixty-five must
		 * touch a small fraction of this organization's rows; a generous bound
		 * still fails a read that dropped its date predicate, which is exactly
		 * what this phase removed.
		 */
		$this->assertLessThan(
			(int) ( $seeded['mine'] / 4 ),
			$examined,
			sprintf(
				'A 30-day org total examined %d of the organization\'s %d rows. The read is not bounded by its range.',
				$examined,
				$seeded['mine']
			)
		);

		$series = $this->reports->series_for_org( self::ORG_ID, $period );

		$this->assertCount( 30, $series, 'The series must have a row per day in range, zeros included.' );

		$series_plan     = $this->plan_for_last_query();
		$series_examined = (int) ( $series_plan['rows'] ?? PHP_INT_MAX );

		$this->assertLessThan(
			(int) ( $seeded['mine'] / 4 ),
			$series_examined,
			sprintf(
				'A 30-day series examined %d of the organization\'s %d rows.',
				$series_examined,
				$seeded['mine']
			)
		);

		if ( '1' === getenv( 'AGGR_REPORT_PERFORMANCE' ) ) {
			fwrite(
				STDOUT,
				sprintf(
					"\norg report reads: %s rows in table, %s for the org over %d days; a 30-day total examines %s, a 30-day series %s\n",
					number_format( $seeded['total'] ),
					number_format( $seeded['mine'] ),
					self::DAYS,
					number_format( $examined ),
					number_format( $series_examined )
				)
			);
		}
	}
}
