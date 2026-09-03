<?php
/**
 * Emptying this plugin's own tables between tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests;

use PHPUnit\Runner\BeforeTestHook;

/**
 * The rollback that `WP_UnitTestCase` cannot give these tables.
 *
 * **The cause is DDL, not the tables being special.** `WP_UnitTestCase` wraps
 * each test in a transaction and rolls it back, and that covers this plugin's
 * tables exactly as it covers core's — until a test performs DDL. `CREATE
 * TABLE` implicitly commits in MySQL, so a `set_up()` that calls
 * `parent::set_up()` and then `install_table()` opens a transaction and ends it
 * one line later. Everything that test writes afterwards is permanent, and on a
 * developer machine it is permanent across *runs*.
 *
 * It cost real time twice before it was understood. `RollupLineItemAttributionTest`
 * began failing on a local full-suite run and passing in isolation, because six
 * rows left by earlier runs of `RollupTenancySchemaTest` were being summed into
 * its total — and the second time, it corrupted a mutation-testing run, where a
 * red baseline makes every mutant look caught. CI never saw either, because CI
 * starts from a fresh database every time.
 *
 * **A hook rather than a rule.** "Do not perform DDL inside a test" is the real
 * fix and it is not enforceable: schema and migration tests exist precisely to
 * exercise DDL, and the ordering that makes it harmless — install *before*
 * `parent::set_up()` — is a convention nobody will remember. Emptying the
 * tables before each test makes the whole class of mistake harmless instead.
 *
 * `DELETE`, never `TRUNCATE`: truncation is itself DDL and would commit the
 * very transaction this exists to protect.
 */
final class Plugin_Table_Reset implements BeforeTestHook {

	/**
	 * Empties every table this plugin owns.
	 *
	 * Tables that do not exist yet are skipped rather than created: this runs
	 * before tests that have not installed anything, and creating a table here
	 * would be the DDL problem all over again.
	 *
	 * @param string $test Test identifier, unused.
	 * @return void
	 */
	public function executeBeforeTest( string $test ): void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		foreach ( self::tables() as $table ) {
			$name = $wpdb->prefix . $table;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only cleanup of this plugin's own tables; the name is prefix + a constant from Schema.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );

			if ( $name === $exists ) {
				$wpdb->query( "DELETE FROM {$name}" );
			}
			// phpcs:enable
		}
	}

	/**
	 * Every table name this plugin installs.
	 *
	 * Read from `Schema` rather than listed, so a table added there is emptied
	 * without anybody remembering this file exists.
	 *
	 * @return list<string>
	 */
	private static function tables(): array {
		$tables = array();

		foreach ( ( new \ReflectionClass( \Aggressive\Ads\Install\Schema::class ) )->getConstants() as $name => $value ) {
			if ( is_string( $value ) && str_starts_with( $value, 'aggr_' ) && str_ends_with( $name, '_TABLE' ) ) {
				$tables[] = $value;
			}
		}

		return $tables;
	}
}
