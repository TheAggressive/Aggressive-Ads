<?php
/**
 * Schema DDL tests.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Install;

use LAAO_Advertiser_Portal\Install\Schema;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The DDL's *formatting* is functional, because dbDelta parses SQL with regular
 * expressions. Each assertion here corresponds to a documented dbDelta trap,
 * and every one of them fails silently in production: the table is created,
 * looks correct, and dbDelta tries to re-apply something on every single
 * request thereafter.
 */
final class SchemaTest extends TestCase {

	/**
	 * The DDL under test.
	 *
	 * @var string
	 */
	private string $ddl;

	/**
	 * Builds the DDL.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		$this->ddl = Schema::audit_table_ddl( 'wp_laao_ads_audit_log', 'DEFAULT CHARACTER SET utf8mb4' );
	}

	/**
	 * PRIMARY KEY must be followed by exactly two spaces.
	 *
	 * With one space dbDelta does not recognize the key and tries to add it
	 * again forever. This is the single most common dbDelta mistake.
	 *
	 * @return void
	 */
	public function test_primary_key_has_two_spaces(): void {
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $this->ddl );
		$this->assertDoesNotMatchRegularExpression( '/PRIMARY KEY (?! )/', $this->ddl );
	}

	/**
	 * Keys must use KEY, never INDEX.
	 *
	 * INDEX is valid MySQL and completely invisible to dbDelta, so an index
	 * declared that way is created once and never diffed again.
	 *
	 * @return void
	 */
	public function test_no_index_keyword(): void {
		$this->assertDoesNotMatchRegularExpression( '/\bINDEX\b/i', $this->ddl );
	}

	/**
	 * Every key is named. An anonymous key cannot be diffed, so dbDelta
	 * recreates it on every run.
	 *
	 * @return void
	 */
	public function test_every_key_is_named(): void {
		preg_match_all( '/^\s*KEY\s+(\S+)\s*\(/mi', $this->ddl, $matches );

		$this->assertNotEmpty( $matches[1], 'No named keys found at all.' );

		foreach ( $matches[1] as $name ) {
			$this->assertDoesNotMatchRegularExpression( '/^\(/', $name, 'Found an unnamed KEY.' );
		}

		// Five named keys plus PRIMARY.
		$this->assertCount( count( Schema::audit_index_names() ) - 1, $matches[1] );
	}

	/**
	 * The declared index list matches what the DDL actually creates. These are
	 * two separate declarations, and an integration test compares the list
	 * against SHOW INDEX — so they have to agree here first.
	 *
	 * @return void
	 */
	public function test_declared_indexes_appear_in_the_ddl(): void {
		foreach ( Schema::audit_index_names() as $index ) {
			if ( 'PRIMARY' === $index ) {
				$this->assertStringContainsString( 'PRIMARY KEY', $this->ddl );

				continue;
			}

			$this->assertMatchesRegularExpression(
				'/^\s*KEY ' . preg_quote( $index, '/' ) . ' \(/mi',
				$this->ddl,
				"Index {$index} is declared but not in the DDL."
			);
		}
	}

	/**
	 * Every declared column appears in the DDL.
	 *
	 * @return void
	 */
	public function test_every_declared_column_is_in_the_ddl(): void {
		foreach ( Schema::audit_columns() as $column ) {
			$this->assertMatchesRegularExpression(
				'/^\s*' . preg_quote( $column, '/' ) . '\s/mi',
				$this->ddl,
				"Column {$column} is declared but not in the DDL."
			);
		}
	}

	/**
	 * Both timestamp representations exist.
	 *
	 * The integer is for cheap comparison and matches the UTC-integer rule; the
	 * datetime is for humans reading the table. Storing both costs 8 bytes and
	 * removes every timezone question from every query.
	 *
	 * @return void
	 */
	public function test_both_timestamp_columns_exist(): void {
		$this->assertStringContainsString( 'created_at datetime', $this->ddl );
		$this->assertStringContainsString( 'created_at_ts bigint(20) unsigned', $this->ddl );
	}

	/**
	 * The client address column stores a hash, and its width says so — char(64)
	 * is a sha256, and no IPv6 address fits a different shape by accident.
	 *
	 * @return void
	 */
	public function test_ip_column_is_a_hash(): void {
		$this->assertStringContainsString( 'actor_ip_hash char(64)', $this->ddl );
		$this->assertStringNotContainsString( 'actor_ip ', $this->ddl );
	}

	/**
	 * The table name and charset are interpolated where they belong.
	 *
	 * @return void
	 */
	public function test_table_name_and_charset_are_applied(): void {
		$this->assertStringContainsString( 'CREATE TABLE wp_laao_ads_audit_log (', $this->ddl );
		$this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $this->ddl );
	}

	/**
	 * A schema version is declared, since the migration walker keys off it.
	 *
	 * @return void
	 */
	public function test_a_db_version_is_declared(): void {
		$this->assertGreaterThanOrEqual( 1, Schema::DB_VERSION );
	}
}
