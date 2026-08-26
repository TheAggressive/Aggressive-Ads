<?php
/**
 * The screens read assignments, and heal what the backfill has not reached.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Assignment_Health;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Workflow\Assigned_Creatives;
use WP_UnitTestCase;

/**
 * The first slice where anything depends on the P2 tables.
 *
 * Serving is still untouched, so the worst case here is a wrong row on a screen
 * rather than a wrong ad — which is exactly why this is the right place to
 * start depending on the backfill, and why these assertions are the only thing
 * standing between a wrong backfill and a publisher seeing it.
 *
 * The case that matters most is the healing one. A campaign the backfill has
 * not reached must still render, or opening an old campaign during an upgrade
 * shows an advertiser an empty creative list and looks like data loss.
 */
final class AssignedCreativesTest extends WP_UnitTestCase {

	/**
	 * Reader under test.
	 *
	 * @var Assigned_Creatives
	 */
	private Assigned_Creatives $assigned;

	/**
	 * Assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	/**
	 * Backfill, used to arrange migrated fixtures.
	 *
	 * @var Creative_Assignment_Migrator
	 */
	private Creative_Assignment_Migrator $migrator;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->assigned    = $container->get( Assigned_Creatives::class );
		$this->assignments = $container->get( Creative_Assignment_Repository::class );
		$this->migrator    = $container->get( Creative_Assignment_Migrator::class );

		$this->assignments->install_table();

		delete_option( Creative_Assignment_Migrator::OPTION_CURSOR );
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
	}

	public function tear_down(): void {
		delete_option( Creative_Assignment_Migrator::OPTION_CURSOR );
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
		wp_clear_scheduled_hook( Creative_Assignment_Migrator::HOOK );

		parent::tear_down();
	}

	/**
	 * A campaign with one creative on one placement.
	 *
	 * @return array{campaign: int, creative: int, placement: int}
	 */
	private function campaign_with_creative(): array {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, 7 );
		add_post_meta( $campaign, Campaign_Repository::META_PLACEMENT_ID, $placement );

		$creative = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $creative, Creative_Repository::META_CAMPAIGN_ID, $campaign );
		update_post_meta( $creative, Creative_Repository::META_ORG_ID, 7 );
		update_post_meta( $creative, Creative_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $creative, Creative_Repository::META_CLICK_URL, 'https://example.com/a' );
		update_post_meta( $creative, Creative_Repository::META_ALT_TEXT, 'Original' );
		update_post_meta( $creative, Creative_Repository::META_KIND, 'image' );
		update_post_meta( $creative, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative, Creative_Repository::META_HEIGHT, 90 );

		return array(
			'campaign'  => $campaign,
			'creative'  => $creative,
			'placement' => $placement,
		);
	}

	public function test_a_migrated_campaign_reads_its_revision_from_the_assignment(): void {
		$made = $this->campaign_with_creative();

		$this->migrator->migrate_one( $made['creative'] );

		$this->assertSame(
			array( $made['creative'] ),
			$this->assigned->revision_ids( $made['campaign'] )
		);
	}

	/**
	 * A campaign the backfill has not reached still renders.
	 *
	 * The failure this exists for: during an upgrade, opening an old campaign
	 * would otherwise show an advertiser an empty creative list, which is
	 * indistinguishable from their artwork having been deleted.
	 */
	public function test_an_unmigrated_campaign_heals_on_read(): void {
		$made = $this->campaign_with_creative();

		$this->assertCount(
			0,
			$this->assignments->for_campaign( $made['campaign'] ),
			'The fixture was already migrated, so healing proves nothing.'
		);

		$this->assertSame(
			array( $made['creative'] ),
			$this->assigned->revision_ids( $made['campaign'] ),
			'An unmigrated campaign rendered nothing instead of healing.'
		);

		// And the heal persisted, so the next read is a plain lookup.
		$this->assertCount( 1, $this->assignments->for_campaign( $made['campaign'] ) );
	}

	/**
	 * Healing twice creates one row.
	 *
	 * A count, not a presence check: the second would pass whether healing made
	 * one row or one per page view, and a lazy migration that writes on every
	 * read is a performance defect that looks like nothing at all.
	 */
	public function test_healing_is_idempotent_across_repeated_reads(): void {
		$made = $this->campaign_with_creative();

		$this->assigned->revision_ids( $made['campaign'] );
		$this->assigned->revision_ids( $made['campaign'] );
		$this->assigned->revision_ids( $made['campaign'] );

		$this->assertCount( 1, $this->assignments->for_campaign( $made['campaign'] ) );
	}

	/**
	 * A campaign with no creatives reads as empty rather than erroring.
	 *
	 * The heal path runs, finds nothing to create, and must not leave a row
	 * behind or throw. A draft campaign before any upload is the ordinary case.
	 */
	public function test_a_campaign_with_no_creatives_reads_empty(): void {
		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
			)
		);

		$this->assertSame( array(), $this->assigned->revision_ids( $campaign ) );
		$this->assertCount( 0, $this->assignments->for_campaign( $campaign ) );
	}

	/** One campaign's assignments never leak into another's read. */
	public function test_assignments_do_not_cross_campaigns(): void {
		$mine   = $this->campaign_with_creative();
		$theirs = $this->campaign_with_creative();

		$this->migrator->migrate_one( $mine['creative'] );
		$this->migrator->migrate_one( $theirs['creative'] );

		$this->assertSame( array( $mine['creative'] ), $this->assigned->revision_ids( $mine['campaign'] ) );
		$this->assertSame( array( $theirs['creative'] ), $this->assigned->revision_ids( $theirs['campaign'] ) );
	}

	/**
	 * A read of an already-migrated campaign writes nothing.
	 *
	 * This exists because sabotage found the gap: healing unconditionally on
	 * every read still leaves exactly one row, because the unique key refuses
	 * the duplicate. The count assertion above therefore cannot see it, and the
	 * defect is invisible in every way except the one that matters — a write
	 * attempt on every page view of every campaign screen.
	 *
	 * So this counts the writes rather than the rows.
	 *
	 * Worth recording what it does *not* catch, since the same sabotage still
	 * passes: healing unconditionally costs extra SELECTs, not writes, because
	 * `ensure()` returns the existing row before reaching its insert. The guard
	 * in `revision_ids()` is therefore an optimisation rather than a
	 * correctness boundary, and no test here pins it. What this does pin is the
	 * property that matters — a read of migrated data writes nothing.
	 */
	public function test_reading_a_migrated_campaign_attempts_no_writes(): void {
		$made = $this->campaign_with_creative();

		$this->migrator->migrate_one( $made['creative'] );

		$table  = $this->assignments->table_name();
		$writes = 0;

		$counter = static function ( $query ) use ( &$writes, $table ) {
			if ( is_string( $query ) && str_contains( $query, $table ) && preg_match( '/^\s*(INSERT|REPLACE)/i', $query ) ) {
				++$writes;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$this->assigned->revision_ids( $made['campaign'] );
		$this->assigned->revision_ids( $made['campaign'] );
		remove_filter( 'query', $counter );

		$this->assertSame(
			0,
			$writes,
			'Reading an already-migrated campaign attempted a write.'
		);
	}

	// --- Site Health ---

	/** The health check counts a creative with no assignment. */
	public function test_site_health_reports_an_unmigrated_creative(): void {
		$this->campaign_with_creative();

		$health = Plugin::instance()->container()->get( Assignment_Health::class );
		$result = $health->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'still running', $result['label'] );
	}

	/**
	 * A finished backfill with a leftover reads differently from an unfinished one.
	 *
	 * The distinction is the whole value of the check. A site mid-backfill is
	 * working as designed and must not be told it has a problem; a backfill
	 * that reports itself finished and left rows behind is worth looking at.
	 */
	public function test_site_health_distinguishes_running_from_stalled(): void {
		$this->campaign_with_creative();

		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1, false );

		$health = Plugin::instance()->container()->get( Assignment_Health::class );
		$result = $health->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'no delivery assignment', $result['label'] );
	}

	/** A fully migrated site reports good. */
	public function test_site_health_reports_good_when_everything_is_migrated(): void {
		$made = $this->campaign_with_creative();

		$this->migrator->migrate_one( $made['creative'] );
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1, false );

		$health = Plugin::instance()->container()->get( Assignment_Health::class );

		$this->assertSame( 'good', $health->run_test()['status'] );
	}
}
