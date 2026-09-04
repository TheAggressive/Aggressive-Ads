<?php
/**
 * The opportunity column, and the unique that had to move for it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use WP_UnitTestCase;

/**
 * A schema assertion is worthless until the old state is recreated.
 *
 * `dbDelta` adds an index and never drops one, so the failure this guards is
 * an upgraded site keeping `slot_day_outcome` — which enforces one row per
 * outcome per day and makes a placement's first refresh collide with its page
 * opportunity. The counter then stops advancing for whichever arrives second,
 * with no error anywhere.
 *
 * A fresh table never had that index, so a test that merely asserts it is
 * absent passes over a migration that does nothing. Each of these puts the old
 * index back first.
 */
final class DecisionGrainSchemaTest extends WP_UnitTestCase {

	/**
	 * The repository under test.
	 *
	 * @var Decision_Rollup_Repository
	 */
	private Decision_Rollup_Repository $rollups;

	/**
	 * Resolves the repository per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->rollups = Plugin::instance()->container()->get( Decision_Rollup_Repository::class );
	}

	/**
	 * Index names currently on the table.
	 *
	 * @return list<string>
	 */
	private function index_names(): array {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		return is_array( $rows ) ? array_values( array_unique( array_column( $rows, 'Key_name' ) ) ) : array();
	}

	/** The column exists and the widened unique is the one in force. */
	public function test_the_table_carries_the_opportunity_grain(): void {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		$this->assertContains( 'opportunity', (array) $columns );
		$this->assertContains( 'slot_day_outcome_kind', $this->index_names() );
	}

	/**
	 * **The superseded unique is dropped, proven against a table that has it.**
	 *
	 * @return void
	 */
	public function test_the_pre_grain_unique_is_dropped_when_it_is_present(): void {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreating the pre-P15 state this migration exists to repair.
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY slot_day_outcome (placement_id,day_utc,outcome)" );

		$this->assertContains(
			'slot_day_outcome',
			$this->index_names(),
			'The fixture failed to recreate the old state, so what follows would prove nothing.'
		);

		$this->rollups->install_table();

		$this->assertNotContains(
			'slot_day_outcome',
			$this->index_names(),
			'The pre-P15 unique survived, so a refresh cannot hold its own row.'
		);
		$this->assertContains( 'slot_day_outcome_kind', $this->index_names() );
	}

	/**
	 * A page opportunity and a refresh of it hold separate rows.
	 *
	 * This is what the whole key change is for, and it fails on insert rather
	 * than quietly if the old unique is still there.
	 *
	 * @return void
	 */
	public function test_a_page_and_a_refresh_do_not_share_a_row(): void {
		global $wpdb;

		$table = $this->rollups->table_name();

		foreach ( array( 'page', 'refresh' ) as $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Writing this plugin's own counter table in a test.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (day_utc, placement_id, outcome, opportunity, events)
					 VALUES (%s, %d, %s, %s, %d)
					 ON DUPLICATE KEY UPDATE events = events + VALUES(events)",
					'2026-09-04',
					4242,
					'fill',
					$kind,
					1
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reading this plugin's own counter table in a test.
		$rows = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE placement_id = %d AND day_utc = %s", 4242, '2026-09-04' )
		);

		$this->assertSame( 2, $rows, 'A refresh was merged into the page opportunity beside it.' );
	}
}
