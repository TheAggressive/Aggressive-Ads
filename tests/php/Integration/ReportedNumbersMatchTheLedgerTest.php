<?php
/**
 * What a customer is shown equals what actually happened.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Measurement_Event_Type;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Event_Recorder;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_UnitTestCase;

/**
 * The end-to-end property the reporting screens rest on, asserted in the fast
 * suites rather than only in the load harness.
 *
 * `aggr_events` is the durable ledger — one row per acknowledged beacon.
 * `aggr_rollups` is the projection every screen reads, written synchronously
 * on the fill path and rebuilt from the ledger for closed days. The reports are
 * only trustworthy while those two agree, and nothing in the fast suites
 * compared them: the exact match on record was measured once, at scale, by
 * `bin/load`.
 *
 * A projection that drifts from its ledger is the worst failure this plugin
 * has, because it is invisible. Every screen keeps working and every figure is
 * wrong, and an advertiser billed against those figures has no way to tell.
 */
final class ReportedNumbersMatchTheLedgerTest extends WP_UnitTestCase {

	/**
	 * Durable ledger.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * The projection screens read.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * Production write path for a beacon.
	 *
	 * @var Event_Recorder
	 */
	private Event_Recorder $recorder;

	/**
	 * Owning organization.
	 *
	 * @var int
	 */
	private int $org_id = 0;

	/**
	 * Campaign the events belong to.
	 *
	 * @var int
	 */
	private int $campaign_id = 0;

	/**
	 * Placement the events belong to.
	 *
	 * @var int
	 */
	private int $placement_id = 0;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$container      = Plugin::instance()->container();
		$this->events   = $container->get( Event_Repository::class );
		$this->rollups  = $container->get( Rollup_Repository::class );
		$this->recorder = $container->get( Event_Recorder::class );

		$this->events->install_table();
		$this->rollups->install_table();

		$this->org_id       = (int) self::factory()->post->create( array( 'post_type' => Post_Types::ORGANIZATION ) );
		$this->placement_id = (int) self::factory()->post->create( array( 'post_type' => Post_Types::PLACEMENT ) );
		$this->campaign_id  = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CAMPAIGN ) );

		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
	}

	/**
	 * Records one beacon through the production path.
	 *
	 * @param string $token Token the browser presented.
	 * @param string $type  Measurement event type.
	 * @return string Outcome the recorder reported.
	 */
	private function beacon( string $token, string $type = Measurement_Event_Type::TYPE_SERVED ): string {
		return $this->recorder->record(
			$type,
			$this->placement_id,
			$this->campaign_id,
			0,
			hash( 'sha256', $token ),
			hash( 'sha256', '203.0.113.7' )
		);
	}

	/**
	 * Rows in the durable ledger for one event type.
	 *
	 * @param string $type Measurement event type.
	 * @return int
	 */
	private function ledger_count( string $type ): int {
		global $wpdb;

		$table = $this->events->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counting this plugin's own ledger in a test.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event = %s AND campaign_id = %d", $type, $this->campaign_id ) );
	}

	/**
	 * The figure a screen would show.
	 *
	 * @param string $column Rollup column.
	 * @return int
	 */
	private function reported( string $column ): int {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column name comes from this method's own closed set.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM({$column}), 0) FROM {$table} WHERE campaign_id = %d AND org_id = %d", $this->campaign_id, $this->org_id ) );
	}

	public function test_every_acknowledged_beacon_is_reported_exactly_once(): void {
		for ( $i = 1; $i <= 25; $i++ ) {
			$this->beacon( 'token-' . $i );
		}

		$this->assertSame( 25, $this->ledger_count( Measurement_Event_Type::TYPE_SERVED ) );
		$this->assertSame(
			$this->ledger_count( Measurement_Event_Type::TYPE_SERVED ),
			$this->reported( 'impressions' ),
			'The projection every screen reads disagreed with the ledger, which is the failure that keeps every figure wrong while nothing errors.'
		);
	}

	public function test_a_replayed_beacon_does_not_inflate_what_is_reported(): void {
		$this->beacon( 'replay-me' );
		$this->beacon( 'replay-me' );
		$this->beacon( 'replay-me' );

		$this->assertSame(
			1,
			$this->ledger_count( Measurement_Event_Type::TYPE_SERVED ),
			'The unique key over (token_hash, event) is what refuses the replay.'
		);
		$this->assertSame(
			1,
			$this->reported( 'impressions' ),
			'A replay that reached the projection would bill an advertiser for a view that happened once.'
		);
	}

	public function test_clicks_and_impressions_are_counted_separately(): void {
		$this->beacon( 'both', Measurement_Event_Type::TYPE_SERVED );
		$this->beacon( 'both', Measurement_Event_Type::TYPE_CLICK );

		$this->assertSame( 1, $this->reported( 'impressions' ) );
		$this->assertSame(
			1,
			$this->reported( 'clicks' ),
			'The replay key is the token and the event together: one token may produce a view and a click, and refusing the second would lose every click.'
		);
	}

	/**
	 * **Reconciliation rebuilds a closed day and lands on the same number.**
	 *
	 * The synchronous increment and the rebuild are two ways of arriving at one
	 * figure, and the whole point of the rebuild is that it is exact. If they
	 * disagreed, reconciliation would silently rewrite correct reports into
	 * wrong ones every hour.
	 */
	public function test_rebuilding_a_closed_day_does_not_change_the_number(): void {
		$day = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );

		for ( $i = 1; $i <= 12; $i++ ) {
			$this->events->insert(
				Measurement_Event_Type::TYPE_SERVED,
				$this->placement_id,
				$this->campaign_id,
				0,
				hash( 'sha256', 'closed-' . $i ),
				hash( 'sha256', '203.0.113.7' )
			);
		}

		/*
		 * Moved onto a closed day afterwards. The production path stamps
		 * "now", so arranging a day the reconciler will rebuild is a fixture
		 * concern — the rows themselves were written by the real insert, which
		 * is the half that has to be genuine.
		 */
		global $wpdb;

		$events_table = $this->events->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Backdating this plugin's own ledger rows to arrange a closed day.
		$wpdb->query( $wpdb->prepare( "UPDATE {$events_table} SET created_at_ts = %d WHERE campaign_id = %d", (int) strtotime( $day . ' 12:00:00 UTC' ), $this->campaign_id ) );

		$this->rollups->increment( 'impressions', $this->placement_id, $this->campaign_id, $day, 0, $this->org_id );

		$before = $this->reported( 'impressions' );

		$this->assertSame( 1, $before, 'The synchronous path recorded one; the ledger holds twelve.' );

		update_option( Rollup_Reconciler::OPTION, gmdate( 'Y-m-d', time() - 4 * DAY_IN_SECONDS ), false );

		Plugin::instance()->container()->get( Rollup_Reconciler::class )->run();

		$this->assertSame(
			12,
			$this->reported( 'impressions' ),
			'Reconciliation is the exact rebuild, so a day it has closed must equal its ledger rather than whatever the synchronous path managed.'
		);
		$this->assertSame( $this->ledger_count( Measurement_Event_Type::TYPE_SERVED ), $this->reported( 'impressions' ) );
	}

	public function test_another_organizations_delivery_is_never_reported_here(): void {
		$other_org      = (int) self::factory()->post->create( array( 'post_type' => Post_Types::ORGANIZATION ) );
		$other_campaign = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CAMPAIGN ) );

		update_post_meta( $other_campaign, Campaign_Repository::META_ORG_ID, $other_org );

		$this->rollups->increment( 'impressions', $this->placement_id, $other_campaign, gmdate( 'Y-m-d' ), 0, $other_org );

		$this->beacon( 'mine' );

		$this->assertSame(
			1,
			$this->reported( 'impressions' ),
			'A figure that included another tenant would show an advertiser delivery they did not buy.'
		);
	}
}
