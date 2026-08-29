<?php
/**
 * The P12 conversion ledger installs and upgrades.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Conversion_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

/**
 * Schema only, and that is deliberate — the same staging version 14 used.
 *
 * A site upgrading to database version 18 gains one empty table, one nullable
 * column and no behaviour. The code that writes conversions ships with the code
 * that reads them, so there is never a window in which a report is assembled
 * from a ledger nothing has finished filling.
 *
 * **What this suite cannot assert, and why.** `WP_UnitTestCase` filters every
 * query: `CREATE TABLE` becomes `CREATE TEMPORARY TABLE` and `DROP TABLE`
 * becomes `DROP TEMPORARY TABLE`. So a repository's `drop_table()` drops
 * nothing here — it asks MySQL to drop a temporary table that never existed,
 * MySQL obliges, and the real table is still sitting there — and `SHOW TABLES`
 * cannot see a table this suite created, because it is temporary. A
 * "drop it, upgrade, assert it came back" test therefore proves nothing about
 * the migration: it passes over a table that was never removed.
 *
 * That was written first, and it failed on its own fixture. Forcing real DDL to
 * make it work was worse — it left a real `aggr_rollups` shadowing the
 * temporary one, and five assertions in `ReleaseUpgradePathTest` started
 * reading an empty table. Real and temporary DDL cannot be mixed in one
 * session.
 *
 * So this asserts what is honestly provable without that: the migration is
 * registered at the version that declares it, the schema version is the one the
 * walker will actually reach, dbDelta is idempotent, and the shapes MySQL
 * created are the shapes the code declares. `SHOW COLUMNS` and `SHOW INDEX` do
 * resolve temporary tables, which is what makes those assertions real. The
 * end-to-end walk from a published release to `DB_VERSION` is already covered
 * by `ReleaseUpgradePathTest`.
 */
final class ConversionSchemaTest extends WP_UnitTestCase {

	/** The database version that installs the conversion ledger. */
	private const CONVERSIONS_VERSION = 18;

	/**
	 * Conversion ledger persistence.
	 *
	 * @var Conversion_Repository
	 */
	private Conversion_Repository $conversions;

	/**
	 * Reporting projection.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	public function set_up(): void {
		parent::set_up();

		$this->conversions = new Conversion_Repository();
		$this->rollups     = new Rollup_Repository();

		$this->conversions->install_table();
	}

	/**
	 * The migration is registered, and registered at the version it claims.
	 *
	 * This is the failure this file exists to catch. A step written against the
	 * wrong key, or a `DB_VERSION` left unbumped, passes every component test in
	 * the repository and leaves a real site without the table — and nothing else
	 * in the build would say so.
	 */
	public function test_the_migration_is_registered_at_the_version_that_declares_it(): void {
		$steps = Migration_Map::steps( Plugin::instance()->container() );

		$this->assertArrayHasKey(
			self::CONVERSIONS_VERSION,
			$steps,
			'The conversion ledger is declared but no database version installs it.'
		);

		$this->assertSame(
			self::CONVERSIONS_VERSION,
			max( array_keys( $steps ) ),
			'Migration 18 must be the newest step, or a later one is missing from this assertion.'
		);

		$this->assertSame(
			max( array_keys( $steps ) ),
			Schema::DB_VERSION,
			'DB_VERSION and the highest migration disagree, so the walker stops before the last step.'
		);
	}


	/**
	 * Migration 18 installs the ledger.
	 *
	 * **This is the assertion the rest of the file could not make, and it is the
	 * one that matters most.** `Upgrader::run()` calls `install()` only when the
	 * stored version is 0. An existing site runs migrations and nothing else, so
	 * migration 18 is the *only* thing that creates this table on every site
	 * that already has the plugin. Deleting that one line would leave every
	 * upgrading install without a conversion ledger — and every other test in
	 * this file passed with it deleted, because the suite's table was never
	 * actually gone.
	 *
	 * **The step is invoked directly rather than through `maybe_upgrade()`.**
	 * That is not a shortcut. The walker takes an option-based lock, and an
	 * option written in one test survives this suite's transaction rollback in
	 * the object cache — so a second call to `maybe_upgrade()` in a later test
	 * finds the lock held and silently upgrades nothing. Doing it the obvious
	 * way left `ReleaseUpgradePathTest` stranded on its starting version with
	 * five failures that pointed nowhere near this file. The walker itself is
	 * already covered by `UpgraderTest` and `ReleaseUpgradePathTest`; what is
	 * unproven, and proven here, is what step 18 does.
	 *
	 * The drop is prefixed with a comment because `_drop_temporary_tables` trims
	 * the query and rewrites it only when it *starts with* `DROP TABLE`, so a
	 * leading comment gets a genuine drop past it. The create is un-rewritten
	 * for the duration of the step, because a table dbDelta creates while that
	 * filter is on is temporary and `SHOW TABLES` cannot see it — the migration
	 * would look broken when it worked. Both are confined to this one table,
	 * which nothing else in the suite touches, and the table left behind is real:
	 * the state the suite began in.
	 */
	public function test_migration_18_installs_the_ledger(): void {
		global $wpdb;

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deliberately un-rewritten drop; see the docblock.
		$wpdb->query( "/* real drop */ DROP TABLE IF EXISTS {$table}" );

		$this->assertFalse(
			( new Conversion_Repository() )->table_exists(),
			'The fixture table survived the drop, so the migration below would prove nothing.'
		);

		$steps = Migration_Map::steps( Plugin::instance()->container() );

		$this->assertArrayHasKey( self::CONVERSIONS_VERSION, $steps );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

		try {
			$steps[ self::CONVERSIONS_VERSION ]();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		}

		$this->assertTrue(
			( new Conversion_Repository() )->table_exists(),
			'Migration 18 did not install the conversion ledger, so no upgrading site would have one.'
		);
	}

	/**
	 * The ledger's shape is the shape the schema declares.
	 *
	 * Asserted through `SHOW COLUMNS` and `SHOW INDEX` rather than a row count,
	 * because a column this code names and MySQL does not have is a write that
	 * fails at runtime and nowhere earlier.
	 */
	public function test_the_installed_table_matches_the_declared_schema(): void {
		global $wpdb;

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		$this->assertNotEmpty( $columns, 'The fixture table must exist before its shape is asserted.' );

		sort( $columns );
		$declared = Schema::conversions_columns();
		sort( $declared );

		$this->assertSame( $declared, $columns );
	}

	/**
	 * The rollup column arrives, and arrives nullable.
	 *
	 * Nullability is the assertion that matters. A `NOT NULL DEFAULT 0` column
	 * would install just as cleanly and would report every day before P12 as a
	 * campaign that converted nobody — a different, and more alarming, claim
	 * than "nobody was counting".
	 */
	public function test_the_rollup_gains_a_nullable_conversions_column(): void {
		global $wpdb;

		$this->rollups->install_table();

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'conversions'", ARRAY_A );

		$this->assertIsArray( $column, 'The rollup projection has no conversions column.' );
		$this->assertSame( 'YES', $column['Null'], 'History must be able to say "not measured".' );
		$this->assertNull( $column['Default'] );
	}

	/**
	 * Installing twice must be a no-op, because dbDelta runs on every upgrade.
	 *
	 * Asserted as a column and index count rather than as "it did not throw": a
	 * repeated dbDelta that added a duplicate index would not throw either, and
	 * dbDelta never drops one, so it would stay.
	 */
	public function test_installing_twice_changes_nothing(): void {
		global $wpdb;

		$this->conversions->install_table();
		$this->conversions->install_table();

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		$this->assertCount( count( Schema::conversions_columns() ), $columns );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names = array_values( array_unique( array_column( $rows, 'Key_name' ) ) );

		$this->assertCount(
			count( Schema::conversions_index_names() ),
			$names,
			'A repeated dbDelta added an index. It adds and never drops, so this would stay.'
		);
	}
}
