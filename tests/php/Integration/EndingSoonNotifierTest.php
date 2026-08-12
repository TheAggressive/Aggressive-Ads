<?php
/**
 * Ending-soon reminders against real WordPress.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Notification\Ending_Soon_Mailer;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Roles;
use LAAO_Advertiser_Portal\Workflow\Ending_Soon_Notifier;
use WP_UnitTestCase;

/**
 * Proves the seven-day window, receipt suppression and open-ended exclusion.
 */
final class EndingSoonNotifierTest extends WP_UnitTestCase {

	/**
	 * @var Ending_Soon_Notifier
	 */
	private Ending_Soon_Notifier $notifier;

	/**
	 * @var Ending_Soon_Mailer
	 */
	private Ending_Soon_Mailer $mailer;

	/**
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * @var int
	 */
	private int $org_id;

	/**
	 * @var int
	 */
	private int $member_id;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container       = Plugin::instance()->container();
		$this->notifier  = $container->get( Ending_Soon_Notifier::class );
		$this->mailer    = $container->get( Ending_Soon_Mailer::class );
		$this->campaigns = $container->get( Campaign_Repository::class );
		$this->audit     = $container->get( Audit_Repository::class );

		$this->member_id = (int) self::factory()->user->create(
			array(
				'role'  => Roles::ADVERTISER,
				'email' => 'ending-soon@example.com',
			)
		);

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->member_id );
		$container->get( Ownership::class )->flush_cache();

		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		parent::tear_down();
	}

	/**
	 * @param null|bool            $short_circuit Unused.
	 * @param array<string, mixed> $atts          Mail attributes.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, array $atts ): bool {
		$this->mail[] = $atts;

		return true;
	}

	/**
	 * A live campaign ending inside the window notifies once.
	 *
	 * @return void
	 */
	public function test_a_live_campaign_ending_soon_is_notified_once(): void {
		$end         = time() + ( 3 * DAY_IN_SECONDS );
		$campaign_id = $this->campaign( Post_Statuses::LIVE, $end );

		$this->assertSame( 1, $this->notifier->notify() );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'ending soon', strtolower( (string) $this->mail[0]['subject'] ) );

		$this->assertSame( 1, $this->notifier->notify() );
		$this->assertCount( 1, $this->mail, 'Receipts must suppress a second send for the same end_ts.' );

		$events = $this->audit->for_object( 'campaign', $campaign_id, $this->org_id );
		$this->assertSame( 'campaign.ending_soon_notified', $events[0]['event'] );
	}

	/**
	 * Open-ended and far-future campaigns stay silent.
	 *
	 * @return void
	 */
	public function test_open_ended_and_distant_campaigns_are_skipped(): void {
		$this->campaign( Post_Statuses::LIVE, 0 );
		$this->campaign( Post_Statuses::LIVE, time() + ( 30 * DAY_IN_SECONDS ) );
		$this->campaign( Post_Statuses::APPROVED, time() + ( 2 * DAY_IN_SECONDS ) );

		$this->assertSame( 0, $this->notifier->notify() );
		$this->assertSame( array(), $this->mail );
	}

	/**
	 * Extending the end date re-arms a later reminder.
	 *
	 * @return void
	 */
	public function test_a_new_end_date_sends_again(): void {
		$campaign_id = $this->campaign( Post_Statuses::LIVE, time() + ( 2 * DAY_IN_SECONDS ) );

		$this->mailer->notify( $campaign_id );
		$this->assertCount( 1, $this->mail );

		$new_end = time() + ( 5 * DAY_IN_SECONDS );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, $new_end );

		$this->mailer->notify( $campaign_id );
		$this->assertCount( 2, $this->mail );
	}

	/**
	 * The hourly event is scheduled and a drifted recurrence is repaired.
	 *
	 * @return void
	 */
	public function test_the_sweep_is_scheduled_and_repaired(): void {
		wp_clear_scheduled_hook( Ending_Soon_Notifier::HOOK );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Ending_Soon_Notifier::HOOK );

		$this->notifier->ensure_scheduled();

		$this->assertSame( Ending_Soon_Notifier::RECURRENCE, wp_get_schedule( Ending_Soon_Notifier::HOOK ) );
	}

	/**
	 * @param string $status Campaign status.
	 * @param int    $end_ts End timestamp.
	 * @return int
	 */
	private function campaign( string $status, int $end_ts ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_title'  => 'Ending soon fixture',
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, time() - DAY_IN_SECONDS );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, $end_ts );

		return $campaign_id;
	}
}
