<?php
/**
 * Migration 23: decision counters, and the end of the option they replace.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Workflow\Decision_Metrics;
use WP_UnitTestCase;

/**
 * **This suite cannot prove a table was created by dropping it first**, the way
 * an ordinary test would: `WP_UnitTestCase` rewrites `CREATE TABLE` and
 * `DROP TABLE` into their `TEMPORARY` forms, so a repository's `drop_table()`
 * drops nothing and `SHOW TABLES` cannot see what the suite created.
 *
 * The rewrite is a `query` filter, so it can be lifted around the statements
 * that must be real — the shape `ConversionSchemaTest` records, and the reason
 * the migration step is invoked directly rather than through `maybe_upgrade()`,
 * whose option-based lock survives the transaction rollback in the object cache
 * and silently disables a later test's upgrade.
 */
final class DecisionRollupSchemaTest extends WP_UnitTestCase {

	private const DECISION_ROLLUPS_VERSION = 23;

	/**
	 * Runs one migration step with the temporary-table rewrite lifted.
	 *
	 * @param int $version Migration version.
	 */
	private function run_migration( int $version ): void {
		$steps = Migration_Map::steps( Plugin::instance()->container() );

		$this->assertArrayHasKey( $version, $steps, "Migration {$version} is not registered, so nothing would run on upgrade." );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

		try {
			$steps[ $version ]();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		}
	}

	/**
	 * Really drops a table, past the suite's rewrite.
	 *
	 * @param string $table Fully prefixed table name.
	 */
	private function really_drop( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deliberately un-rewritten drop; see the class docblock.
		$wpdb->query( "/* real drop */ DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * The step must be registered at the version that declares it, or a site
	 * upgrades its recorded version past a migration that never ran.
	 */
	public function test_the_migration_is_registered_at_the_version_that_declares_it(): void {
		$this->assertSame(
			self::DECISION_ROLLUPS_VERSION,
			Schema::DB_VERSION,
			'DB_VERSION and the migration this suite asserts have diverged.'
		);

		$this->assertArrayHasKey(
			self::DECISION_ROLLUPS_VERSION,
			Migration_Map::steps( Plugin::instance()->container() )
		);
	}

	public function test_migration_23_installs_the_counter_table(): void {
		$rollups = new Decision_Rollup_Repository();

		$this->really_drop( $rollups->table_name() );

		$this->assertFalse(
			$rollups->table_exists(),
			'The fixture table survived the drop, so the migration below would prove nothing.'
		);

		$this->run_migration( self::DECISION_ROLLUPS_VERSION );

		$this->assertTrue( $rollups->table_exists(), 'Migration 23 did not install the counter table.' );

		$this->really_drop( $rollups->table_name() );
	}

	/**
	 * The installed table matches what the schema declares.
	 *
	 * Asserted against the declaration rather than a hand-written list, so a
	 * column added to one and not the other fails here instead of at a write.
	 */
	public function test_the_installed_table_matches_the_declared_schema(): void {
		global $wpdb;

		$rollups = new Decision_Rollup_Repository();
		$table   = $rollups->table_name();

		$this->really_drop( $table );
		$this->run_migration( self::DECISION_ROLLUPS_VERSION );

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

		$this->really_drop( $table );
	}

	/**
	 * Running it twice changes nothing — a repair install must be safe.
	 */
	public function test_installing_twice_changes_nothing(): void {
		$rollups = new Decision_Rollup_Repository();

		$this->really_drop( $rollups->table_name() );
		$this->run_migration( self::DECISION_ROLLUPS_VERSION );
		$this->run_migration( self::DECISION_ROLLUPS_VERSION );

		$this->assertTrue( $rollups->table_exists() );

		$this->really_drop( $rollups->table_name() );
	}

	/**
	 * **The option is deleted, and that loss is the intended behaviour.**
	 *
	 * It held one unbounded running total with no time dimension, so there is
	 * no day to attribute any of it to. Carrying the number forward would put a
	 * total of unknown age beside per-day rows and invite somebody to add them
	 * together.
	 */
	public function test_migration_23_removes_the_option_it_replaces(): void {
		update_option( Decision_Metrics::LEGACY_OPTION_EXCLUSIONS, array( 'aggregate' => array( 'no_fill' => 41 ) ), false );

		$this->assertNotFalse(
			get_option( Decision_Metrics::LEGACY_OPTION_EXCLUSIONS, false ),
			'Without the option present this test proves nothing.'
		);

		$this->run_migration( self::DECISION_ROLLUPS_VERSION );

		$this->assertFalse(
			get_option( Decision_Metrics::LEGACY_OPTION_EXCLUSIONS, false ),
			'The replaced option outlived the migration.'
		);

		$this->really_drop( ( new Decision_Rollup_Repository() )->table_name() );
	}
}
