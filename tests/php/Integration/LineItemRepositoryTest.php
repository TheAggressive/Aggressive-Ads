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
		delete_option( Line_Item_Migrator::OPTION_NAME_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_NAME_DONE );
		wp_clear_scheduled_hook( Line_Item_Migrator::HOOK );
	}

	public function tear_down(): void {
		delete_option( Line_Item_Migrator::OPTION_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_DONE );
		delete_option( Line_Item_Migrator::OPTION_NAME_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_NAME_DONE );
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

	/**
	 * **A campaign renamed after its first detail view takes the name with it.**
	 *
	 * The bug this closes, in the sequence that produced it. The wizard creates
	 * a campaign under a placeholder title; the first detail view creates the
	 * default line item and freezes that placeholder into its name; the
	 * advertiser then names the campaign properly. Before the projection
	 * carried the name, the Delivery strategy panel showed "Untitled campaign"
	 * for the rest of the campaign's life, and the browser suite caught it.
	 *
	 * @return void
	 */
	public function test_a_default_line_item_follows_a_campaign_rename(): void {
		$campaign = $this->campaign( 'Untitled campaign', Post_Statuses::DRAFT, 4 );

		$this->line_items->ensure_default( $campaign );

		$this->assertSame(
			'Untitled campaign',
			$this->line_items->default_for_campaign( $campaign )['name'],
			'The fixture must start from the placeholder, or it is not testing the rename.'
		);

		wp_update_post(
			array(
				'ID'         => $campaign,
				'post_title' => 'Spring season launch',
			)
		);

		$this->assertTrue( $this->line_items->sync_default_from_campaign( $campaign ) );

		$row = $this->line_items->default_for_campaign( $campaign );

		$this->assertSame( 'Spring season launch', $row['name'] );
		$this->assertTrue( $row['name_is_derived'] );
	}

	/**
	 * **A publisher's own name is never overwritten by a later rename.**
	 *
	 * The other half, and the reason the flag exists rather than a comparison
	 * against the revision counter: any edit bumps the revision, so inferring
	 * provenance from it would freeze the name of every line item whose pacing
	 * somebody adjusted, and re-deriving unconditionally would throw away a
	 * deliberate rename.
	 *
	 * @return void
	 */
	public function test_a_renamed_line_item_stops_following_the_campaign(): void {
		$campaign = $this->campaign( 'Untitled campaign', Post_Statuses::DRAFT, 4 );
		$row      = $this->line_items->ensure_default( $campaign );

		$revision = $this->line_items->update(
			(int) $row['id'],
			$campaign,
			array( 'name' => 'Retargeting, week 2' ),
			(int) $row['revision']
		);

		$this->assertIsInt( $revision );
		$this->assertFalse( $this->line_items->default_for_campaign( $campaign )['name_is_derived'] );

		wp_update_post(
			array(
				'ID'         => $campaign,
				'post_title' => 'Spring season launch',
			)
		);

		$this->line_items->sync_default_from_campaign( $campaign );

		$after = $this->line_items->default_for_campaign( $campaign );

		$this->assertSame( 'Retargeting, week 2', $after['name'] );
		$this->assertFalse( $after['name_is_derived'] );
	}

	/**
	 * An unrelated edit does not stop the name following the campaign.
	 *
	 * Pins the distinction the revision counter could not make.
	 *
	 * @return void
	 */
	public function test_an_edit_that_is_not_a_rename_leaves_the_name_derived(): void {
		$campaign = $this->campaign( 'Untitled campaign', Post_Statuses::DRAFT, 4 );
		$row      = $this->line_items->ensure_default( $campaign );

		$this->line_items->update(
			(int) $row['id'],
			$campaign,
			array( 'pacing_mode' => 'asap' ),
			(int) $row['revision']
		);

		wp_update_post(
			array(
				'ID'         => $campaign,
				'post_title' => 'Spring season launch',
			)
		);

		$this->line_items->sync_default_from_campaign( $campaign );

		$after = $this->line_items->default_for_campaign( $campaign );

		$this->assertSame( 'asap', $after['pacing_mode'] );
		$this->assertSame( 'Spring season launch', $after['name'] );
		$this->assertTrue( $after['name_is_derived'] );
	}

	/**
	 * **The backfill classifies existing rows instead of assuming.**
	 *
	 * Adding the column gives every row already on disk the "derived" default.
	 * That is right for rows nobody renamed and wrong for the rest, and getting
	 * it wrong in that direction silently overwrites a publisher's rename on
	 * the next projection.
	 *
	 * Asserted by count as well as by flag, so "it corrected something" cannot
	 * pass for "it corrected the right row".
	 *
	 * @return void
	 */
	public function test_the_backfill_separates_derived_names_from_renamed_ones(): void {
		$derived  = $this->campaign( 'Left alone', Post_Statuses::DRAFT, 4 );
		$renamed  = $this->campaign( 'Also left alone', Post_Statuses::DRAFT, 4 );
		$expected = $this->line_items->ensure_default( $derived );
		$other    = $this->line_items->ensure_default( $renamed );

		$this->assertNotNull( $expected );
		$this->assertNotNull( $other );

		// Put both rows in the state an upgrade produces: a name somebody
		// changed, and the column default claiming both are still ours.
		global $wpdb;
		$table = $this->line_items->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Test fixture writing the pre-upgrade state directly.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET name = %s, name_is_derived = 1 WHERE id = %d", 'Hand-written name', (int) $other['id'] ) );

		$result = $this->line_items->backfill_name_provenance( 0, 100 );

		$this->assertSame( 2, $result['examined'] );
		$this->assertSame( 1, $result['corrected'], 'Exactly the renamed row should have been corrected.' );
		$this->assertTrue( $this->line_items->default_for_campaign( $derived )['name_is_derived'] );
		$this->assertFalse( $this->line_items->default_for_campaign( $renamed )['name_is_derived'] );
	}

	/**
	 * The backfill is bounded and resumes from its cursor.
	 *
	 * @return void
	 */
	public function test_the_backfill_is_bounded_and_restartable(): void {
		$campaigns = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$campaigns[] = $this->campaign( 'Campaign ' . $i, Post_Statuses::DRAFT, 4 );
		}

		foreach ( $campaigns as $campaign ) {
			$this->line_items->ensure_default( $campaign );
		}

		$first = $this->line_items->backfill_name_provenance( 0, 2 );

		$this->assertSame( 2, $first['examined'] );
		$this->assertGreaterThan( 0, $first['cursor'] );

		$second = $this->line_items->backfill_name_provenance( $first['cursor'], 100 );

		$this->assertSame( 3, $second['examined'], 'The second pass must resume, not restart.' );
		$this->assertSame( 0, $this->line_items->backfill_name_provenance( $second['cursor'], 100 )['examined'] );
	}
}
