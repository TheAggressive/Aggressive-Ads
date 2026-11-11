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
use Aggressive\Ads\Repository\Report_Bucket_Repository;
use WP_UnitTestCase;

/**
 * Same shape as `CreativeDecisionSchemaTest`: the suite rewrites CREATE/DROP
 * into TEMPORARY forms, so the rewrite is lifted around the statements that
 * must be real.
 */
final class ReportBucketSchemaTest extends WP_UnitTestCase {

	private const REPORT_BUCKETS_VERSION = 32;

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
			self::REPORT_BUCKETS_VERSION,
			Schema::DB_VERSION,
			'DB_VERSION is behind the migration this suite asserts, so it would never run.'
		);

		$this->assertArrayHasKey(
			self::REPORT_BUCKETS_VERSION,
			Migration_Map::steps( Plugin::instance()->container() )
		);
	}

	/**
	 * The migration installs the table reports read.
	 */
	public function test_migration_32_installs_the_hourly_table(): void {
		$hourly = new Report_Bucket_Repository();

		$this->really_drop( $hourly->table_name() );

		$this->assertFalse(
			$hourly->table_exists(),
			'The fixture table survived the drop, so the migration below would prove nothing.'
		);

		$this->run_migration( self::REPORT_BUCKETS_VERSION );

		$this->assertTrue(
			( new Report_Bucket_Repository() )->table_exists(),
			'Migration 32 did not install the hourly rollups table.'
		);

		$this->really_drop( $hourly->table_name() );
	}

	/**
	 * The installed table matches what the schema declares.
	 */
	public function test_the_installed_columns_are_the_declared_ones(): void {
		global $wpdb;

		$hourly = new Report_Bucket_Repository();

		$this->really_drop( $hourly->table_name() );
		$this->run_migration( self::REPORT_BUCKETS_VERSION );

		$table = $hourly->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection of this plugin's own table.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		sort( $columns );

		$declared = Schema::report_buckets_columns();
		sort( $declared );

		$this->assertSame( $declared, $columns );

		$this->really_drop( $table );
	}

	/**
	 * Reporting has its own bucket key; daily pacing retains its existing key.
	 */
	public function test_the_unique_key_is_per_bucket(): void {
		global $wpdb;

		$hourly = new Report_Bucket_Repository();

		$this->really_drop( $hourly->table_name() );
		$this->run_migration( self::REPORT_BUCKETS_VERSION );

		$table = $hourly->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection of this plugin's own table.
		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'slot_creative_bucket'" );

		$this->assertNotEmpty( $indexes, 'The reporting bucket unique was not created.' );

		$this->really_drop( $table );
	}
	/** Existing conversion ledgers gain the timestamp-leading index used by backfills. */
	public function test_migration_32_indexes_conversion_occurrence_time(): void {
		global $wpdb;
		$table = ( new \Aggressive\Ads\Repository\Conversion_Repository() )->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreate the pre-v32 index shape before exercising the real migration.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX occurred" );
		$this->run_migration( self::REPORT_BUCKETS_VERSION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Inspect the index installed by the migration, not its DDL string.
		$columns = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'occurred'", 4 );
		$this->assertSame( array( 'occurred_at_ts', 'id' ), $columns );
	}

	/** Owner recovery must also read only the day currently being rebuilt. */
	public function test_migration_32_indexes_daily_ownership_evidence_by_day(): void {
		global $wpdb;
		$table = ( new \Aggressive\Ads\Repository\Rollup_Repository() )->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreate the pre-v32 daily index shape.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX day" );
		$this->run_migration( self::REPORT_BUCKETS_VERSION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verify the actual migrated index.
		$columns = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'day'", 4 );
		$this->assertSame( array( 'day_utc' ), $columns );
	}
}
