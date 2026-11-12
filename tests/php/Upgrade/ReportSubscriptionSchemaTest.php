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
}
