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

	/**
	 * The pacing join reaches rollups by index rather than scanning them.
	 *
	 * This runs on every cold fill, joining the whole rollup table — which
	 * grows one row per placement, campaign, line item and day and is never
	 * purged with events. A scan here is invisible on a new site and gets
	 * slower every day the plugin runs, which is the worst shape of
	 * performance bug: it passes review and degrades in production.
	 *
	 * Asserted from `EXPLAIN` rather than from timing, for the reason the
	 * candidate read contract records — a small table is fast to scan.
	 *
	 * **Two limits, both real.** This EXPLAINs the access pattern rather than
	 * the repository's own statement, so it proves the index serves
	 * `WHERE line_item_id IN (…) GROUP BY line_item_id` and not that the caller
	 * still writes it that way. And a sabotage removing the index from the DDL
	 * does not fail here: per-test tables are TEMPORARY, so dropping one can
	 * reveal a base table that `dbDelta` then leaves alone. A fresh install on
	 * a real site is the proof; this catches the access pattern drifting away
	 * from the index it was given.
	 */
	public function test_the_pacing_join_uses_the_line_item_index(): void {
		global $wpdb;

		$rollups = $this->rollups->table_name();

		for ( $i = 0; $i < 200; $i++ ) {
			$this->rollups->increment( 'impressions', 1, 1, sprintf( '2026-03-%02d', ( $i % 28 ) + 1 ), 1000 + $i );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query-plan introspection over this plugin's own table.
		$plan = $wpdb->get_results( "EXPLAIN SELECT line_item_id, SUM(impressions) FROM {$rollups} WHERE line_item_id IN (1000,1001,1002) GROUP BY line_item_id", ARRAY_A );

		$this->assertNotEmpty( $plan, 'EXPLAIN returned nothing, so this asserts nothing.' );

		$keys = array_filter( array_column( $plan, 'key' ) );

		$this->assertContains(
			'line_item_day',
			$keys,
			'The pacing read is not using the line-item index it was given.'
		);

		$this->assertNotEmpty(
			$keys,
			'The pacing read scans the rollup table, which grows every day and is never purged with events.'
		);
	}

	/** The stored `viewables` for one slot and day, or null when unmeasured. */
	private function viewables( int $placement_id, string $day ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table in a test.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT viewables FROM %i WHERE placement_id = %d AND day_utc = %s',
				$this->rollups->table_name(),
				$placement_id,
				$day
			)
		);

		return null === $value ? null : (int) $value;
	}

	/**
	 * A day with impressions but no views reads zero, not null.
	 *
	 * The distinction the column exists for. Zero means the page was measuring
	 * and nothing qualified; null means nobody was looking. Reporting has to
	 * tell those apart, and an impression is what proves measurement was on.
	 */
	public function test_a_measured_day_with_no_views_reads_zero(): void {
		$this->rollups->increment( 'impressions', 61, 610, '2026-04-01', 6100 );

		$this->assertSame( 0, $this->viewables( 61, '2026-04-01' ) );
	}

	/**
	 * A row written before the column existed stays null.
	 *
	 * Projecting zero onto history would make "viewability was not implemented"
	 * look exactly like "not one ad was seen all day" — the more alarming
	 * reading, and the wrong one.
	 */
	public function test_history_is_left_unmeasured_rather_than_zeroed(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recreating the pre-v17 row shape.
		$wpdb->insert(
			$this->rollups->table_name(),
			array(
				'day_utc'      => '2026-04-02',
				'placement_id' => 62,
				'campaign_id'  => 620,
				'line_item_id' => 6200,
				'impressions'  => 40,
				'clicks'       => 2,
			)
		);

		$this->assertNull(
			$this->viewables( 62, '2026-04-02' ),
			'A day from before measurement existed was projected as zero views.'
		);
	}

	/**
	 * A recorded view lands on `viewables`, and its delivery on `impressions`.
	 *
	 * Driven through `Event_Recorder`, which is where an event type becomes a
	 * column. Every other test here calls the repository directly and names the
	 * column itself, so a sabotage projecting views onto `clicks` left all of
	 * them green — the mapping was the one part nothing exercised.
	 */
	public function test_the_recorder_projects_a_view_onto_the_right_column(): void {
		global $wpdb;

		$assignments = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Creative_Assignment_Repository::class );
		$assignments->install_table();

		$placement_id = 7373;
		$revision_id  = 7474;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->insert(
			$assignments->table_name(),
			array(
				'line_item_id' => 7575,
				'campaign_id'  => 7676,
				'placement_id' => $placement_id,
				'revision_id'  => $revision_id,
				'status'       => \Aggressive\Ads\Domain\Assignment_Rules::LIVE,
				'weight'       => 100,
				'revision'     => 1,
			)
		);

		$recorder = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Event_Recorder::class );
		$today    = gmdate( 'Y-m-d' );

		$recorder->record(
			\Aggressive\Ads\Repository\Event_Repository::TYPE_SERVED,
			$placement_id,
			7676,
			$revision_id,
			str_repeat( 'c', 64 ),
			str_repeat( 'd', 64 )
		);

		$this->assertSame(
			0,
			$this->viewables( $placement_id, $today ),
			'A delivery must mark the day measured rather than leaving it unknown.'
		);

		$recorder->record(
			\Aggressive\Ads\Repository\Event_Repository::TYPE_VIEWABLE,
			$placement_id,
			7676,
			$revision_id,
			str_repeat( 'c', 64 ),
			str_repeat( 'd', 64 )
		);

		$this->assertSame(
			1,
			$this->viewables( $placement_id, $today ),
			'A recorded view did not reach the viewables column.'
		);
	}

	/** A recorded view increments the day's counter. */
	public function test_a_view_is_counted(): void {
		$this->rollups->increment( 'impressions', 63, 630, '2026-04-03', 6300 );
		$this->rollups->increment( 'viewables', 63, 630, '2026-04-03', 6300 );
		$this->rollups->increment( 'viewables', 63, 630, '2026-04-03', 6300 );

		$this->assertSame( 2, $this->viewables( 63, '2026-04-03' ) );
	}

	/**
	 * A view against an unmeasured row starts at one rather than staying null.
	 *
	 * `NULL + 1` is NULL in SQL, so without the COALESCE on update a row
	 * carried over from before the column existed would silently swallow every
	 * view recorded against it.
	 */
	public function test_a_view_on_a_pre_existing_row_counts_from_zero(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recreating the pre-v17 row shape.
		$wpdb->insert(
			$this->rollups->table_name(),
			array(
				'day_utc'      => '2026-04-04',
				'placement_id' => 64,
				'campaign_id'  => 640,
				'line_item_id' => 6400,
				'impressions'  => 5,
			)
		);

		$this->rollups->increment( 'viewables', 64, 640, '2026-04-04', 6400 );

		$this->assertSame( 1, $this->viewables( 64, '2026-04-04' ) );
	}

	/**
	 * Reconciling a pre-measurement day leaves it unmeasured.
	 *
	 * The reconciler walks from the earliest ledger day whenever it has no
	 * watermark, and no pre-P11 day has viewable events — so a plain
	 * `viewables = VALUES(viewables)` rewrites every one of them from NULL to
	 * zero. History would silently change from "nobody was measuring" to "not
	 * one ad was seen", which is the alarming reading and the false one.
	 */
	public function test_reconciling_history_does_not_invent_zero_views(): void {
		global $wpdb;

		update_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE, '2026-06-01' );

		$events = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Event_Repository::class );
		$events->install_table();

		$day   = '2026-05-20';
		$start = (int) strtotime( $day . ' 00:00:00 UTC' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ledger fixture for this plugin's own table.
		$wpdb->insert(
			$events->table_name(),
			array(
				'created_at_ts' => $start + 60,
				'event'         => \Aggressive\Ads\Repository\Event_Repository::TYPE_SERVED,
				'placement_id'  => 81,
				'campaign_id'   => 810,
				'creative_id'   => 8100,
				'token_hash'    => str_repeat( '1', 64 ),
				'ip_hash'       => str_repeat( '2', 64 ),
			)
		);

		$this->assertTrue( $this->rollups->reconcile_day( $day ) );

		$this->assertNull(
			$this->viewables( 81, $day ),
			'Reconciling a day from before measurement rewrote it as zero views.'
		);

		delete_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE );
	}

	/**
	 * A day after measurement began reconciles to a real number.
	 *
	 * The positive half: a rule that returned NULL for every day would satisfy
	 * the test above while making viewability permanently unreportable.
	 */
	public function test_reconciling_a_measured_day_counts_its_views(): void {
		global $wpdb;

		update_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE, '2026-06-01' );

		$events = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Event_Repository::class );
		$events->install_table();

		$day   = '2026-06-10';
		$start = (int) strtotime( $day . ' 00:00:00 UTC' );

		foreach ( array( \Aggressive\Ads\Repository\Event_Repository::TYPE_SERVED, \Aggressive\Ads\Repository\Event_Repository::TYPE_VIEWABLE ) as $index => $event ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ledger fixture for this plugin's own table.
			$wpdb->insert(
				$events->table_name(),
				array(
					'created_at_ts' => $start + 60,
					'event'         => $event,
					'placement_id'  => 82,
					'campaign_id'   => 820,
					'creative_id'   => 8200,
					'token_hash'    => str_repeat( '3', 64 ),
					'ip_hash'       => str_repeat( '4', 64 ),
				)
			);
		}

		$this->assertTrue( $this->rollups->reconcile_day( $day ) );

		$this->assertSame(
			1,
			$this->viewables( 82, $day ),
			'A measured day did not reconcile its views.'
		);

		delete_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE );
	}
}
