<?php
/**
 * Migration 34 installs bounded report access paths and retries failed DDL.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Report_Repository;
use WP_UnitTestCase;

final class CampaignReportIndexesTest extends WP_UnitTestCase {

	/** Schema declares the indexed cursor and tenant/campaign/plan projection ranges. */
	public function test_migration_34_reinstalls_all_three_indexes(): void {
		$steps = Migration_Map::steps( Plugin::instance()->container() );
		$this->assertArrayHasKey( 34, $steps );
		$this->assertGreaterThanOrEqual( 34, Schema::DB_VERSION );
		$this->drop_indexes();
		try {
			$steps[34]();
			( new Campaign_Report_Repository() )->verify_indexes();
			$this->addToAssertionCount( 1 );
			$steps[34]();
			( new Campaign_Report_Repository() )->verify_indexes();
			$this->addToAssertionCount( 1 );
		} finally {
			$steps[34]();
		}
	}

	/** An ALTER refusal cannot be marked complete and hide the failure permanently. */
	public function test_failed_index_install_keeps_version_33_and_recovers_on_retry(): void {
		global $wpdb;
		$services = Plugin::instance()->container();
		$steps    = Migration_Map::steps( $services );
		$original = get_option( Installer::OPTION_DB_VERSION );
		$errors   = $wpdb->suppress_errors( true );
		$this->drop_indexes();
		$fail = static fn ( string $sql ): string => str_starts_with( $sql, 'ALTER TABLE' ) && ( str_contains( $sql, 'organization_report' ) || str_contains( $sql, 'org_campaign_line_' ) ) ? 'ALTER TABLE aggr_missing_index_test ADD KEY broken (' : $sql;
		try {
			$this->assertFalse( get_option( Installer::OPTION_UPGRADE_LOCK, false ), 'The DDL refusal must be reached, rather than skipped under a leftover upgrade lock.' );
			update_option( Installer::OPTION_DB_VERSION, 33, true );
			add_filter( 'query', $fail );
			try {
				$services->get( Upgrader::class )->maybe_upgrade();
			} finally {
				remove_filter( 'query', $fail );
			}
			$this->assertSame( 33, Installer::stored_db_version() );
			$services->get( Upgrader::class )->maybe_upgrade();
			$this->assertSame( Schema::DB_VERSION, Installer::stored_db_version() );
			( new Campaign_Report_Repository() )->verify_indexes();
			$this->addToAssertionCount( 1 );
		} finally {
			$steps[34]();
			update_option( Installer::OPTION_DB_VERSION, $original, true );
			// ALTER implicitly commits the acquired lock; persist the subsequent release before the suite rolls back its transaction.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete cleanup after real DDL in a transactional WordPress test.
			$wpdb->query( 'COMMIT' );
			$wpdb->suppress_errors( $errors );
		}
	}

	/** The access path's column order matters, not only the index name. */
	public function test_wrong_index_shape_is_not_accepted_as_success(): void {
		global $wpdb;
		$steps = Migration_Map::steps( Plugin::instance()->container() );
		$table = $wpdb->prefix . Schema::ROLLUPS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Physical malformed-index fixture, repaired in finally.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX org_campaign_line_day, ADD KEY org_campaign_line_day (org_id,day_utc)', $table ) );
		try {
			try {
				( new Campaign_Report_Repository() )->verify_indexes();
				$this->fail( 'An index name alone cannot establish the query access path.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Could not install campaign report indexes.', $error->getMessage() );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Remove the intentionally malformed index so dbDelta can recreate its declared definition.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX org_campaign_line_day', $table ) );
			$steps[34]();
		}
	}

	/** Remove the actual indexes so a fixture cannot supply migration behavior. */
	private function drop_indexes(): void {
		global $wpdb;
		foreach ( array(
			Schema::LINE_ITEMS_TABLE     => 'organization_report',
			Schema::ROLLUPS_TABLE        => 'org_campaign_line_day',
			Schema::REPORT_BUCKETS_TABLE => 'org_campaign_line_bucket',
		) as $table => $index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER is not rewritten into a temporary-table operation by WP_UnitTestCase.
			$this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $wpdb->prefix . $table, $index ) ) );
		}
	}
}
