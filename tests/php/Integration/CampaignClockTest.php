<?php
/**
 * The clock-driven half of the lifecycle.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Clock;
use WP_UnitTestCase;

/**
 * Campaign_Clock against real WordPress.
 *
 * The assertions that matter are the refusals. A reconciler that moves
 * everything forward is trivially "working" and would put an unstarted campaign
 * live; the value is that it moves a campaign at exactly the moment the clock
 * says so and not before.
 */
final class CampaignClockTest extends WP_UnitTestCase {

	/**
	 * The reconciler under test.
	 *
	 * @var Campaign_Clock
	 */
	private Campaign_Clock $clock;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * Owning organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Sets up roles and an active organization.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->clock     = Plugin::instance()->container()->get( Campaign_Clock::class );
		$this->campaigns = Plugin::instance()->container()->get( Campaign_Repository::class );

		$advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $advertiser );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * A campaign in a given status and window.
	 *
	 * @param string $status    Campaign status.
	 * @param int    $start     Start timestamp.
	 * @param int    $end       End timestamp.
	 * @param bool   $published Whether it carries a provider ad id.
	 * @return int
	 */
	private function campaign( string $status, int $start, int $end, bool $published = true ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, $start );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, $end );

		if ( $published ) {
			$this->campaigns->add_provider_ad_id( $campaign_id, 4242 );
		}

		return $campaign_id;
	}

	/**
	 * An approved campaign whose window has not opened becomes scheduled.
	 *
	 * @return void
	 */
	public function test_an_approved_future_campaign_becomes_scheduled(): void {
		$campaign_id = $this->campaign(
			Post_Statuses::APPROVED,
			time() + WEEK_IN_SECONDS,
			time() + ( 2 * WEEK_IN_SECONDS )
		);

		$this->assertSame( 1, $this->clock->reconcile() );
		$this->assertSame( Post_Statuses::SCHEDULED, $this->campaigns->status( $campaign_id ) );
	}

	/**
	 * A scheduled campaign whose start has passed goes live.
	 *
	 * @return void
	 */
	public function test_a_scheduled_campaign_goes_live_once_it_starts(): void {
		$campaign_id = $this->campaign(
			Post_Statuses::SCHEDULED,
			time() - HOUR_IN_SECONDS,
			time() + WEEK_IN_SECONDS
		);

		$this->assertSame( 1, $this->clock->reconcile() );
		$this->assertSame( Post_Statuses::LIVE, $this->campaigns->status( $campaign_id ) );
	}

	/**
	 * **A scheduled campaign that has not started stays scheduled.**
	 *
	 * The single most important refusal here: taking this edge early puts an
	 * advertisement in front of the public before the day it was sold for.
	 *
	 * @return void
	 */
	public function test_a_scheduled_campaign_does_not_go_live_early(): void {
		$campaign_id = $this->campaign(
			Post_Statuses::SCHEDULED,
			time() + DAY_IN_SECONDS,
			time() + WEEK_IN_SECONDS
		);

		$this->assertSame( 0, $this->clock->reconcile() );
		$this->assertSame( Post_Statuses::SCHEDULED, $this->campaigns->status( $campaign_id ) );
	}

	/**
	 * A live campaign past its end date completes.
	 *
	 * @return void
	 */
	public function test_a_live_campaign_completes_once_it_ends(): void {
		$campaign_id = $this->campaign(
			Post_Statuses::LIVE,
			time() - ( 2 * WEEK_IN_SECONDS ),
			time() - HOUR_IN_SECONDS
		);

		$this->assertSame( 1, $this->clock->reconcile() );
		$this->assertSame( Post_Statuses::COMPLETE, $this->campaigns->status( $campaign_id ) );
	}

	/**
	 * A live campaign still inside its window is left alone.
	 *
	 * @return void
	 */
	public function test_a_running_campaign_is_left_alone(): void {
		$campaign_id = $this->campaign(
			Post_Statuses::LIVE,
			time() - DAY_IN_SECONDS,
			time() + DAY_IN_SECONDS
		);

		$this->assertSame( 0, $this->clock->reconcile() );
		$this->assertSame( Post_Statuses::LIVE, $this->campaigns->status( $campaign_id ) );
	}

	/**
	 * A campaign with no end date runs indefinitely rather than completing.
	 *
	 * An open-ended campaign stores 0, and a naive "end has passed" comparison
	 * reads 0 as 1970 and completes it on the first sweep — the failure that
	 * silently ends every open-ended campaign an hour after it goes live.
	 *
	 * @return void
	 */
	public function test_an_open_ended_campaign_does_not_complete(): void {
		$campaign_id = $this->campaign( Post_Statuses::LIVE, time() - DAY_IN_SECONDS, 0 );

		$this->assertSame( 0, $this->clock->reconcile() );
		$this->assertSame( Post_Statuses::LIVE, $this->campaigns->status( $campaign_id ) );
	}

	/**
	 * An approved campaign advances on the clock without a provider ad id.
	 *
	 * Native fill reads campaign status. There is no downstream ad CPT to wait
	 * for, so an approved window that has opened goes live.
	 *
	 * @return void
	 */
	public function test_an_approved_campaign_with_no_provider_ad_advances(): void {
		$campaign_id = $this->campaign(
			Post_Statuses::APPROVED,
			time() - HOUR_IN_SECONDS,
			time() + WEEK_IN_SECONDS,
			false
		);

		$this->assertSame( 1, $this->clock->reconcile() );
		$this->assertSame( Post_Statuses::LIVE, $this->campaigns->status( $campaign_id ) );
	}

	/**
	 * A campaign approved after its own window closed crosses both edges at once.
	 *
	 * @return void
	 */
	public function test_a_late_approval_catches_up_in_one_sweep(): void {
		$campaign_id = $this->campaign(
			Post_Statuses::APPROVED,
			time() - ( 2 * WEEK_IN_SECONDS ),
			time() - DAY_IN_SECONDS
		);

		$this->assertSame( 1, $this->clock->reconcile() );
		$this->assertSame( Post_Statuses::COMPLETE, $this->campaigns->status( $campaign_id ) );
	}

	/**
	 * Statuses the clock has no business touching are not touched.
	 *
	 * @return void
	 */
	public function test_non_system_statuses_are_untouched(): void {
		$untouched = array();

		foreach ( array( Post_Statuses::DRAFT, Post_Statuses::SUBMITTED, Post_Statuses::REVIEW, Post_Statuses::PAUSED ) as $status ) {
			$untouched[ $status ] = $this->campaign( $status, time() - WEEK_IN_SECONDS, time() - DAY_IN_SECONDS );
		}

		$this->assertSame( 0, $this->clock->reconcile() );

		foreach ( $untouched as $status => $campaign_id ) {
			$this->assertSame( $status, $this->campaigns->status( $campaign_id ) );
		}
	}

	/**
	 * A second sweep changes nothing, so a doubled cron run is harmless.
	 *
	 * @return void
	 */
	public function test_reconciling_twice_is_idempotent(): void {
		$this->campaign( Post_Statuses::SCHEDULED, time() - HOUR_IN_SECONDS, time() + WEEK_IN_SECONDS );

		$this->assertSame( 1, $this->clock->reconcile() );
		$this->assertSame( 0, $this->clock->reconcile() );
	}

	/**
	 * Every transition the clock makes is recorded.
	 *
	 * "When did this go live" is a billing question, and a status written
	 * without an audit row is a status nobody can account for later.
	 *
	 * @return void
	 */
	public function test_a_clock_transition_is_audited(): void {
		$campaign_id = $this->campaign( Post_Statuses::SCHEDULED, time() - HOUR_IN_SECONDS, time() + WEEK_IN_SECONDS );

		$this->clock->reconcile();

		$events = Plugin::instance()->container()->get( Audit_Repository::class )
			->for_object( 'campaign', $campaign_id, $this->org_id );

		$transitions = array();

		foreach ( $events as $event ) {
			if ( Post_Statuses::SCHEDULED === $event['from_state'] && Post_Statuses::LIVE === $event['to_state'] ) {
				$transitions[] = $event;
			}
		}

		$this->assertCount( 1, $transitions, 'The clock must record going live exactly once.' );
		$this->assertSame( 0, $transitions[0]['actor_user_id'], 'A clock transition has no acting user.' );
	}

	/**
	 * The sweep covers every status the table gives the system an exit from.
	 *
	 * Derived from Transition_Table rather than listed, so a fifth system edge
	 * cannot be added without its source joining the sweep.
	 *
	 * @return void
	 */
	public function test_the_sweep_covers_every_system_source(): void {
		$this->assertSame(
			array( Post_Statuses::APPROVED, Post_Statuses::SCHEDULED, Post_Statuses::LIVE ),
			Transition_Table::system_sources()
		);
	}

	/**
	 * The hourly event is scheduled, and scheduling it twice does not double it.
	 *
	 * @return void
	 */
	public function test_the_sweep_is_scheduled_exactly_once(): void {
		wp_clear_scheduled_hook( Campaign_Clock::HOOK );

		$this->clock->ensure_scheduled();
		$first = wp_next_scheduled( Campaign_Clock::HOOK );

		$this->assertIsInt( $first );

		$this->clock->ensure_scheduled();

		$this->assertSame( $first, wp_next_scheduled( Campaign_Clock::HOOK ) );
		$this->assertSame( Campaign_Clock::RECURRENCE, wp_get_schedule( Campaign_Clock::HOOK ) );
	}

	/**
	 * A sweep left on the wrong recurrence by an older release is repaired.
	 *
	 * Checking only that *something* is scheduled leaves a site upgraded from a
	 * release with a different interval sweeping on the old one forever, and a
	 * change to RECURRENCE that reached new installs only.
	 *
	 * @return void
	 */
	public function test_a_drifted_recurrence_is_repaired(): void {
		wp_clear_scheduled_hook( Campaign_Clock::HOOK );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Campaign_Clock::HOOK );

		$this->assertSame( 'daily', wp_get_schedule( Campaign_Clock::HOOK ) );

		$this->clock->ensure_scheduled();

		$this->assertSame( Campaign_Clock::RECURRENCE, wp_get_schedule( Campaign_Clock::HOOK ) );
	}
}
