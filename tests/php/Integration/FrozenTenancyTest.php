<?php
/**
 * Tenancy is a historical fact about a delivery, not a current one about a campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Repository\Rollup_Report_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Event_Recorder;
use WP_UnitTestCase;

/**
 * **The assertion the whole decision rests on.**
 *
 * Org totals used to be produced by joining `_aggr_org_id` off the campaign at
 * read time. That made tenancy a *current* fact about the campaign rather than
 * a historical fact about the delivery: moving a campaign between
 * organizations moved its past totals with it, in both directions, and nothing
 * recorded that it had happened. Tenancy decides who may read a number, so it
 * is the one label that cannot be re-resolved.
 *
 * These go through `Event_Recorder` rather than writing rollup rows, because
 * the freeze is only real if the production write path performs it. A fixture
 * that set `org_id` itself would prove the column exists and nothing else.
 */
final class FrozenTenancyTest extends WP_UnitTestCase {

	/**
	 * Projection under test.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * Org-scoped, range-bounded reads.
	 *
	 * @var Rollup_Report_Repository
	 */
	private Rollup_Report_Repository $reports;

	/**
	 * Production write path.
	 *
	 * @var Event_Recorder
	 */
	private Event_Recorder $recorder;

	/**
	 * Assignments, which carry the attribution the recorder resolves.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->rollups = $container->get( Rollup_Repository::class );
		$this->reports = $container->get( Rollup_Report_Repository::class );
		$this->rollups->install_table();

		$container->get( Event_Repository::class )->install_table();

		$this->assignments = $container->get( Creative_Assignment_Repository::class );
		$this->assignments->install_table();

		$this->recorder = $container->get( Event_Recorder::class );
	}

	/**
	 * A campaign owned by one organization, with an assignment to serve from.
	 *
	 * @param int $org_id Owning organization.
	 * @return array{campaign: int, creative: int, placement: int}
	 */
	private function serving_campaign( int $org_id ): array {
		global $wpdb;

		$campaign  = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CAMPAIGN ) );
		$creative  = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CREATIVE ) );
		$placement = (int) self::factory()->post->create( array( 'post_type' => Post_Types::PLACEMENT ) );

		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, $org_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding this plugin's own table.
		$wpdb->insert(
			$this->assignments->table_name(),
			array(
				'line_item_id' => $campaign,
				'campaign_id'  => $campaign,
				'placement_id' => $placement,
				'revision_id'  => $creative,
				'status'       => 'live',
				'weight'       => 100,
				'revision'     => 1,
			)
		);

		return array(
			'campaign'  => $campaign,
			'creative'  => $creative,
			'placement' => $placement,
		);
	}

	/**
	 * Records one impression through the production path.
	 *
	 * @param array{campaign: int, creative: int, placement: int} $fixture Serving fixture.
	 * @param string                                              $salt    Makes the replay key unique.
	 */
	private function serve( array $fixture, string $salt ): void {
		$this->assertSame(
			Event_Recorder::RECORDED,
			$this->recorder->record(
				Event_Repository::TYPE_SERVED,
				$fixture['placement'],
				$fixture['campaign'],
				$fixture['creative'],
				hash( 'sha256', $salt ),
				str_repeat( 'b', 64 )
			)
		);
	}

	/**
	 * The frozen value on today's row for one campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	private function stored_org_for( int $campaign_id ): int {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT org_id FROM {$table} WHERE campaign_id = %d LIMIT 1", $campaign_id ) );
	}

	/**
	 * The production write path freezes the organization onto the row.
	 */
	public function test_recording_a_delivery_freezes_the_owning_organization(): void {
		$fixture = $this->serving_campaign( 4242 );

		$this->serve( $fixture, 'one' );

		$this->assertSame(
			4242,
			$this->stored_org_for( $fixture['campaign'] ),
			'The recorder did not freeze tenancy, so reporting is still a read-time join.'
		);
	}

	/**
	 * **Moving a campaign does not move its history.**
	 *
	 * The behaviour the column exists for. Before it, this assertion was not
	 * merely unproven — it was false in both directions: the old organization's
	 * totals fell and the new one's rose, for deliveries the new organization
	 * never made.
	 */
	public function test_moving_a_campaign_leaves_its_past_totals_where_they_were(): void {
		$fixture = $this->serving_campaign( 100 );

		$this->serve( $fixture, 'before' );

		$before_old = $this->reports->totals_for_org( 100, $this->window() );
		$this->assertSame( 1, $before_old['impressions'], 'Without a delivery on the books this test proves nothing.' );

		// The campaign changes hands.
		update_post_meta( $fixture['campaign'], Campaign_Repository::META_ORG_ID, 200 );

		$this->assertSame(
			1,
			$this->reports->totals_for_org( 100, $this->window() )['impressions'],
			'The former organization lost a delivery it really made.'
		);
		$this->assertSame(
			0,
			$this->reports->totals_for_org( 200, $this->window() )['impressions'],
			'The new organization was credited with a delivery it never made.'
		);
	}

	/**
	 * And the reconciler, which rebuilds closed days on a schedule, leaves the
	 * frozen value alone.
	 *
	 * This is the half that would undo the freeze silently: a nightly job that
	 * re-derives tenancy puts the drift back every night, and does it in code
	 * whose whole purpose is to guarantee accuracy.
	 */
	public function test_reconciling_a_day_does_not_re_derive_tenancy(): void {
		$fixture = $this->serving_campaign( 100 );

		$this->serve( $fixture, 'recon' );

		update_post_meta( $fixture['campaign'], Campaign_Repository::META_ORG_ID, 200 );

		$this->assertTrue( $this->rollups->reconcile_day( gmdate( 'Y-m-d' ) ) );

		$this->assertSame(
			100,
			$this->stored_org_for( $fixture['campaign'] ),
			'The reconciler re-derived tenancy and undid the freeze.'
		);
	}

	/**
	 * A row that has no organization yet takes the first real answer.
	 *
	 * The asymmetry that makes filling safe while changing is not: a row
	 * written before the column existed, or created by a conversion days before
	 * any delivery, must still become attributable.
	 */
	public function test_a_row_without_an_organization_is_filled_rather_than_left_at_zero(): void {
		$fixture = $this->serving_campaign( 777 );

		/*
		 * A counter as an older release wrote it: no organization named, and on
		 * the same key the recorder will write to. The line item matters — the
		 * unique key includes it, so a different value here would create a
		 * second row and the fill below would never be exercised.
		 */
		$this->rollups->increment( 'impressions', $fixture['placement'], $fixture['campaign'], '', $fixture['campaign'] );

		$this->assertSame( 0, $this->stored_org_for( $fixture['campaign'] ) );

		$this->serve( $fixture, 'fill' );

		$this->assertSame(
			777,
			$this->stored_org_for( $fixture['campaign'] ),
			'A row with no organization stayed unattributable, so its campaign is invisible to reporting for ever.'
		);
	}

	/**
	 * House deliveries belong to nobody, and must not land in an org total.
	 */
	public function test_a_house_delivery_is_never_attributed_to_an_organization(): void {
		$fixture = $this->serving_campaign( 100 );

		$this->recorder->record(
			Event_Repository::TYPE_SERVED,
			$fixture['placement'],
			0,
			$fixture['creative'],
			hash( 'sha256', 'house' ),
			str_repeat( 'b', 64 )
		);

		$this->assertSame( 0, $this->reports->totals_for_org( 100, $this->window() )['impressions'] );
		$this->assertSame( 0, $this->stored_org_for( 0 ), 'A house row carried an organization.' );
	}

	/**
	 * The projector stamps which code wrote the counters.
	 */
	public function test_rows_record_the_projector_that_wrote_them(): void {
		global $wpdb;

		$fixture = $this->serving_campaign( 100 );

		$this->serve( $fixture, 'stamp' );

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$stamped = (int) $wpdb->get_var( $wpdb->prepare( "SELECT projector_version FROM {$table} WHERE campaign_id = %d LIMIT 1", $fixture['campaign'] ) );

		$this->assertSame( Rollup_Repository::PROJECTOR_VERSION, $stamped );
	}

	/**
	 * The declared schema and the installed table agree on the new columns.
	 */
	public function test_the_new_dimensions_are_installed(): void {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		$this->assertContains( 'org_id', $columns );
		$this->assertContains( 'projector_version', $columns );

		/*
		 * Sorted, because `dbDelta` appends a new column to an existing table
		 * rather than inserting it where the DDL declares it. Asserting stored
		 * order would pass on a fresh install and fail on every upgraded one —
		 * the opposite of what this needs to check.
		 */
		$declared = Schema::rollups_columns();
		sort( $declared );
		sort( $columns );

		$this->assertSame( $declared, $columns );

		$events = $wpdb->prefix . Schema::EVENTS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$event_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$events}" );

		$this->assertContains( 'schema_version', $event_columns );
	}

	/**
	 * The range every org read in this file uses.
	 *
	 * The fixtures deliver today, so any window ending today contains them.
	 * Reads are bounded by type now, which is the point: there is no unbounded
	 * org total left to call by accident.
	 */
	private function window(): Report_Period {
		return Report_Period::trailing( 30, gmdate( 'Y-m-d' ) );
	}
}
