<?php
/**
 * The P2 backfill gives every creative an asset and an assignment.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use WP_UnitTestCase;

/**
 * The backfill, the lazy heal, and the deletion that has to keep up with them.
 *
 * Nothing on the serving path reads what this writes, which is what makes the
 * migration safe to run on a live site — but it is also what makes these
 * assertions the only thing watching it. A wrong row here is invisible until
 * something depends on it.
 *
 * The cases are the ones the P1 migration taught: resume from a cursor without
 * revisiting or skipping, treat a row that can never migrate as skippable
 * rather than fatal, make repeat runs no-ops by database rule, and give every
 * revision of one artwork the same asset.
 */
final class CreativeAssignmentBackfillTest extends WP_UnitTestCase {

	/**
	 * Backfill under test.
	 *
	 * @var Creative_Assignment_Migrator
	 */
	private Creative_Assignment_Migrator $migrator;

	/**
	 * Assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	/**
	 * Asset persistence.
	 *
	 * @var Creative_Asset_Repository
	 */
	private Creative_Asset_Repository $assets;

	/**
	 * Line-item persistence.
	 *
	 * @var Line_Item_Repository
	 */
	private Line_Item_Repository $line_items;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->migrator    = $container->get( Creative_Assignment_Migrator::class );
		$this->assignments = $container->get( Creative_Assignment_Repository::class );
		$this->assets      = $container->get( Creative_Asset_Repository::class );
		$this->line_items  = $container->get( Line_Item_Repository::class );

		$this->assets->install_table();
		$this->assignments->install_table();
		$this->line_items->install_table();

		delete_option( Creative_Assignment_Migrator::OPTION_CURSOR );
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
		wp_clear_scheduled_hook( Creative_Assignment_Migrator::HOOK );
	}

	public function tear_down(): void {
		delete_option( Creative_Assignment_Migrator::OPTION_CURSOR );
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
		wp_clear_scheduled_hook( Creative_Assignment_Migrator::HOOK );

		parent::tear_down();
	}

	/**
	 * A campaign with one live creative on one placement.
	 *
	 * @param string $status Campaign status.
	 * @return array{campaign: int, creative: int, placement: int}
	 */
	private function campaign_with_creative( string $status = Post_Statuses::LIVE ): array {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
			)
		);

		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, 42 );
		add_post_meta( $campaign, Campaign_Repository::META_PLACEMENT_ID, $placement );

		$creative = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
				'post_title'  => 'Spring sale banner',
			)
		);

		update_post_meta( $creative, Creative_Repository::META_CAMPAIGN_ID, $campaign );
		update_post_meta( $creative, Creative_Repository::META_ORG_ID, 42 );
		update_post_meta( $creative, Creative_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $creative, Creative_Repository::META_CLICK_URL, 'https://example.com/spring' );
		update_post_meta( $creative, Creative_Repository::META_ALT_TEXT, 'Spring sale' );
		update_post_meta( $creative, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative, Creative_Repository::META_HEIGHT, 90 );
		update_post_meta( $creative, Creative_Repository::META_KIND, 'image' );

		return array(
			'campaign'  => $campaign,
			'creative'  => $creative,
			'placement' => $placement,
		);
	}

	public function test_a_creative_gets_an_assignment_carrying_its_delivery_fields(): void {
		$made = $this->campaign_with_creative();

		$this->migrator->run_batch();

		$line_item = $this->line_items->default_for_campaign( $made['campaign'] );
		$this->assertIsArray( $line_item, 'The backfill needs a line item to assign to.' );

		$row = $this->assignments->compatibility_row( (int) $line_item['id'], $made['placement'] );

		$this->assertIsArray( $row, 'The creative got no compatibility assignment.' );
		$this->assertSame( $made['creative'], (int) $row['revision_id'] );
		$this->assertSame( $made['campaign'], (int) $row['campaign_id'] );
		$this->assertSame( 42, (int) $row['organization_id'] );

		// The denormalized columns are the reason this table exists: without
		// them the serving path is still seven postmeta joins.
		$this->assertSame( 'https://example.com/spring', (string) $row['click_url'] );
		$this->assertSame( 'Spring sale', (string) $row['alt_text'] );
		$this->assertSame( 728, (int) $row['width'] );
		$this->assertSame( 90, (int) $row['height'] );
	}

	/**
	 * Running twice creates one row, not two.
	 *
	 * The unique key is what guarantees this, so the assertion is a *count*
	 * rather than "a row exists" — the second would pass whether the backfill
	 * created one row or three.
	 */
	public function test_running_the_backfill_twice_is_idempotent(): void {
		$made = $this->campaign_with_creative();

		$this->migrator->migrate_one( $made['creative'] );
		$this->migrator->migrate_one( $made['creative'] );
		$this->migrator->migrate_one( $made['creative'] );

		$this->assertCount(
			1,
			$this->assignments->for_campaign( $made['campaign'] ),
			'Repeat runs created duplicate assignments.'
		);
	}

	/**
	 * Every revision of one artwork resolves to the same asset.
	 *
	 * A replacement chain is what P2 calls an asset, so two creatives linked by
	 * `_aggr_replaces_creative_id` must not become two assets — that would make
	 * "the same artwork, reused" mean nothing.
	 */
	public function test_a_replacement_chain_shares_one_asset(): void {
		$first  = $this->campaign_with_creative();
		$second = $this->campaign_with_creative();

		// The second creative replaces the first: one artwork, two revisions.
		update_post_meta(
			$second['creative'],
			Creative_Repository::META_REPLACES_ID,
			$first['creative']
		);

		$a = $this->migrator->migrate_one( $first['creative'] );
		$b = $this->migrator->migrate_one( $second['creative'] );

		$this->assertIsArray( $a );
		$this->assertIsArray( $b );
		$this->assertGreaterThan( 0, (int) $a['asset_id'], 'No asset was created.' );
		$this->assertSame(
			(int) $a['asset_id'],
			(int) $b['asset_id'],
			'Two revisions of one artwork were given different assets.'
		);
	}

	/**
	 * A creative that can never be assigned is skipped, not fatal.
	 *
	 * A creative with no campaign or no placement has nowhere to be assigned
	 * and never will. Stopping on it would wedge the backfill on one row
	 * forever, which is the failure mode that makes a migration unresumable.
	 */
	public function test_an_unmigratable_creative_does_not_wedge_the_backfill(): void {
		$orphan = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$good = $this->campaign_with_creative();

		$this->assertNull(
			$this->migrator->migrate_one( $orphan ),
			'An orphan creative should produce no assignment.'
		);

		$this->migrator->run_batch();

		$line_item = $this->line_items->default_for_campaign( $good['campaign'] );

		$this->assertIsArray(
			$this->assignments->compatibility_row( (int) $line_item['id'], $good['placement'] ),
			'The orphan stopped the batch before it reached a migratable creative.'
		);
	}

	/**
	 * The cursor advances and completion is marked only at the end.
	 *
	 * Marking done before the id space is exhausted is the P1 defect this
	 * pattern already paid for once.
	 */
	public function test_a_finished_batch_marks_completion_and_releases_the_hook(): void {
		$this->campaign_with_creative();

		$this->migrator->start();
		$this->assertNotFalse( wp_next_scheduled( Creative_Assignment_Migrator::HOOK ) );

		$this->migrator->run_batch();

		$this->assertTrue( $this->migrator->is_complete(), 'The backfill did not finish.' );
		$this->assertFalse(
			wp_next_scheduled( Creative_Assignment_Migrator::HOOK ),
			'A completed backfill left a cron event behind.'
		);
	}

	/**
	 * A full batch is not the end of the id space.
	 *
	 * This exists because sabotage found the gap: with one creative in the
	 * fixture, `count( $ids ) < BATCH_SIZE` is true whatever the code does, so
	 * replacing the completion condition with `true` left every other test
	 * green. Marking done early is the exact defect the P1 migration paid for
	 * once — the backfill stops at row 100 and reports success.
	 *
	 * The creatives here are deliberately bare. They have no campaign, so each
	 * is skipped in microseconds; what is under test is the batch arithmetic,
	 * not the per-row work.
	 */
	public function test_a_full_batch_does_not_mark_the_backfill_complete(): void {
		for ( $i = 0; $i < Creative_Assignment_Migrator::BATCH_SIZE; $i++ ) {
			self::factory()->post->create(
				array(
					'post_type'   => Post_Types::CREATIVE,
					'post_status' => 'publish',
				)
			);
		}

		$this->migrator->start();

		$visited = $this->migrator->run_batch();

		$this->assertSame(
			Creative_Assignment_Migrator::BATCH_SIZE,
			$visited,
			'The fixture did not produce a full batch, so this proves nothing.'
		);
		$this->assertFalse(
			$this->migrator->is_complete(),
			'A full batch was treated as the end of the creative id space.'
		);
		$this->assertNotFalse(
			wp_next_scheduled( Creative_Assignment_Migrator::HOOK ),
			'A full batch left nothing scheduled to continue the work.'
		);

		// And the next batch, which finds nothing more, does finish.
		$this->migrator->run_batch();

		$this->assertTrue( $this->migrator->is_complete() );
	}

	/** A complete backfill schedules nothing on init. */
	public function test_a_complete_backfill_schedules_nothing(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1, false );

		$this->migrator->init();

		$this->assertFalse( wp_next_scheduled( Creative_Assignment_Migrator::HOOK ) );
	}

	/** An unfinished backfill is resumed by runtime initialization. */
	public function test_an_unfinished_backfill_is_resumed_on_init(): void {
		$this->migrator->init();

		$this->assertNotFalse(
			wp_next_scheduled( Creative_Assignment_Migrator::HOOK ),
			'A lost cron event would strand the backfill with nothing able to wake it.'
		);
	}

	/**
	 * Deleting a campaign removes its assignments.
	 *
	 * The destructive half, and the one worth a count: "the row is gone" passes
	 * whether one row was deleted or the whole table.
	 */
	public function test_deleting_a_campaign_removes_its_assignments_and_nothing_else(): void {
		$doomed   = $this->campaign_with_creative();
		$survivor = $this->campaign_with_creative();

		$this->migrator->run_batch();

		$this->assertCount( 1, $this->assignments->for_campaign( $doomed['campaign'] ) );
		$this->assertCount( 1, $this->assignments->for_campaign( $survivor['campaign'] ) );

		wp_delete_post( $doomed['campaign'], true );

		$this->assertCount(
			0,
			$this->assignments->for_campaign( $doomed['campaign'] ),
			'Deleting a campaign left its assignments behind.'
		);

		// The negative half. A delete_for_campaign that ignored its argument
		// would satisfy the assertion above and destroy every tenant's rows.
		$this->assertCount(
			1,
			$this->assignments->for_campaign( $survivor['campaign'] ),
			'Deleting one campaign removed another campaign assignments.'
		);
	}
}
