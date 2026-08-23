<?php
/**
 * Line-item persistence and migration.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Line_Item_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use WP_UnitTestCase;

final class LineItemRepositoryTest extends WP_UnitTestCase {

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * Line-item persistence.
	 *
	 * @var Line_Item_Repository
	 */
	private Line_Item_Repository $line_items;

	public function set_up(): void {
		parent::set_up();
		$this->campaigns  = Plugin::instance()->container()->get( Campaign_Repository::class );
		$this->line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );
		$this->line_items->install_table();
		delete_option( Line_Item_Migrator::OPTION_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_DONE );
		wp_clear_scheduled_hook( Line_Item_Migrator::HOOK );
	}

	public function tear_down(): void {
		delete_option( Line_Item_Migrator::OPTION_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_DONE );
		wp_clear_scheduled_hook( Line_Item_Migrator::HOOK );
		parent::tear_down();
	}

	public function test_default_creation_is_idempotent_and_maps_campaign_fields(): void {
		$campaign = $this->campaign( 'Launch', Post_Statuses::LIVE, 42 );
		update_post_meta( $campaign, Campaign_Repository::META_START_TS, 1000 );
		update_post_meta( $campaign, Campaign_Repository::META_END_TS, 2000 );
		update_post_meta( $campaign, Campaign_Repository::META_BUDGET_CENTS, 87500 );

		$first  = $this->line_items->ensure_default( $campaign );
		$second = $this->line_items->ensure_default( $campaign );

		$this->assertNotNull( $first );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertCount( 1, $this->line_items->for_campaign( $campaign ) );
		$this->assertSame( 42, $first['organization_id'] );
		$this->assertSame( 'Launch', $first['name'] );
		$this->assertSame( 'live', $first['status'] );
		$this->assertSame( 1000, $first['start_at_ts'] );
		$this->assertSame( 2000, $first['end_at_ts'] );
		$this->assertSame( 87500, $first['budget_cents'] );
		$this->assertTrue( $first['is_default'] );
	}

	public function test_legacy_titles_are_bounded_and_empty_titles_get_a_stable_name(): void {
		$long     = $this->campaign( str_repeat( 'É', 220 ), Post_Statuses::DRAFT, 42 );
		$untitled = $this->campaign( '', Post_Statuses::DRAFT, 42 );

		$long_row     = $this->line_items->ensure_default( $long );
		$untitled_row = $this->line_items->ensure_default( $untitled );

		$this->assertNotNull( $long_row );
		$this->assertNotNull( $untitled_row );
		$this->assertSame( 191, mb_strlen( $long_row['name'] ) );
		$this->assertSame( 'Campaign ' . $untitled, $untitled_row['name'] );
	}

	public function test_optimistic_update_allows_one_writer_and_rejects_stale_revision(): void {
		$campaign = $this->campaign( 'Concurrent', Post_Statuses::DRAFT, 7 );
		$row      = $this->line_items->ensure_default( $campaign );
		$this->assertNotNull( $row );

		$next = $this->line_items->update(
			(int) $row['id'],
			$campaign,
			array(
				'pacing_mode' => 'asap',
				'weight'      => 250,
			),
			1
		);
		$lost = $this->line_items->update( (int) $row['id'], $campaign, array( 'weight' => 999 ), 1 );
		$read = $this->line_items->default_for_campaign( $campaign );

		$this->assertSame( 2, $next );
		$this->assertFalse( $lost );
		$this->assertSame( 250, $read['weight'] );
		$this->assertSame( 'asap', $read['pacing_mode'] );
	}

	public function test_campaign_projection_updates_lifecycle_schedule_and_budget(): void {
		$campaign = $this->campaign( 'Projection', Post_Statuses::DRAFT, 9 );
		$this->line_items->ensure_default( $campaign );
		$this->campaigns->update_status( $campaign, Post_Statuses::SCHEDULED );
		update_post_meta( $campaign, Campaign_Repository::META_START_TS, 3000 );
		update_post_meta( $campaign, Campaign_Repository::META_BUDGET_CENTS, 12345 );

		$this->assertTrue( $this->line_items->sync_default_from_campaign( $campaign ) );
		$row = $this->line_items->default_for_campaign( $campaign );
		$this->assertSame( 'scheduled', $row['status'] );
		$this->assertSame( 3000, $row['start_at_ts'] );
		$this->assertSame( 12345, $row['budget_cents'] );
	}

	public function test_deleting_a_campaign_removes_its_line_items(): void {
		$campaign = $this->campaign( 'Delete me', Post_Statuses::DRAFT, 9 );
		$this->assertNotNull( $this->line_items->ensure_default( $campaign ) );

		wp_delete_post( $campaign, true );

		$this->assertSame( array(), $this->line_items->for_campaign( $campaign ) );
	}

	public function test_backfill_is_bounded_restartable_and_eventually_complete(): void {
		$existing = $this->line_items->campaign_ids_after( 0, 500 );
		$baseline = array() === $existing ? 0 : max( $existing );
		$created  = array();
		for ( $i = 0; $i < Line_Item_Migrator::BATCH_SIZE + 3; ++$i ) {
			$created[] = $this->campaign( 'Legacy ' . $i, Post_Statuses::DRAFT, 5 );
		}

		$migrator = new Line_Item_Migrator( $this->line_items, $this->campaigns );
		add_option( Line_Item_Migrator::OPTION_CURSOR, $baseline, '', false );
		$migrator->start();
		$this->assertSame( Line_Item_Migrator::BATCH_SIZE, $migrator->run_batch() );
		$this->assertFalse( $migrator->is_complete() );
		$this->assertGreaterThan( 0, (int) get_option( Line_Item_Migrator::OPTION_CURSOR ) );

		$this->assertSame( 3, $migrator->run_batch() );
		$this->assertTrue( $migrator->is_complete() );
		$this->assertFalse( get_option( Line_Item_Migrator::OPTION_CURSOR, false ) );

		foreach ( $created as $campaign_id ) {
			$this->assertNotNull( $this->line_items->default_for_campaign( $campaign_id ) );
		}
	}

	private function campaign( string $title, string $status, int $org_id ): int {
		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);
		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, $org_id );

		return $campaign;
	}
}
