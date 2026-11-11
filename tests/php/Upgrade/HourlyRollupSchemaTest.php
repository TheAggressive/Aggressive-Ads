<?php
/**
 * Migration 32: hourly rollups so a report can be cut on a local day.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Hourly_Rollup_Repository;
use WP_UnitTestCase;

/**
 * Same shape as `CreativeDecisionSchemaTest`: the suite rewrites CREATE/DROP
 * into TEMPORARY forms, so the rewrite is lifted around the statements that
 * must be real.
 */
final class HourlyRollupSchemaTest extends WP_UnitTestCase {

	private const HOURLY_ROLLUPS_VERSION = 32;

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
	 * The step is registered at the version that declares it.
	 */
	public function test_the_migration_is_registered_at_the_version_that_declares_it(): void {
		$this->assertGreaterThanOrEqual(
			self::HOURLY_ROLLUPS_VERSION,
			Schema::DB_VERSION,
			'DB_VERSION is behind the migration this suite asserts, so it would never run.'
		);

		$this->assertArrayHasKey(
			self::HOURLY_ROLLUPS_VERSION,
			Migration_Map::steps( Plugin::instance()->container() )
		);
	}

	/**
	 * The migration installs the table reports read.
	 */
	public function test_migration_32_installs_the_hourly_table(): void {
		$hourly = new Hourly_Rollup_Repository();

		$this->really_drop( $hourly->table_name() );

		$this->assertFalse(
			$hourly->table_exists(),
			'The fixture table survived the drop, so the migration below would prove nothing.'
		);

		$this->run_migration( self::HOURLY_ROLLUPS_VERSION );

		$this->assertTrue(
			( new Hourly_Rollup_Repository() )->table_exists(),
			'Migration 32 did not install the hourly rollups table.'
		);

		$this->really_drop( $hourly->table_name() );
	}

	/**
	 * The installed table matches what the schema declares.
	 */
	public function test_the_installed_columns_are_the_declared_ones(): void {
		global $wpdb;

		$hourly = new Hourly_Rollup_Repository();

		$this->really_drop( $hourly->table_name() );
		$this->run_migration( self::HOURLY_ROLLUPS_VERSION );

		$table = $hourly->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection of this plugin's own table.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		sort( $columns );

		$declared = Schema::hourly_rollups_columns();
		sort( $declared );

		$this->assertSame( $declared, $columns );

		$this->really_drop( $table );
	}

	/**
	 * The unique key is per hour, not per day. Growing the daily unique by an
	 * hour would have turned every pacing read into a twenty-four-row sum.
	 */
	public function test_the_unique_key_is_per_hour(): void {
		global $wpdb;

		$hourly = new Hourly_Rollup_Repository();

		$this->really_drop( $hourly->table_name() );
		$this->run_migration( self::HOURLY_ROLLUPS_VERSION );

		$table = $hourly->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection of this plugin's own table.
		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'slot_creative_hour'" );

		$this->assertNotEmpty( $indexes, 'The hourly unique was not created.' );

		$this->really_drop( $table );
	}
}
