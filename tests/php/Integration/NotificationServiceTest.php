<?php
/**
 * Campaign notifications against real WordPress users, roles, mail and meta.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Notification\Notification_Service;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Transition_Guards;
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
			has_action( 'aggr_notify_campaign_transitioned', array( $this->service, 'campaign_transitioned' ) )
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
			$this->assertStringContainsString( 'page=aggr-review', (string) $mail['message'] );
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

	/**
	 * A member of the organization, so notices are not owner-only.
	 *
	 * @param string $email Member address.
	 * @return int
	 */
	private function member( string $email ): int {
		$user_id = self::factory()->user->create(
			array(
				'role'       => Roles::ADVERTISER,
				'user_email' => $email,
			)
		);

		add_post_meta( $this->org_id, Org_Repository::META_MEMBER_USER, $user_id );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $user_id;
	}

	/**
	 * **Requested changes reach the advertiser, carrying the feedback.**
	 *
	 * GUARD_REVIEW_NOTES forces a reviewer to write advertiser-facing feedback
	 * before this transition. Until the notice existed that feedback went
	 * nowhere: it sat on a screen the advertiser had no reason to open, so the
	 * guard compelled people to write into a void.
	 *
	 * @return void
	 */
	public function test_requested_changes_reach_the_advertiser_with_the_feedback(): void {
		$campaign = $this->campaign( Post_Statuses::CHANGES );

		$this->campaigns->set_review_notes( $campaign, 'The leaderboard artwork is 720x90 and must be 728x90.' );
		$this->campaigns->set_internal_notes( $campaign, 'Third time this agency has done this.' );

		$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::CHANGES );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'advertiser@example.test', $this->mail[0]['to'] );
		$this->assertStringContainsString( 'Changes requested: Autumn arts guide', (string) $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'The leaderboard artwork is 720x90', (string) $this->mail[0]['message'] );

		// The advertiser's own screen, never the staff review screen.
		$this->assertStringContainsString( '/advertiser/campaigns/' . $campaign, (string) $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'page=aggr-review', (string) $this->mail[0]['message'] );
	}

	/**
	 * **Internal notes never leave the admin.**
	 *
	 * Two note fields exist precisely so staff can write candidly. Leaking one
	 * into an advertiser's inbox is unrecoverable.
	 *
	 * @return void
	 */
	public function test_internal_notes_never_reach_the_advertiser(): void {
		$campaign = $this->campaign( Post_Statuses::REJECTED );

		$this->campaigns->set_review_notes( $campaign, 'This does not meet our content guidelines.' );
		$this->campaigns->set_internal_notes( $campaign, 'Do not take bookings from them again.' );

		$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::REJECTED );

		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'This does not meet our content guidelines.', (string) $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'Do not take bookings', (string) $this->mail[0]['message'] );
	}

	/**
	 * Approval reaches everyone in the organization, not only its owner.
	 *
	 * @return void
	 */
	public function test_approval_reaches_every_member_of_the_organization(): void {
		$this->member( 'colleague@example.test' );

		$campaign = $this->campaign( Post_Statuses::APPROVED );

		$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::APPROVED );

		$addresses = array_column( $this->mail, 'to' );

		$this->assertContains( 'advertiser@example.test', $addresses );
		$this->assertContains( 'colleague@example.test', $addresses );
		$this->assertCount( 2, array_unique( $addresses ) );
	}

	/**
	 * Good news carries no feedback block, even when review notes exist.
	 *
	 * Notes left over from an earlier round of changes must not be replayed as
	 * though they were the reason for the approval.
	 *
	 * @return void
	 */
	public function test_approval_does_not_replay_old_feedback(): void {
		$campaign = $this->campaign( Post_Statuses::APPROVED );

		$this->campaigns->set_review_notes( $campaign, 'Left over from the previous round.' );

		$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::APPROVED );

		$this->assertCount( 1, $this->mail );
		$this->assertStringNotContainsString( 'Left over from the previous round.', (string) $this->mail[0]['message'] );
	}

	/**
	 * The clock going live tells the advertiser, since nobody else will.
	 *
	 * @return void
	 */
	public function test_going_live_tells_the_advertiser(): void {
		$campaign = $this->campaign( Post_Statuses::LIVE );

		$this->service->campaign_transitioned( $campaign, Post_Statuses::SCHEDULED, Post_Statuses::LIVE );

		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'Campaign is now running', (string) $this->mail[0]['subject'] );
	}

	/**
	 * **Queue management is not news.**
	 *
	 * A reviewer claiming a campaign and putting it back moves it through two
	 * statuses. Emailing the advertiser about either is the surest way to make
	 * them ignore the message that matters.
	 *
	 * @return void
	 */
	public function test_internal_queue_movement_does_not_email_the_advertiser(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );

		$this->service->campaign_transitioned( $campaign, Post_Statuses::SUBMITTED, Post_Statuses::REVIEW );

		$this->assertSame( array(), $this->mail );
	}

	/**
	 * A repeated hook does not send the same decision twice.
	 *
	 * @return void
	 */
	public function test_a_repeated_decision_hook_sends_once(): void {
		$campaign = $this->campaign( Post_Statuses::CHANGES );

		$this->campaigns->set_review_notes( $campaign, 'Please resize the artwork.' );

		$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::CHANGES );
		$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::CHANGES );

		$this->assertCount( 1, $this->mail );
	}

	/**
	 * A second round of changes does send again.
	 *
	 * The receipt carries the revision as well as the status, so a campaign
	 * resubmitted and sent back a second time is a different decision about
	 * different work. Keying on status alone would silence every round after
	 * the first — the advertiser would be told once and then left waiting.
	 *
	 * @return void
	 */
	public function test_a_second_round_of_changes_sends_again(): void {
		$campaign = $this->campaign( Post_Statuses::CHANGES );

		$this->campaigns->set_review_notes( $campaign, 'First round.' );
		$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::CHANGES );

		$this->campaigns->increment_revision( $campaign );
		$this->campaigns->set_review_notes( $campaign, 'Second round.' );
		$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::CHANGES );

		$this->assertCount( 2, $this->mail );
		$this->assertStringContainsString( 'First round.', (string) $this->mail[0]['message'] );
		$this->assertStringContainsString( 'Second round.', (string) $this->mail[1]['message'] );
	}

	/**
	 * A failed advertiser notice schedules a retry and never reverses anything.
	 *
	 * @return void
	 */
	public function test_a_failed_advertiser_notice_is_retried(): void {
		$campaign = $this->campaign( Post_Statuses::CHANGES );

		$this->campaigns->set_review_notes( $campaign, 'Please resize the artwork.' );
		$this->mail_results['advertiser@example.test'] = false;

		$threw = false;

		try {
			$this->service->campaign_transitioned( $campaign, Post_Statuses::REVIEW, Post_Statuses::CHANGES );
		} catch ( RuntimeException ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'A failed notice must surface so the state machine can audit it.' );
		$this->assertNotFalse(
			wp_next_scheduled(
				Notification_Service::ADVERTISER_RETRY_HOOK,
				array( $campaign, Post_Statuses::CHANGES, $this->campaigns->revision( $campaign ), 1 )
			)
		);
	}

	/**
	 * A retry for a campaign that has moved on is dropped.
	 *
	 * "Changes requested" landing after the advertiser has already resubmitted
	 * describes a state the campaign is no longer in, which is worse than
	 * silence.
	 *
	 * @return void
	 */
	public function test_a_stale_advertiser_retry_is_dropped(): void {
		$campaign = $this->campaign( Post_Statuses::SUBMITTED );

		$this->service->retry_advertiser_notice( $campaign, Post_Statuses::CHANGES, $this->campaigns->revision( $campaign ), 1 );

		$this->assertSame( array(), $this->mail );
	}
}
