<?php
/**
 * Migration 31: the table that keeps what a reviewer decided.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Decision_Repository;
use WP_UnitTestCase;

/**
 * **This suite cannot prove a table was created by dropping it first** the way
 * an ordinary test would: `WP_UnitTestCase` rewrites `CREATE TABLE` and
 * `DROP TABLE` into their `TEMPORARY` forms, so a repository's `drop_table()`
 * drops nothing and `SHOW TABLES` cannot see what the suite created. The
 * rewrite is a `query` filter, so it is lifted around the statements that must
 * be real — the shape `DecisionRollupSchemaTest` records, and the reason the
 * migration step is invoked directly rather than through `maybe_upgrade()`.
 */
final class CreativeDecisionSchemaTest extends WP_UnitTestCase {

	private const CREATIVE_DECISIONS_VERSION = 31;

	/**
	 * Runs one migration step with the temporary-table rewrite lifted.
	 *
	 * @param int $version Migration version.
	 * @return void
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
	 * @return void
	 */
	private function really_drop( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deliberately un-rewritten drop; see the class docblock.
		$wpdb->query( "/* real drop */ DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * The step is registered at the version that declares it.
	 *
	 * @return void
	 */
	public function test_the_migration_is_registered_at_the_version_that_declares_it(): void {
		$this->assertGreaterThanOrEqual(
			self::CREATIVE_DECISIONS_VERSION,
			Schema::DB_VERSION,
			'DB_VERSION is behind the migration this suite asserts, so it would never run.'
		);

		$this->assertArrayHasKey(
			self::CREATIVE_DECISIONS_VERSION,
			Migration_Map::steps( Plugin::instance()->container() )
		);
	}

	/**
	 * The migration installs the table the history is read from.
	 *
	 * @return void
	 */
	public function test_migration_31_installs_the_decision_table(): void {
		$decisions = new Creative_Decision_Repository();

		$this->really_drop( $decisions->table_name() );

		$this->assertFalse(
			$decisions->table_exists(),
			'The fixture table survived the drop, so the migration below would prove nothing.'
		);

		$this->run_migration( self::CREATIVE_DECISIONS_VERSION );

		/*
		 * A fresh repository, because the answer is cached per instance: the
		 * one above was asked before the migration and would go on saying no.
		 * The cache is worth having — every read consults it — and this is
		 * what testing around it costs.
		 */
		$this->assertTrue(
			( new Creative_Decision_Repository() )->table_exists(),
			'Migration 31 did not install the decision table.'
		);

		$this->really_drop( $decisions->table_name() );
	}

	/**
	 * The installed table matches what the schema declares.
	 *
	 * Asserted against the declaration rather than a hand-written list, so a
	 * column added to one and not the other fails here instead of at a write.
	 *
	 * @return void
	 */
	public function test_the_installed_columns_are_the_declared_ones(): void {
		global $wpdb;

		$decisions = new Creative_Decision_Repository();

		$this->really_drop( $decisions->table_name() );
		$this->run_migration( self::CREATIVE_DECISIONS_VERSION );

		$table = $decisions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection of this plugin's own table.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		sort( $columns );

		$declared = Schema::creative_decisions_columns();
		sort( $declared );

		$this->assertSame( $declared, $columns );

		$this->really_drop( $table );
	}

	/**
	 * Nothing is backfilled, and the migration says so by doing nothing else.
	 *
	 * A decision taken before this table existed is in the audit log. Inventing
	 * a row for it would put a date and an actor on a record that is a guess,
	 * and a screen showing guesses about who refused what is worse than a
	 * screen showing the decisions it has.
	 *
	 * @return void
	 */
	public function test_the_migration_backfills_nothing(): void {
		$decisions = new Creative_Decision_Repository();

		$this->really_drop( $decisions->table_name() );
		$this->run_migration( self::CREATIVE_DECISIONS_VERSION );

		$this->assertSame( array(), $decisions->for_campaign( 1 ) );

		global $wpdb;

		$table = $decisions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection of this plugin's own table.
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->assertSame( 0, $rows, 'The migration wrote history it could not know.' );

		$this->really_drop( $table );
	}
}
