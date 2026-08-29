<?php
/**
 * Schema 16: a delivery counts against the line item whose cap it spends.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Counting deliveries per campaign was correct only while a campaign had
 * exactly one line item, which was true and is not a property to depend on: a
 * second would have spent its sibling's impressions against its own cap and
 * stopped delivering early, with nothing reporting why.
 *
 * The migration has to do two things `dbDelta` will not. It must attribute the
 * rows already stored — safe precisely because of the one-line-item assumption
 * that made the old counting work — and it must drop the superseded unique,
 * which otherwise refuses the second row this whole change exists to allow.
 */
final class RollupLineItemAttributionTest extends WP_UnitTestCase {

	/**
	 * Rollup persistence.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	public function set_up(): void {
		parent::set_up();

		$installer = new Installer( new Audit_Repository(), new Roles() );
		$installer->install_delivery_tables();
		$installer->install_line_items();

		$this->rollups = Plugin::instance()->container()->get( Rollup_Repository::class );
	}

	/** The index names actually on the table. */
	private function index_names(): array {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		return is_array( $rows ) ? array_values( array_unique( array_column( $rows, 'Key_name' ) ) ) : array();
	}

	/**
	 * Two line items on one slot and day each keep their own counter.
	 *
	 * The behaviour the whole change exists for, and the one the pre-v16 unique
	 * made impossible: `(placement, campaign, day)` accepted one row, so the
	 * second line item's impressions landed on the first.
	 */
	public function test_two_line_items_on_one_slot_count_separately(): void {
		$this->assertTrue( $this->rollups->increment( 'impressions', 7, 70, '2026-01-01', 111 ) );
		$this->assertTrue( $this->rollups->increment( 'impressions', 7, 70, '2026-01-01', 111 ) );
		$this->assertTrue( $this->rollups->increment( 'impressions', 7, 70, '2026-01-01', 222 ) );

		$totals = $this->rollups->delivery_totals_for_line_items( array( 111, 222 ), '2026-01-01' );

		$this->assertSame( 2, $totals[111]['lifetime'] ?? 0, 'The first line item lost a delivery.' );
		$this->assertSame(
			1,
			$totals[222]['lifetime'] ?? 0,
			'The second line item was folded into the first, which is the defect this replaces.'
		);
	}

	/**
	 * A counter belongs to one line item, not to every candidate in a campaign.
	 *
	 * The negative half. A read that still grouped by campaign would satisfy the
	 * test above and report the same total for both.
	 */
	public function test_a_line_item_does_not_see_its_siblings_deliveries(): void {
		$this->rollups->increment( 'impressions', 8, 80, '2026-01-02', 333 );
		$this->rollups->increment( 'impressions', 8, 80, '2026-01-02', 333 );
		$this->rollups->increment( 'impressions', 8, 80, '2026-01-02', 444 );

		$totals = $this->rollups->delivery_totals_for_line_items( array( 444 ), '2026-01-02' );

		$this->assertSame(
			1,
			$totals[444]['lifetime'] ?? 0,
			'A line item counted a sibling delivery against its own cap.'
		);
	}

	/** Today and lifetime are told apart, since a daily cap depends on it. */
	public function test_today_is_separated_from_lifetime(): void {
		$this->rollups->increment( 'impressions', 9, 90, '2026-01-03', 555 );
		$this->rollups->increment( 'impressions', 9, 90, '2026-01-04', 555 );
		$this->rollups->increment( 'impressions', 9, 90, '2026-01-04', 555 );

		$totals = $this->rollups->delivery_totals_for_line_items( array( 555 ), '2026-01-04' );

		$this->assertSame( 3, $totals[555]['lifetime'] ?? 0 );
		$this->assertSame( 2, $totals[555]['today'] ?? 0 );
	}

	/**
	 * The migration attributes rows stored before the column existed.
	 *
	 * Written with `line_item_id = 0`, the state an upgraded install is in, then
	 * attributed to the campaign's default line item — correct because a
	 * campaign had exactly one, which is the same assumption that made counting
	 * by campaign work in the first place.
	 */
	public function test_the_migration_attributes_existing_rows(): void {
		global $wpdb;

		$campaign_id = (int) self::factory()->post->create( array( 'post_type' => 'aggr_campaign' ) );
		$default     = Plugin::instance()->container()->get( Line_Item_Repository::class )
			->ensure_default( $campaign_id );

		$this->assertIsArray( $default, 'The fixture produced no default line item.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recreating the pre-v16 row shape.
		$wpdb->insert(
			$this->rollups->table_name(),
			array(
				'day_utc'      => '2026-02-01',
				'placement_id' => 12,
				'campaign_id'  => $campaign_id,
				'line_item_id' => 0,
				'impressions'  => 9,
				'clicks'       => 2,
			)
		);

		$this->rollups->migrate_line_item_attribution();

		$totals = $this->rollups->delivery_totals_for_line_items( array( (int) $default['id'] ), '2026-02-01' );

		$this->assertSame(
			9,
			$totals[ (int) $default['id'] ]['lifetime'] ?? 0,
			'Existing counters were not attributed, so every cap would restart from zero.'
		);
	}

	/**
	 * The superseded unique is gone, not merely superseded.
	 *
	 * `dbDelta` adds an index and never drops one, so without this the pre-v16
	 * `slot_day` unique stays and keeps refusing the second line item's row.
	 */
	public function test_the_pre_v16_unique_is_dropped(): void {
		global $wpdb;

		$table = $this->rollups->table_name();

		/*
		 * Put the old unique back first. A fresh table is built from the
		 * current DDL and never had `slot_day`, so asserting its absence
		 * without this passes on a table the migration never had to repair —
		 * a sabotage run removing the drop entirely left it green.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreating the pre-v16 shape in a test.
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY slot_day (placement_id,campaign_id,day_utc)" );

		$this->assertContains( 'slot_day', $this->index_names(), 'The fixture did not recreate the pre-v16 unique.' );

		$this->rollups->migrate_line_item_attribution();

		$names = $this->index_names();

		$this->assertContains( 'slot_line_day', $names, 'The new unique key was never created.' );
		$this->assertNotContains(
			'slot_day',
			$names,
			'The pre-v16 unique survived, so a second line item still cannot hold its own row.'
		);
	}

	/**
	 * The beacon attributes a delivery to the assignment that served it.
	 *
	 * `Event_Recorder` is where a live impression becomes a counter, and it
	 * knows the creative rather than the line item. Without this, the whole
	 * chain could be correct and every real delivery still land on line item
	 * zero — a sabotage removing the lookup left the rest of these green.
	 */
	public function test_a_recorded_delivery_lands_on_the_serving_line_item(): void {
		global $wpdb;

		$assignments = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Creative_Assignment_Repository::class );
		$assignments->install_table();

		$placement_id = 4242;
		$revision_id  = 8181;
		$line_item_id = 9191;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->insert(
			$assignments->table_name(),
			array(
				'line_item_id' => $line_item_id,
				'campaign_id'  => 4141,
				'placement_id' => $placement_id,
				'revision_id'  => $revision_id,
				'status'       => \Aggressive\Ads\Domain\Assignment_Rules::LIVE,
				'weight'       => 100,
				'revision'     => 1,
			)
		);

		$recorder = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Event_Recorder::class );

		$recorder->record(
			\Aggressive\Ads\Repository\Event_Repository::TYPE_SERVED,
			$placement_id,
			4141,
			$revision_id,
			str_repeat( 'a', 64 ),
			str_repeat( 'b', 64 )
		);

		$totals = $this->rollups->delivery_totals_for_line_items( array( $line_item_id ) );

		$this->assertSame(
			1,
			$totals[ $line_item_id ]['lifetime'] ?? 0,
			'A recorded delivery did not land on the line item that served it.'
		);
	}
}
