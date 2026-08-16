<?php
/**
 * Staff notification for an advertiser's request against a running campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Notification\Request_Mailer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Proves the review team is told once per request, and again when asked again.
 *
 * The queue tab and menu badge already showed a request. Nobody was told about
 * one, so this covers the two directions a receipt-and-retry chain fails in:
 * mailing the whole team on every cron tick, and silently telling nobody the
 * second time an advertiser asks.
 */
final class RequestNotificationTest extends WP_UnitTestCase {

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
	 * Production request workflow.
	 *
	 * @var Campaign_Change_Manager
	 */
	private Campaign_Change_Manager $changes;

	/**
	 * Production request mailer.
	 *
	 * @var Request_Mailer
	 */
	private Request_Mailer $mailer;

	/**
	 * Settings document, for the live-edit allowlist.
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * Installs roles, an organization and an advertiser, and captures mail.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit     = new Audit_Repository();
		$this->campaigns = new Campaign_Repository();

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

		$this->changes  = Plugin::instance()->container()->get( Campaign_Change_Manager::class );
		$this->mailer   = Plugin::instance()->container()->get( Request_Mailer::class );
		$this->settings = Plugin::instance()->container()->get( Settings::class );

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Removes the mail interception, and any settings this test wrote.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		delete_option( Settings::OPTION );

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
	 * Creates one live campaign belonging to the fixture organization.
	 *
	 * @return int
	 */
	private function live_campaign(): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
				'post_author' => $this->advertiser,
				'post_title'  => 'Autumn arts guide',
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, time() - DAY_IN_SECONDS );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, time() + DAY_IN_SECONDS );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $campaign_id;
	}

	/**
	 * Turns on exactly the live-edit fields a test needs.
	 *
	 * The shipped default is an empty allowlist — the whole feature off — so an
	 * edits test that did not do this would pass against a refusal.
	 *
	 * @param array<int, string> $fields Settings_Schema::edit_keys() subset.
	 * @return void
	 */
	private function allow( array $fields ): void {
		$document = $this->settings->get();

		foreach ( Settings_Schema::edit_keys() as $key ) {
			$document['live_edits'][ $key ] = in_array( $key, $fields, true );
		}

		$this->assertTrue( $this->settings->save( $document ) );
		$this->assertNotSame( array(), $this->settings->live_edit_fields() );
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
	 * Asks staff to pause the campaign as the advertiser.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $reason      Advertiser's explanation.
	 * @return void
	 */
	private function request_pause( int $campaign_id, string $reason = 'The sponsor has paused the budget.' ): void {
		wp_set_current_user( $this->advertiser );

		$this->assertTrue( $this->changes->request_action( $campaign_id, Post_Statuses::PAUSED, $reason ) );
	}

	/**
	 * Admin address plus every reviewer, in one array for assertions.
	 *
	 * @param array<int, string> $reviewers Reviewer addresses.
	 * @return array<int, string>
	 */
	private function expected_recipients( array $reviewers ): array {
		return array_merge( array( (string) get_option( 'admin_email' ) ), $reviewers );
	}

	/**
	 * The production listener and its retry are attached.
	 *
	 * @return void
	 */
	public function test_the_request_mailer_is_wired(): void {
		$this->assertSame( 10, has_action( 'aggr_notify_advertiser_request', array( $this->mailer, 'advertiser_requested' ) ) );
		$this->assertSame( 10, has_action( Request_Mailer::RETRY_HOOK, array( $this->mailer, 'retry' ) ) );
	}

	/**
	 * **Every reviewer is told, privately, once.**
	 *
	 * @return void
	 */
	public function test_an_action_request_reaches_every_reviewer(): void {
		$this->reviewer( 'first-reviewer@example.test' );
		$this->reviewer( 'second-reviewer@example.test' );

		$campaign = $this->live_campaign();

		$this->request_pause( $campaign );

		$addresses = array_column( $this->mail, 'to' );

		$this->assertSame(
			$this->expected_recipients( array( 'first-reviewer@example.test', 'second-reviewer@example.test' ) ),
			$addresses
		);
		$this->assertCount( 3, array_unique( $addresses ) );

		foreach ( $this->mail as $mail ) {
			$this->assertStringContainsString( 'Action requested: Autumn arts guide', (string) $mail['subject'] );
			$this->assertStringNotContainsString( "\n", (string) $mail['subject'] );
			$this->assertStringContainsString( 'Organization: Bright Angle Media', (string) $mail['message'] );
			$this->assertStringContainsString( 'Pause this campaign', (string) $mail['message'] );
			$this->assertStringContainsString( 'The sponsor has paused the budget.', (string) $mail['message'] );
			$this->assertStringContainsString( 'page=aggr-review', (string) $mail['message'] );
			$this->assertStringContainsString( 'filter=requests', (string) $mail['message'] );

			// One address per call: reviewers never learn each other's.
			$this->assertStringNotContainsString( 'first-reviewer@example.test', (string) $mail['message'] );
			$this->assertStringNotContainsString( 'second-reviewer@example.test', (string) $mail['message'] );
			$this->assertStringNotContainsString( 'advertiser@example.test', (string) $mail['message'] );
		}
	}

	/**
	 * **A replayed hook sends nothing.**
	 *
	 * The receipt is what stops a cron retry mailing the whole review team on
	 * every tick.
	 *
	 * @return void
	 */
	public function test_a_repeated_request_hook_sends_once(): void {
		$this->reviewer( 'reviewer@example.test' );

		$campaign = $this->live_campaign();

		$this->request_pause( $campaign );

		$sent = count( $this->mail );

		do_action( 'aggr_notify_advertiser_request', $campaign, Post_Statuses::PAUSED );

		$this->assertCount( $sent, $this->mail );

		$events    = array_column( $this->audit->for_object( 'campaign', $campaign, $this->org_id ), 'event' );
		$queued    = array_filter( $events, static fn ( string $event ): bool => 'campaign.notification_queued' === $event );
		$requested = array_filter( $events, static fn ( string $event ): bool => 'campaign.action_requested' === $event );

		$this->assertCount( 1, $queued );
		$this->assertCount( 1, $requested );
	}

	/**
	 * **Withdrawing and asking again tells the review team a second time.**
	 *
	 * This is the assertion the receipt key exists for. Keyed on the kind alone,
	 * or on `revision()` — which never moves for a campaign that stays live —
	 * the second ask is suppressed as a duplicate and nobody is told.
	 *
	 * @return void
	 */
	public function test_a_withdrawn_and_repeated_request_sends_again(): void {
		$this->reviewer( 'reviewer@example.test' );

		$campaign = $this->live_campaign();

		$this->request_pause( $campaign, 'First ask.' );

		$first = count( $this->mail );

		$this->assertTrue( $this->changes->withdraw_action( $campaign ) );

		$this->request_pause( $campaign, 'Asking again, the sponsor confirmed.' );

		$this->assertCount( $first * 2, $this->mail );
		$this->assertStringContainsString( 'First ask.', (string) $this->mail[0]['message'] );
		$this->assertStringContainsString( 'Asking again, the sponsor confirmed.', (string) $this->mail[ $first ]['message'] );
	}

	/**
	 * Proposed edits are their own kind of request, and also notify.
	 *
	 * @return void
	 */
	public function test_submitted_edits_notify_the_review_team(): void {
		$this->reviewer( 'reviewer@example.test' );

		$campaign = $this->live_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );

		wp_set_current_user( $this->advertiser );

		$this->assertIsArray( $this->changes->stage( $campaign, array( 'title' => 'Autumn arts guide 2026' ) ) );
		$this->assertSame( array(), $this->mail, 'Staging a step is not a request and must not email anyone.' );

		$this->assertTrue( $this->changes->submit( $campaign ) );

		$this->assertNotSame( array(), $this->mail );

		foreach ( $this->mail as $mail ) {
			$this->assertStringContainsString( 'Changes requested: Autumn arts guide', (string) $mail['subject'] );
			$this->assertStringContainsString( 'has proposed changes', (string) $mail['message'] );

			// The proposal's values change while it waits; the screen is the
			// authority on what is actually being asked for.
			$this->assertStringNotContainsString( 'Autumn arts guide 2026', (string) $mail['message'] );
		}
	}

	/**
	 * A failed send is retried for that recipient only, and never lost.
	 *
	 * @return void
	 */
	public function test_a_failed_request_notice_is_retried(): void {
		$this->reviewer( 'failed@example.test' );
		$this->reviewer( 'sent@example.test' );

		$this->mail_results['failed@example.test'] = false;

		$campaign = $this->live_campaign();

		$this->request_pause( $campaign );

		$this->assertNotFalse(
			wp_next_scheduled( Request_Mailer::RETRY_HOOK, array( $campaign, Post_Statuses::PAUSED, 1 ) )
		);

		$attempted = count( $this->mail );

		$this->mail_results['failed@example.test'] = true;
		$this->mailer->retry( $campaign, Post_Statuses::PAUSED, 1 );

		$this->assertCount( $attempted + 1, $this->mail );
		$this->assertSame( 'failed@example.test', $this->mail[ $attempted ]['to'] );
	}

	/**
	 * **A withdrawn request produces no late email.**
	 *
	 * A cancelled ask answered by a cron tick two hours later is worse than
	 * silence: it puts a decision in front of staff that nobody is waiting on.
	 *
	 * @return void
	 */
	public function test_a_retry_for_a_withdrawn_request_is_dropped(): void {
		$this->reviewer( 'reviewer@example.test' );

		/*
		 * The whole delivery has to fail first, which is the only situation a
		 * retry exists in. Retrying after a *successful* send would prove
		 * nothing about the guard: the receipts would still be held and the
		 * attempt would be skipped for that reason instead.
		 */
		$this->mail_results[ (string) get_option( 'admin_email' ) ] = false;
		$this->mail_results['reviewer@example.test']                = false;

		$campaign = $this->live_campaign();

		$this->request_pause( $campaign );

		$this->assertSame(
			array(),
			get_post_meta( $campaign, Campaign_Repository::META_NOTIFICATION_RECEIPT, false ),
			'A failed send must release its receipts, or this proves the wrong thing.'
		);

		wp_set_current_user( $this->advertiser );
		$this->assertTrue( $this->changes->withdraw_action( $campaign ) );

		$this->mail = array();
		$this->mail_results[ (string) get_option( 'admin_email' ) ] = true;
		$this->mail_results['reviewer@example.test']                = true;

		$this->mailer->retry( $campaign, Post_Statuses::PAUSED, 1 );

		$this->assertSame( array(), $this->mail );
	}

	/**
	 * A retry for a different action than the one now pending is dropped.
	 *
	 * @return void
	 */
	public function test_a_retry_for_a_superseded_request_is_dropped(): void {
		$this->reviewer( 'reviewer@example.test' );

		$campaign = $this->live_campaign();

		$this->request_pause( $campaign );

		$this->mail = array();

		$this->mailer->retry( $campaign, Post_Statuses::CANCELLED, 1 );

		$this->assertSame( array(), $this->mail );
	}

	/**
	 * The final failed retry stops and leaves an explicit exhaustion event.
	 *
	 * @return void
	 */
	public function test_request_retries_are_bounded_and_audited(): void {
		$this->reviewer( 'reviewer@example.test' );

		$campaign = $this->live_campaign();

		$this->request_pause( $campaign );

		// Fail every address from here on, then run the last permitted attempt.
		$this->mail_results[ (string) get_option( 'admin_email' ) ] = false;
		$this->mail_results['reviewer@example.test']                = false;

		delete_post_meta( $campaign, Campaign_Repository::META_NOTIFICATION_RECEIPT );

		$this->mailer->retry( $campaign, Post_Statuses::PAUSED, 3 );

		$events = array_column( $this->audit->for_object( 'campaign', $campaign, $this->org_id ), 'event' );

		$this->assertContains( 'campaign.notification_retry_exhausted', $events );
		$this->assertFalse( wp_next_scheduled( Request_Mailer::RETRY_HOOK, array( $campaign, Post_Statuses::PAUSED, 4 ) ) );
	}

	/**
	 * **A failed notification never reverses the request itself.**
	 *
	 * The advertiser's ask is already saved when mail is attempted. Surfacing a
	 * mail failure to them would say their request had not been recorded.
	 *
	 * @return void
	 */
	public function test_a_mail_failure_never_reverses_the_request(): void {
		$this->reviewer( 'reviewer@example.test' );

		$this->mail_results[ (string) get_option( 'admin_email' ) ] = false;
		$this->mail_results['reviewer@example.test']                = false;

		$campaign = $this->live_campaign();

		wp_set_current_user( $this->advertiser );

		$result = $this->changes->request_action( $campaign, Post_Statuses::PAUSED, 'The sponsor has paused the budget.' );

		$this->assertTrue( $result, 'A mail failure must not become the advertiser\'s error.' );
		$this->assertSame( Post_Statuses::PAUSED, $this->campaigns->action_request( $campaign )['action'] );

		$events = array_column( $this->audit->for_object( 'campaign', $campaign, $this->org_id ), 'event' );

		$this->assertContains( 'campaign.notification_failed', $events );
	}

	/**
	 * A site with nobody able to review raises rather than quietly sending none.
	 *
	 * @return void
	 */
	public function test_no_eligible_reviewer_raises(): void {
		$revoke = static function ( array $allcaps ): array {
			unset( $allcaps[ Capabilities::REVIEW_CAMPAIGNS ] );

			return $allcaps;
		};

		add_filter( 'user_has_cap', $revoke, 10 );

		$campaign = $this->live_campaign();
		$users    = Plugin::instance()->container()->get( User_Repository::class );

		try {
			// The fixture is only real if the administrator has genuinely lost
			// the capability; asserting the exception against a site that still
			// has a recipient would prove nothing.
			$this->assertSame( array(), $users->with_capability( Capabilities::REVIEW_CAMPAIGNS ) );

			$this->expectException( RuntimeException::class );

			$this->mailer->advertiser_requested( $campaign, Post_Statuses::PAUSED );
		} finally {
			remove_filter( 'user_has_cap', $revoke, 10 );
		}
	}
}
