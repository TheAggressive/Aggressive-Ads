<?php
/**
 * Decision outcome counters, against real MySQL.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use WP_UnitTestCase;

/**
 * Why this suite and not a unit test: every claim here is about what the
 * database does. The counters exist because an option could not do these
 * things — refuse to lose a concurrent increment, be pruned by day, or be
 * rebuilt — and none of that is expressible without MySQL.
 */
final class DecisionRollupTest extends WP_UnitTestCase {

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
	 * Rows currently stored.
	 */
	private function row_count(): int {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public function test_the_table_installs_with_the_columns_and_indexes_the_schema_declares(): void {
		global $wpdb;

		$table = $this->rollups->table_name();

		$this->assertTrue( $this->rollups->table_exists(), 'The fixture must exist before anything is asserted about it.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		sort( $columns );

		$expected = Schema::decision_rollups_columns();
		sort( $expected );

		$this->assertSame( $expected, $columns );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names = array_values( array_unique( array_column( $rows, 'Key_name' ) ) );
		sort( $names );

		$declared = Schema::decision_rollups_index_names();
		sort( $declared );

		$this->assertSame( $declared, $names );

		/*
		 * And that the key is actually unique. Name matching proves an index
		 * exists; only `Non_unique` proves it makes the increment atomic. A DDL
		 * edit dropping the word UNIQUE would keep the name, keep the columns,
		 * and quietly turn every increment into a duplicate row.
		 */
		$unique = array_values( array_filter( $rows, static fn ( array $r ): bool => 'slot_day_outcome_kind' === $r['Key_name'] ) );

		$this->assertNotEmpty( $unique );

		foreach ( $unique as $part ) {
			$this->assertSame( '0', (string) $part['Non_unique'], 'slot_day_outcome_kind must be UNIQUE or increments duplicate instead of adding.' );
		}

		$this->assertSame(
			array( 'placement_id', 'day_utc', 'outcome', 'opportunity' ),
			array_column( $unique, 'Column_name' ),
			'Deduplication is per placement, day and outcome; a key over anything else counts the wrong things together.'
		);
	}

	public function test_one_outcome_is_counted(): void {
		$this->assertTrue( $this->rollups->add( '2026-09-01', 7, array( Decision_Outcome::REQUEST => 1 ) ) );

		$this->assertSame( 1, $this->row_count() );
		$this->assertSame( array( Decision_Outcome::REQUEST => 1 ), $this->rollups->totals( '2026-09-01', '2026-09-01' ) );
	}

	/**
	 * **The property the option could not have.**
	 *
	 * The row already present was written by something this repository knows
	 * nothing about — which is what a concurrent decision is: the other
	 * request's row appearing between this one's read and its write. A
	 * read-modify-write loses that increment. The unique key adds it.
	 */
	public function test_an_increment_adds_to_a_row_written_by_another_process(): void {
		global $wpdb;

		$table = $this->rollups->table_name();

		// Deliberately not through the repository.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating a write this process did not make.
		$wpdb->insert(
			$table,
			array(
				'day_utc'      => '2026-09-01',
				'placement_id' => 7,
				'outcome'      => Decision_Outcome::REQUEST,
				'events'       => 5,
			),
			array( '%s', '%d', '%s', '%d' )
		);

		$this->assertTrue( $this->rollups->add( '2026-09-01', 7, array( Decision_Outcome::REQUEST => 3 ) ) );

		$this->assertSame( 1, $this->row_count(), 'A concurrent increment must not add a second row.' );
		$this->assertSame(
			array( Decision_Outcome::REQUEST => 8 ),
			$this->rollups->totals( '2026-09-01', '2026-09-01' ),
			'The increment overwrote instead of adding, which is exactly how the option lost counts.'
		);
	}

	/**
	 * And that a whole request costs one statement no matter how much it counts.
	 *
	 * This runs on the public fill path, so the number is the thing that
	 * regresses: a loop that wrote per outcome would pass every correctness
	 * test here and multiply the cost of a busy page.
	 */
	public function test_a_request_costs_one_statement_regardless_of_how_much_it_counts(): void {
		global $wpdb;

		// Warm the memoised table name and any connection setup first, so this
		// measures the write rather than the fixture.
		$this->rollups->add( '2026-09-01', 1, array( Decision_Outcome::REQUEST => 1 ) );

		$many = array(
			Decision_Outcome::REQUEST => 6,
			Decision_Outcome::FILL    => 2,
			\Aggressive\Ads\Domain\No_Fill_Reason::NO_CANDIDATES => 2,
			\Aggressive\Ads\Domain\No_Fill_Reason::TARGETING_MISMATCH => 1,
			\Aggressive\Ads\Domain\No_Fill_Reason::FREQUENCY_CAPPED => 1,
		);

		$before = $wpdb->num_queries;

		$this->assertTrue( $this->rollups->add( '2026-09-01', 42, $many ) );

		$this->assertSame( 1, $wpdb->num_queries - $before, 'Five outcomes must cost one statement, not five.' );
	}

	/**
	 * Cardinality is the domain's to decide, not the caller's.
	 *
	 * The option grew with whatever string it was handed; this is the bound
	 * that replaced that.
	 */
	public function test_an_invented_outcome_is_never_written(): void {
		$this->assertFalse( $this->rollups->add( '2026-09-01', 7, array( 'made_up' => 3 ) ) );
		$this->assertSame( 0, $this->row_count() );

		// And a valid outcome alongside an invalid one stores only the valid one.
		$this->assertTrue(
			$this->rollups->add(
				'2026-09-01',
				7,
				array(
					'made_up'              => 3,
					Decision_Outcome::FILL => 1,
				)
			)
		);
		$this->assertSame( array( Decision_Outcome::FILL => 1 ), $this->rollups->totals( '2026-09-01', '2026-09-01' ) );
	}

	public function test_malformed_input_is_refused_before_the_database(): void {
		$this->assertFalse( $this->rollups->add( '2026-09-01', 0, array( Decision_Outcome::FILL => 1 ) ) );
		$this->assertFalse( $this->rollups->add( 'not-a-day', 7, array( Decision_Outcome::FILL => 1 ) ) );
		$this->assertFalse( $this->rollups->add( '2026-09-01', 7, array( Decision_Outcome::FILL => 0 ) ) );
		$this->assertFalse( $this->rollups->add( '2026-09-01', 7, array() ) );

		$this->assertSame( 0, $this->row_count() );
	}

	/**
	 * Counts are per placement, so one slot's silence is not another's.
	 */
	public function test_placements_are_counted_separately(): void {
		$this->rollups->add( '2026-09-01', 7, array( Decision_Outcome::FILL => 2 ) );
		$this->rollups->add( '2026-09-01', 8, array( Decision_Outcome::FILL => 5 ) );

		$this->assertSame( array( Decision_Outcome::FILL => 2 ), $this->rollups->totals_for_placement( 7, '2026-09-01', '2026-09-01' ) );
		$this->assertSame( array( Decision_Outcome::FILL => 5 ), $this->rollups->totals_for_placement( 8, '2026-09-01', '2026-09-01' ) );
		$this->assertSame( array( Decision_Outcome::FILL => 7 ), $this->rollups->totals( '2026-09-01', '2026-09-01' ) );
	}

	/**
	 * A day is a real boundary, which is the whole reason these are not one total.
	 */
	public function test_days_are_counted_separately_and_ranges_are_inclusive(): void {
		$this->rollups->add( '2026-08-30', 7, array( Decision_Outcome::FILL => 1 ) );
		$this->rollups->add( '2026-08-31', 7, array( Decision_Outcome::FILL => 2 ) );
		$this->rollups->add( '2026-09-01', 7, array( Decision_Outcome::FILL => 4 ) );

		$this->assertSame( array( Decision_Outcome::FILL => 7 ), $this->rollups->totals( '2026-08-30', '2026-09-01' ) );
		$this->assertSame( array( Decision_Outcome::FILL => 3 ), $this->rollups->totals( '2026-08-30', '2026-08-31' ) );
		$this->assertSame( array( Decision_Outcome::FILL => 2 ), $this->rollups->totals( '2026-08-31', '2026-08-31' ), 'A single-day range must include that day.' );
		$this->assertSame( array(), $this->rollups->totals( '2026-07-01', '2026-07-31' ), 'A range with no rows is empty, not an error.' );
	}

	/**
	 * Retention prunes by day, bounded, and — the more valuable half — leaves
	 * everything after the cutoff alone.
	 */
	public function test_purging_is_bounded_and_leaves_later_days_untouched(): void {
		foreach ( array( '2026-08-01', '2026-08-02', '2026-08-03' ) as $day ) {
			$this->rollups->add(
				$day,
				7,
				array(
					Decision_Outcome::REQUEST => 1,
					Decision_Outcome::FILL    => 1,
				)
			);
		}

		$this->assertSame( 6, $this->row_count() );

		$deleted = $this->rollups->purge_through( '2026-08-02', 1 );

		$this->assertSame( 1, $deleted, 'The limit must bound the delete.' );
		$this->assertSame( 5, $this->row_count() );

		$this->rollups->purge_through( '2026-08-02', 100 );

		$survived = $this->rollups->totals( '2026-08-03', '2026-08-03' );
		ksort( $survived );

		$this->assertSame(
			array(
				Decision_Outcome::FILL    => 1,
				Decision_Outcome::REQUEST => 1,
			),
			$survived,
			'Retention deleted past its cutoff.'
		);
		$this->assertSame( array(), $this->rollups->totals( '2026-08-01', '2026-08-02' ) );
	}
}
