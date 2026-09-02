<?php
/**
 * Migration 24: frozen tenancy on the reporting projection.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

/**
 * The migration that runs once against real data, so the assertions here are
 * about what it must *not* do as much as what it must.
 *
 * It deliberately does not empty and rebuild the projection, and that is worth
 * stating where somebody would otherwise "simplify" it: `aggr_rollups` is the
 * pacing and frequency counter as well as the reporting source, so clearing it
 * would reset every live cap and overdeliver for the rest of the day.
 */
final class RollupTenancySchemaTest extends WP_UnitTestCase {

	private const TENANCY_VERSION = 24;

	/**
	 * Projection under test.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	public function set_up(): void {
		parent::set_up();

		$this->rollups = Plugin::instance()->container()->get( Rollup_Repository::class );
		$this->rollups->install_table();
	}

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
	 * A campaign owned by an organization.
	 *
	 * @param int $org_id Owning organization.
	 */
	private function campaign_owned_by( int $org_id ): int {
		$campaign = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CAMPAIGN ) );

		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, $org_id );

		return $campaign;
	}

	/**
	 * Writes one rollup row directly, as an older release would have.
	 *
	 * @param int $campaign_id Campaign id, or 0 for house.
	 * @param int $org_id      Organization already on the row, or 0 for none.
	 */
	private function legacy_row( int $campaign_id, int $org_id = 0 ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recreating a pre-migration row shape.
		$wpdb->insert(
			$this->rollups->table_name(),
			array(
				'day_utc'      => '2026-07-01',
				'placement_id' => 71,
				'campaign_id'  => $campaign_id,
				'line_item_id' => 0,
				'org_id'       => $org_id,
				'impressions'  => 25,
			)
		);
	}

	/**
	 * The stored organization for one campaign's row.
	 *
	 * @param int $campaign_id Campaign id.
	 */
	private function stored_org_for( int $campaign_id ): int {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT org_id FROM {$table} WHERE campaign_id = %d LIMIT 1", $campaign_id ) );
	}

	public function test_the_migration_is_registered_at_a_reachable_version(): void {
		$this->assertGreaterThanOrEqual(
			self::TENANCY_VERSION,
			Schema::DB_VERSION,
			'DB_VERSION is behind this migration, so it would never run.'
		);

		$this->assertArrayHasKey( self::TENANCY_VERSION, Migration_Map::steps( Plugin::instance()->container() ) );
	}

	/**
	 * A row an upgrading site already had becomes attributable.
	 *
	 * The column alone proves nothing — `install_table()` adds it either way.
	 * What only the backfill does is *fill* it, and a row left at zero is a
	 * campaign no report can see.
	 */
	public function test_the_migration_attributes_rows_a_site_already_had(): void {
		$campaign = $this->campaign_owned_by( 314 );

		$this->legacy_row( $campaign );

		$this->assertSame( 0, $this->stored_org_for( $campaign ), 'Without an unattributed row this test proves nothing.' );

		$this->run_migration( self::TENANCY_VERSION );

		$this->assertSame( 314, $this->stored_org_for( $campaign ), 'The backfill left the row unattributable.' );
	}

	/**
	 * **And it never moves a row that already has an organization.**
	 *
	 * The freeze is only worth anything if the migration honours it too. A row
	 * written before its campaign changed hands must keep the organization that
	 * actually made the delivery, not acquire the current one.
	 */
	public function test_the_migration_does_not_move_an_organization_already_recorded(): void {
		$campaign = $this->campaign_owned_by( 200 );

		// Delivered while organization 100 owned it.
		$this->legacy_row( $campaign, 100 );

		$this->run_migration( self::TENANCY_VERSION );

		$this->assertSame(
			100,
			$this->stored_org_for( $campaign ),
			'The migration overwrote history with the campaign current owner.'
		);
	}

	/**
	 * House rows belong to nobody and stay that way.
	 */
	public function test_house_rows_are_left_unattributed(): void {
		$this->legacy_row( 0 );

		$this->run_migration( self::TENANCY_VERSION );

		$this->assertSame( 0, $this->stored_org_for( 0 ) );
	}

	/**
	 * Running it twice changes nothing, which is what lets an interrupted
	 * upgrade resume by simply running again — the reason it needs no cursor.
	 */
	public function test_running_the_migration_twice_changes_nothing(): void {
		$campaign = $this->campaign_owned_by( 555 );

		$this->legacy_row( $campaign );

		$this->run_migration( self::TENANCY_VERSION );
		$first = $this->stored_org_for( $campaign );

		$this->run_migration( self::TENANCY_VERSION );

		$this->assertSame( $first, $this->stored_org_for( $campaign ) );
		$this->assertSame( 555, $first );
	}

	/**
	 * **The counters survive.** The projection is filled in place, never
	 * emptied: this table is the pacing counter too, and a cap that restarts
	 * from nothing overdelivers for the rest of the day.
	 */
	public function test_the_migration_preserves_the_counters_it_attributes(): void {
		global $wpdb;

		$campaign = $this->campaign_owned_by( 888 );

		$this->legacy_row( $campaign );

		$this->run_migration( self::TENANCY_VERSION );

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$impressions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT impressions FROM {$table} WHERE campaign_id = %d LIMIT 1", $campaign ) );

		$this->assertSame( 25, $impressions, 'The migration lost counters that pacing and caps read.' );
	}
}
