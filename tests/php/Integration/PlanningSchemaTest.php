<?php
/**
 * The planning tables really are what their definitions say.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Planning_Schema;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Every other custom table in this plugin declares its columns and index names
 * beside its DDL, and `InstallerTest` compares those declarations against what
 * the site actually built. The two P16 tables shipped without either, which is
 * an inconsistency rather than a decision — found by asking why seven of the
 * nine `*_index_names()` methods had consumers and two did not.
 *
 * The gap matters because `dbDelta` is forgiving. It adds and never drops, so a
 * table that has drifted from its definition keeps working and keeps being
 * wrong: a lost index degrades a capacity check into a table scan without
 * failing anything, and a column added to the DDL but never applied to an
 * upgraded site fails only on the write that needs it.
 *
 * **The tables are really dropped and rebuilt first, and that is the whole
 * difference between this test and a decorative one.** The first version
 * installed over the table `Installer::install()` had already made, so `dbDelta`
 * had nothing to add and the assertions described a table its own DDL had not
 * built — corrupting the DDL's unique key into an ordinary one changed
 * nothing and the suite stayed green. `WP_UnitTestCase` rewrites `CREATE`
 * and `DROP` into their `TEMPORARY` forms, so the rewrite is lifted around the
 * statements that have to be real, the shape `DecisionRollupSchemaTest`
 * records.
 */
final class PlanningSchemaTest extends WP_UnitTestCase {

	/**
	 * Snapshot storage.
	 *
	 * @var Forecast_Repository
	 */
	private Forecast_Repository $forecasts;

	/**
	 * Reservation ledger.
	 *
	 * @var Reservation_Repository
	 */
	private Reservation_Repository $reservations;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$container          = Plugin::instance()->container();
		$this->forecasts    = $container->get( Forecast_Repository::class );
		$this->reservations = $container->get( Reservation_Repository::class );

		// Rebuilt from the definitions under test, not inherited from install.
		$this->rebuild( $this->forecasts->table_name(), array( $this->forecasts, 'install_table' ) );
		$this->rebuild( $this->reservations->table_name(), array( $this->reservations, 'install_table' ) );
	}

	/**
	 * Drops a table for real and builds it again from its own DDL.
	 *
	 * @param string   $table   Fully prefixed table name.
	 * @param callable $install The repository's installer.
	 */
	private function rebuild( string $table, callable $install ): void {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deliberately un-rewritten drop; see the class docblock.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

			$install();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Column names the site really built, sorted.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return array<int, string>
	 */
	private function live_columns( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration schema assertion against a prefix-derived name.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		sort( $columns );

		return $columns;
	}

	/**
	 * Index names the site really built, in the order MySQL reports them.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return array<int, string>
	 */
	private function live_indexes( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration schema assertion against a prefix-derived name.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		return array_values( array_unique( array_column( is_array( $rows ) ? $rows : array(), 'Key_name' ) ) );
	}

	public function test_the_forecast_table_matches_its_definition(): void {
		$declared = Planning_Schema::forecasts_columns();

		sort( $declared );

		$this->assertSame( $declared, $this->live_columns( $this->forecasts->table_name() ) );
		$this->assertSame( Planning_Schema::forecasts_index_names(), $this->live_indexes( $this->forecasts->table_name() ) );
	}

	public function test_the_reservation_table_matches_its_definition(): void {
		$declared = Planning_Schema::reservations_columns();

		sort( $declared );

		$this->assertSame( $declared, $this->live_columns( $this->reservations->table_name() ) );
		$this->assertSame( Planning_Schema::reservations_index_names(), $this->live_indexes( $this->reservations->table_name() ) );
	}

	/**
	 * The unique key is the concurrency control, not a convenience.
	 *
	 * Named separately from the list above because losing it is silent: inserts
	 * keep succeeding and two staff re-forecasting one window both get a row,
	 * so the version stops meaning anything and the older snapshot a publisher
	 * was quoted can be duplicated rather than preserved.
	 */
	public function test_a_forecast_version_cannot_be_claimed_twice(): void {
		global $wpdb;

		$table = $this->forecasts->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration schema assertion against a prefix-derived name.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'slot_window_version'", ARRAY_A );

		$this->assertNotEmpty( $rows, 'The unique key is missing, so a version is no longer unique.' );
		$this->assertSame(
			0,
			(int) $rows[0]['Non_unique'],
			'The key exists and is not unique, which is worse than missing: it looks like the guard is there.'
		);
		$this->assertSame(
			array( 'placement_id', 'opportunity', 'window_start', 'window_end', 'version' ),
			array_column( $rows, 'Column_name' ),
			'A narrower key would refuse legitimate forecasts; a wider one would let a version repeat.'
		);
	}

	/**
	 * The orphaned pending-edit provenance is cleared from an upgraded site.
	 *
	 * Two meta keys recorded who proposed a change and when, duplicating what
	 * the audit row for the same event already held, and nothing read either.
	 * Deleting the code without deleting the rows would leave the next reader
	 * a table full of evidence that a feature exists.
	 */
	public function test_the_orphaned_pending_edit_meta_is_removed(): void {
		$campaign = (int) self::factory()->post->create();

		update_post_meta( $campaign, '_aggr_pending_edits_at', time() );
		update_post_meta( $campaign, '_aggr_pending_edits_by', 7 );

		$this->assertNotSame( '', (string) get_post_meta( $campaign, '_aggr_pending_edits_at', true ) );

		$steps = Migration_Map::steps( Plugin::instance()->container() );

		$this->assertArrayHasKey( 29, $steps, 'Without a registered step the rows stay on every installed site.' );

		$steps[29]();

		$this->assertSame( '', (string) get_post_meta( $campaign, '_aggr_pending_edits_at', true ) );
		$this->assertSame( '', (string) get_post_meta( $campaign, '_aggr_pending_edits_by', true ) );
	}

	/**
	 * Both tables are declared at a version an installed site will reach.
	 */
	public function test_both_tables_have_a_registered_migration(): void {
		$this->assertTrue( $this->forecasts->table_exists() );
		$this->assertTrue( $this->reservations->table_exists() );
		$this->assertGreaterThanOrEqual(
			28,
			Schema::DB_VERSION,
			'The reservation ledger installs at version 28; a lower current version means no site would ever run it.'
		);
	}
}
