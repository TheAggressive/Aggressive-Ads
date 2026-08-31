<?php
/**
 * The P2 creative tables install, upgrade and uninstall.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use WP_UnitTestCase;

/**
 * Schema only. Nothing reads these tables yet, and that is the point.
 *
 * A table added in one release and filled in another is the safe order: a site
 * upgrading to database version 14 gains two empty tables and no behaviour, so
 * there is no window in which serving reads a backfill that has not finished.
 * The migration that fills them will ship with the code that reads them.
 *
 * What must be true now is narrow and worth asserting anyway, because every
 * part of it is destructive or load-bearing later: the tables install, they
 * install *idempotently* (dbDelta runs on every upgrade), the columns the code
 * declares are the columns MySQL actually created, the compatibility key really
 * does permit many rows while constraining one, and a destructive uninstall
 * removes both.
 */
final class CreativeModelSchemaTest extends WP_UnitTestCase {

	/**
	 * Creative asset persistence.
	 *
	 * @var Creative_Asset_Repository
	 */
	private Creative_Asset_Repository $assets;

	/**
	 * Creative assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	public function set_up(): void {
		parent::set_up();

		$this->assets      = new Creative_Asset_Repository();
		$this->assignments = new Creative_Assignment_Repository();

		$this->assets->install_table();
		$this->assignments->install_table();

		// A lock left behind by any earlier upgrade makes maybe_upgrade() a
		// no-op, which would present as "the migration did not run".
		delete_option( Installer::OPTION_UPGRADE_LOCK );
	}

	public function tear_down(): void {
		delete_option( Installer::OPTION_UPGRADE_LOCK );

		// Leave them installed for whatever runs next: this is DDL, and the
		// suite's transaction rollback does not undo it.
		$this->assets->install_table();
		$this->assignments->install_table();

		parent::tear_down();
	}

	/**
	 * Column names MySQL reports for a table.
	 *
	 * @param string $table Table name.
	 * @return array<int, string>
	 */
	private function actual_columns( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test introspection of this plugin's own table.
		$rows = $wpdb->get_col( "DESC {$table}", 0 );

		sort( $rows );

		return $rows;
	}

	public function test_both_tables_install(): void {
		$this->assertTrue( $this->assets->table_exists() );
		$this->assertTrue( $this->assignments->table_exists() );
	}

	/**
	 * The declared columns are the columns that exist.
	 *
	 * `dbDelta` is forgiving in ways that hide mistakes — a malformed line is
	 * skipped rather than raising — so a column can be declared in PHP and
	 * absent from MySQL with nothing saying so until a write fails.
	 */
	public function test_declared_columns_match_the_created_tables(): void {
		/*
		 * From a dropped table, deliberately.
		 *
		 * `dbDelta` adds and modifies columns but never drops one, so against a
		 * table left over from an earlier run this assertion cannot see a column
		 * *removed* from the DDL — the old column survives and the comparison
		 * matches. Sabotaging the DDL proved exactly that: deleting a column
		 * left this test green. The fixture has to be the schema under test.
		 */
		$this->assets->drop_table();
		$this->assignments->drop_table();
		$this->assets->install_table();
		$this->assignments->install_table();

		$expected_assets = Schema::creative_assets_columns();
		sort( $expected_assets );

		$this->assertSame(
			$expected_assets,
			$this->actual_columns( $this->assets->table_name() ),
			'The asset table MySQL created is not the one the code declares.'
		);

		$expected_assignments = Schema::creative_assignments_columns();
		sort( $expected_assignments );

		$this->assertSame(
			$expected_assignments,
			$this->actual_columns( $this->assignments->table_name() ),
			'The assignment table MySQL created is not the one the code declares.'
		);
	}

	/**
	 * Installing twice changes nothing.
	 *
	 * `dbDelta` runs on every upgrade and on every repair, so this is the
	 * ordinary path rather than an edge case.
	 */
	public function test_installing_twice_is_idempotent(): void {
		$before = $this->actual_columns( $this->assignments->table_name() );

		$this->assignments->install_table();
		$this->assignments->install_table();

		$this->assertSame( $before, $this->actual_columns( $this->assignments->table_name() ) );
	}

	/**
	 * The compatibility key constrains one row and permits the rest.
	 *
	 * This is the nullable-unique trick the line-item table already uses, and
	 * it is the whole reason concurrent lazy creation and a background backfill
	 * can both run without coordinating: the database refuses the duplicate
	 * rather than the application remembering to check.
	 *
	 * Both halves matter. If NULLs collided, a line item could hold exactly one
	 * creative — which is the P1 limitation P2 exists to remove.
	 */
	public function test_the_compatibility_key_allows_many_rows_but_one_default(): void {
		global $wpdb;

		/*
		 * **What this test can and cannot see.**
		 *
		 * It asserts the behaviour the unique key must produce, against the
		 * table as the suite actually built it. It does *not* guard the DDL:
		 * changing `compat_key` to `NOT NULL` in the schema and running this
		 * leaves it green, and the reason is the environment rather than the
		 * assertion. `WP_UnitTestCase` creates per-test tables as TEMPORARY,
		 * which `SHOW TABLES` does not list, so `dbDelta` cannot see the table
		 * it is meant to reconcile and the column definition never moves.
		 * Measured with a probe, not assumed.
		 *
		 * So a future reader should not take a green run here as evidence that
		 * the nullable column survived a schema edit. The real protection for
		 * that is `test_declared_columns_match_the_created_tables()`, plus a
		 * fresh install on a real site.
		 */
		$this->assignments->drop_table();
		$this->assignments->install_table();

		$table = $this->assignments->table_name();
		$row   = static function ( $compat ) {
			return array(
				'line_item_id' => 7,
				'placement_id' => 9,
				'compat_key'   => $compat,
			);
		};

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test write to this plugin's own table.
		$this->assertSame( 1, $wpdb->insert( $table, $row( 1 ) ), 'The first compatibility row was refused.' );

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test write to this plugin's own table.
		$duplicate = $wpdb->insert( $table, $row( 1 ) );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse(
			$duplicate,
			'A second compatibility row for the same line item and placement was accepted.'
		);

		// And the negative half: ordinary rows are unconstrained, which is the
		// many-creatives-per-placement behaviour P2 exists to allow.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test write to this plugin's own table.
		$this->assertSame( 1, $wpdb->insert( $table, $row( null ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test write to this plugin's own table.
		$this->assertSame( 1, $wpdb->insert( $table, $row( null ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test read of this plugin's own table.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE line_item_id = 7 AND placement_id = 9" );

		$this->assertSame( 3, $total, 'A line item and placement must hold many assignments.' );
	}

	/** The schema version moved, or nothing upgrades an existing site. */
	public function test_the_schema_version_covers_the_new_tables(): void {
		$this->assertGreaterThanOrEqual(
			14,
			Schema::DB_VERSION,
			'The P2 tables are declared but no database version installs them.'
		);
	}

	/**
	 * An upgrade from before P2 installs both tables.
	 *
	 * The assembly, not the parts — the same gap `LineItemUpgradeWiringTest`
	 * exists to close. A migration registered against the wrong version, or
	 * calling the wrong method, passes every component test.
	 */
	public function test_an_upgrade_from_before_p2_installs_both_tables(): void {
		$this->assets->drop_table();
		$this->assignments->drop_table();

		$this->assertFalse( $this->assets->table_exists(), 'The fixture still had the asset table.' );

		update_option( Installer::OPTION_DB_VERSION, 13, true );

		Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();

		/*
		 * Asked through fresh instances, deliberately.
		 *
		 * `table_exists()` memoises, and `drop_table()` sets that memo to false.
		 * The migration installs through its own repository objects, so the
		 * instances this test dropped would still answer false however well the
		 * upgrade worked — the first draft of this test failed for exactly that
		 * reason and looked like a broken migration.
		 */
		$this->assertTrue(
			( new Creative_Asset_Repository() )->table_exists(),
			'Migration 14 did not install the asset table.'
		);
		$this->assertTrue(
			( new Creative_Assignment_Repository() )->table_exists(),
			'Migration 14 did not install the assignment table.'
		);
		$this->assertSame( Schema::DB_VERSION, Installer::stored_db_version() );
	}

	/**
	 * A destructive uninstall removes both.
	 *
	 * Asserted here rather than by running the uninstaller, which drops every
	 * plugin table and cannot be rolled back by the suite's transaction — the
	 * mistake `UninstallOptionsTest` records. What matters is that the
	 * uninstaller *names* them: a table installed and never dropped is one
	 * tenant's rows left behind on a deleted site.
	 */
	public function test_the_uninstaller_names_both_tables(): void {
		$source = (string) file_get_contents( AGGR_PLUGIN_DIR . 'inc/Install/class-uninstaller.php' );

		$this->assertStringContainsString(
			'Creative_Assignment_Repository() )->drop_table()',
			$source,
			'Destructive uninstall does not drop the assignment table.'
		);
		$this->assertStringContainsString(
			'Creative_Asset_Repository() )->drop_table()',
			$source,
			'Destructive uninstall does not drop the asset table.'
		);
	}
	/**
	 * **Migration 22 adds `operator_paused` to a table that predates it.**
	 *
	 * The column has to arrive on an *existing* table, which is the only case
	 * that matters: a fresh install gets it from the DDL and would pass this
	 * whatever the migration did. So the column is dropped first — the same
	 * reason `dbDelta`'s index behaviour is asserted by recreating the old key
	 * before checking it is gone.
	 *
	 * Without it, an upgraded site keeps a table with no way to tell a pause a
	 * person made from a pause its campaign made, and `Assignment_Projection`
	 * reads a column that is not there.
	 *
	 * @return void
	 */
	public function test_migration_22_adds_the_operator_pause_column(): void {
		global $wpdb;

		$this->assignments->install_table();

		$table = $this->assignments->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Removing the column the migration under test restores.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN operator_paused" );

		$this->assertNotContains(
			'operator_paused',
			$this->actual_columns( $table ),
			'The fixture still has the column, so the migration below would prove nothing.'
		);

		$steps = \Aggressive\Ads\Install\Migration_Map::steps( Plugin::instance()->container() );

		$this->assertArrayHasKey( 22, $steps, 'No database version installs the operator-pause column.' );

		$steps[22]();

		$this->assertContains(
			'operator_paused',
			$this->actual_columns( $table ),
			'Migration 22 did not add the column an upgraded site needs.'
		);
	}
}
