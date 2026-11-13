<?php
/**
 * Migration 33 installs and preserves personal report schedules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Install\Schema_Report_Subscriptions;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Report_Subscription_Repository;
use WP_UnitTestCase;

/** Exercise the actual registered migration against physical tables. */
final class ReportSubscriptionSchemaTest extends WP_UnitTestCase {

	/** The fixture must not supply the table the migration claims to create. */
	public function test_migration_33_creates_declared_schema_and_preserves_preferences_on_retry(): void {
		global $wpdb;
		$repo  = new Report_Subscription_Repository();
		$steps = Migration_Map::steps( Plugin::instance()->container() );
		$this->assertGreaterThanOrEqual( 33, Schema::DB_VERSION );
		$this->assertArrayHasKey( 33, $steps );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$repo->drop_table();
			$this->assertFalse( $repo->table_exists() );
			$steps[33]();
			$this->assertTrue( $repo->table_exists() );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect the migration-created physical schema.
			$columns  = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $repo->table_name() ) );
			$expected = Schema_Report_Subscriptions::columns();
			sort( $columns );
			sort( $expected );
			$this->assertSame( $expected, $columns );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Index column order bounds the due cursor and enforces tenant/account uniqueness.
			$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $repo->table_name() ), ARRAY_A );
			$actual  = array();
			foreach ( $indexes as $index ) {
				$actual[ $index['Key_name'] ][] = $index['Column_name'];
			}
			$this->assertSame( array( 'enabled', 'due_at', 'id' ), $actual['due'] );
			$this->assertSame( array( 'org_id', 'user_id' ), $actual['org_user'] );
			$this->assertTrue( $repo->save( 111, 222, 'weekly', 'UTC', 1234567890 ) );
			$before = $repo->for_member( 111, 222 );
			$steps[33]();
			$this->assertSame( $before, $repo->for_member( 111, 222 ) );
			$this->assertTrue( $repo->save( 111, 222, 'off', 'UTC', 0 ) );
			$this->assertSame( $before['id'], $repo->for_member( 111, 222 )['id'] );
		} finally {
			$repo->drop_table();
			$repo->install_table();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}
	/** A refused CREATE must leave version 32 pending and recover on the next run. */
	public function test_failed_creation_does_not_commit_migration_progress(): void {
		global $wpdb;
		$repo     = new Report_Subscription_Repository();
		$original = get_option( \Aggressive\Ads\Install\Installer::OPTION_DB_VERSION );
		$fail     = static fn ( string $sql ): string => str_contains( $sql, 'CREATE TABLE ' . $repo->table_name() ) ? 'CREATE TABLE aggr_report_create_failure_test (' : $sql;
		$errors   = $wpdb->suppress_errors( true );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$repo->drop_table();
			$this->assertFalse( $repo->table_exists() );
			$this->assertFalse( get_option( \Aggressive\Ads\Install\Installer::OPTION_UPGRADE_LOCK, false ), 'The CREATE refusal must be reached rather than skipped under a leftover lock.' );
			update_option( \Aggressive\Ads\Install\Installer::OPTION_DB_VERSION, 32, true );
			$upgrader = Plugin::instance()->container()->get( \Aggressive\Ads\Install\Upgrader::class );
			add_filter( 'query', $fail );
			try {
				$upgrader->maybe_upgrade();
			} finally {
				remove_filter( 'query', $fail );
			}
			$this->assertSame( 32, \Aggressive\Ads\Install\Installer::stored_db_version() );
			$this->assertFalse( $repo->table_exists() );
			$upgrader->maybe_upgrade();
			$this->assertTrue( $repo->table_exists() );
			$this->assertSame( Schema::DB_VERSION, \Aggressive\Ads\Install\Installer::stored_db_version() );
		} finally {
			$repo->install_table();
			update_option( \Aggressive\Ads\Install\Installer::OPTION_DB_VERSION, $original, true );
			// CREATE commits an acquired lock; persist its release before WP_UnitTestCase rolls back later DML.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete cleanup after real DDL in the transactional test harness.
			$wpdb->query( 'COMMIT' );
			$wpdb->suppress_errors( $errors );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}
}
