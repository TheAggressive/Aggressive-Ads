<?php
/**
 * Campaign notifications against real WordPress users, roles, mail and meta.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Domain\Transition_Table;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Notification\Notification_Service;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\User_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Roles;
use LAAO_Advertiser_Portal\Workflow\Campaign_State_Machine;
use LAAO_Advertiser_Portal\Workflow\Transition_Guards;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Proves recipient resolution, private fan-out, deduplication and failure mode.
 */
final class NotificationServiceTest extends WP_UnitTestCase {

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Recipient persistence.
	 *
	 * @var User_Repository
	 */
	private User_Repository $users;

	/**
	 * Production notification service.
	 *
	 * @var Notification_Service
	 */
	private Notification_Service $service;

	/**
	 * Fixture advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Fixture organization post id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Every wp_mail call captured through WordPress's supported short circuit.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	/**
	 * Per-address mail outcomes used to model partial SMTP failure.
	 *
	 * @var array<string, bool>
	 */
	private array $mail_results = array();

	/**
	 * Installs roles and attaches a real wp_mail interception.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit     = new Audit_Repository();
		$this->campaigns = new Campaign_Repository();
		$this->users     = new User_Repository();

		( new Installer( $this->audit, new Roles() ) )->install_roles();

		$this->advertiser = self::factory()->user->create(
			array(
				'role'       => Roles::ADVERTISER,
				'user_email' => 'advertiser@example.test',
			)
		);
		$this->org_id     = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$this->service = Plugin::instance()->container()->get( Notification_Service::class );

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Removes the mail interception.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		parent::tear_down();
	}

	/**
	 * Captures a message and supplies its configured transport result.
	 *
	 * @param null|bool            $short_circuit Value from earlier filters.
	 * @param array<string, mixed> $mail          Normalized wp_mail arguments.
	 * @return bool
	 */
	public function capture_mail( null|bool $short_circuit, array $mail ): bool {
		$this->mail[] = $mail;

		$to      = $mail['to'] ?? '';
		$address = is_string( $to ) ? $to : '';

		return $this->mail_results[ $address ] ?? true;
	}

	/**
	 * Creates one campaign belonging to the fixture organization.
	 *
	 * @param string $status Campaign status.
	 * @param string $title  Campaign title.
	 * @return int
	 */
	private function campaign( string $status = Post_Statuses::SUBMITTED, string $title = 'Autumn arts guide' ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_author' => $this->advertiser,
				'post_title'  => $title,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $campaign_id;
	}

	/**
	 * Creates one reviewer with a stable address.
	 *
	 * @param string $email Reviewer address.
	 * @return int
	 */
	private function reviewer( string $email ): int {
		return self::factory()->user->create(
			array(
				'role'       => Roles::REVIEWER,
				'user_email' => $email,
			)
		);
	}

	/**
	 * The production listener is attached with all transition arguments.
	 *
	 * @return void
	 */
	public function test_notification_service_is_wired(): void {
		$this->assertSame(
			10,
			has_action( 'laao_ads_notify_campaign_transitioned', array( $this->service, 'campaign_transitioned' ) )
		);
		$this->assertSame( 10, has_action( Notification_Service::RETRY_HOOK, array( $this->service, 'retry_submission' ) ) );
	}

	/**
	 * Every capable user gets one private message; unrelated users get none.
	 *
	 * @return void
	 */
	public function test_submission_fans_out_to_current_capability_holders(): void {
		$first    = $this->reviewer( 'first-reviewer@example.test' );
		$second   = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'direct-grant@example.test',
			)
		);
		$filtered = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'filtered-grant@example.test',
			)
		);
		self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'editor@example.test',
			)
		);

		$direct_user = get_user_by( 'id', $second );
		$this->assertInstanceOf( \WP_User::class, $direct_user );
		$direct_user->add_cap( Capabilities::REVIEW_CAMPAIGNS );

		$dynamic_grant = static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $filtered ): array {
			if ( $user->ID === $filtered ) {
				$allcaps[ Capabilities::REVIEW_CAMPAIGNS ] = true;
			}

			return $allcaps;
		};
		add_filter( 'user_has_cap', $dynamic_grant, 10, 4 );

		$campaign = $this->campaign();
		$this->campaigns->set_internal_notes( $campaign, 'Never include this in email.' );

		$user_ids = array();

		try {
			$this->service->campaign_transitioned( $campaign, Post_Statuses::DRAFT, Post_Statuses::SUBMITTED );
			$user_ids = array_column( $this->users->with_capability( Capabilities::REVIEW_CAMPAIGNS ), 'id' );
		} finally {
			remove_filter( 'user_has_cap', $dynamic_grant, 10 );
		}

		$addresses = array_column( $this->mail, 'to' );

		$this->assertContains( (string) get_option( 'admin_email' ), $addresses );
		$this->assertContains( 'first-reviewer@example.test', $addresses );
		$this->assertContains( 'direct-grant@example.test', $addresses );
		$this->assertContains( 'filtered-grant@example.test', $addresses );
		$this->assertNotContains( 'editor@example.test', $addresses );
		$this->assertCount( 4, array_unique( $addresses ) );
		$this->assertContains( $first, $user_ids );
		$this->assertContains( $second, $user_ids );
		$this->assertContains( $filtered, $user_ids );

		foreach ( $this->mail as $mail ) {
			$this->assertIsString( $mail['to'] );
			$this->assertStringContainsString( 'New campaign submitted: Autumn arts guide', (string) $mail['subject'] );
			$this->assertStringNotContainsString( "\n", (string) $mail['subject'] );
			$this->assertStringContainsString( 'Organization: Bright Angle Media', (string) $mail['message'] );
			$this->assertStringContainsString( 'page=laao-ads-review', (string) $mail['message'] );
			$this->assertStringNotContainsString( 'Never include this in email.', (string) $mail['message'] );
			$this->assertStringNotContainsString( 'first-reviewer@example.test', (string) $mail['message'] );
			$this->assertStringNotContainsString( 'direct-grant@example.test', (string) $mail['message'] );
			$this->assertStringNotContainsString( 'filtered-grant@example.test', (string) $mail['message'] );
			$this->assertStringNotContainsString( '<html', strtolower( (string) $mail['message'] ) );
		}
	}

	/**
	 * A repeated hook does not resend or add a second success audit event.
	 *
	 * @return void
	 */
	public function test_duplicate_submission_notification_is_suppressed(): void {
		$this->reviewer( 'reviewer@example.test' );
		$campaign = $this->campaign();

		$this->service->campaign_transitioned( $campaign, Post_Statuses::DRAFT, Post_Statuses::SUBMITTED );
		$this->service->campaign_transitioned( $campaign, Post_Statuses::DRAFT, Post_Statuses::SUBMITTED );

		$this->assertCount( 2, $this->mail );
		$this->assertCount( 2, get_post_meta( $campaign, Campaign_Repository::META_NOTIFICATION_RECEIPT, false ) );

		$events = $this->audit->for_object( 'campaign', $campaign, $this->org_id );

		$this->assertSame( 1, count( array_filter( $events, static fn ( array $event ): bool => 'campaign.notification_queued' === $event['event'] ) ) );
	}

	/**
	 * A new campaign revision produces a new, clearly labelled message.
	 *
	 * @return void
	 */
	public function test_resubmission_sends_a_new_revision(): void {
		$this->reviewer( 'reviewer@example.test' );
		$campaign = $this->campaign();

		$this->service->campaign_transitioned( $campaign, Post_Statuses::DRAFT, Post_Statuses::SUBMITTED );
		$this->campaigns->increment_revision( $campaign );
		$this->service->campaign_transitioned( $campaign, Post_Statuses::CHANGES, Post_Statuses::SUBMITTED );

		$this->assertCount( 4, $this->mail );

		$resubmissions = array_slice( $this->mail, 2 );

		foreach ( $resubmissions as $mail ) {
			$this->assertStringContainsString( 'Campaign resubmitted: Autumn arts guide', (string) $mail['subject'] );
			$this->assertStringContainsString( 'Revision: 1', (string) $mail['message'] );
		}
	}

	/**
	 * Successful recipients stay suppressed while failed recipients can retry.
	 *
	 * @return void
	 */
	public function test_partial_failure_retries_only_the_failed_recipient(): void {
		$this->reviewer( 'failed@example.test' );
		$this->reviewer( 'sent@example.test' );
		$campaign = $this->campaign();

		$this->mail_results['failed@example.test'] = false;

		try {
			$this->service->campaign_transitioned( $campaign, Post_Statuses::DRAFT, Post_Statuses::SUBMITTED );
			$this->fail( 'A failed mail call did not report the notification failure.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( '1 campaign-review notification', $exception->getMessage() );
		}

		$this->assertSame( array( (string) get_option( 'admin_email' ), 'failed@example.test', 'sent@example.test' ), array_column( $this->mail, 'to' ) );
		$this->assertCount( 2, get_post_meta( $campaign, Campaign_Repository::META_NOTIFICATION_RECEIPT, false ) );
		$this->assertNotFalse( wp_next_scheduled( Notification_Service::RETRY_HOOK, array( $campaign, 0, 1 ) ) );

		$this->mail_results['failed@example.test'] = true;
		$this->service->retry_submission( $campaign, 0, 1 );

		$this->assertSame( 'failed@example.test', $this->mail[3]['to'] );
		$this->assertCount( 4, $this->mail );
		$this->assertCount( 3, get_post_meta( $campaign, Campaign_Repository::META_NOTIFICATION_RECEIPT, false ) );
	}

	/**
	 * A staff unclaim does not masquerade as new advertiser work.
	 *
	 * @return void
	 */
	public function test_staff_unclaim_does_not_send_a_submission_email(): void {
		$this->reviewer( 'reviewer@example.test' );

		$this->service->campaign_transitioned( $this->campaign(), Post_Statuses::REVIEW, Post_Statuses::SUBMITTED );

		$this->assertSame( array(), $this->mail );
	}

	/**
	 * A real delivery failure is audited without reversing the submitted state.
	 *
	 * @return void
	 */
	public function test_mail_failure_never_reverses_the_transition(): void {
		$this->reviewer( 'reviewer@example.test' );
		$this->mail_results['reviewer@example.test'] = false;

		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$machine  = new Campaign_State_Machine(
			$this->campaigns,
			$this->audit,
			new Transition_Guards(
				$this->campaigns,
				array( Transition_Table::GUARD_VALIDATOR => static fn (): bool => true )
			)
		);

		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::SUBMITTED ) );
		$this->assertSame( Post_Statuses::SUBMITTED, $this->campaigns->status( $campaign ) );
		$this->assertCount( 1, get_post_meta( $campaign, Campaign_Repository::META_NOTIFICATION_RECEIPT, false ) );

		$events = $this->audit->for_object( 'campaign', $campaign, $this->org_id );

		$this->assertContains( 'campaign.notification_failed', array_column( $events, 'event' ) );
		$this->assertNotFalse( wp_next_scheduled( Notification_Service::RETRY_HOOK, array( $campaign, 0, 1 ) ) );
	}

	/**
	 * The final failed retry stops and leaves an explicit exhaustion event.
	 *
	 * @return void
	 */
	public function test_notification_retries_are_bounded_and_audited(): void {
		$this->reviewer( 'reviewer@example.test' );
		$this->mail_results[ (string) get_option( 'admin_email' ) ] = false;
		$this->mail_results['reviewer@example.test']                = false;

		$campaign = $this->campaign();

		$this->service->retry_submission( $campaign, 0, 3 );

		$events = $this->audit->for_object( 'campaign', $campaign, $this->org_id );

		$this->assertContains( 'campaign.notification_retry_exhausted', array_column( $events, 'event' ) );
		$this->assertFalse( wp_next_scheduled( Notification_Service::RETRY_HOOK, array( $campaign, 0, 4 ) ) );
	}
}
