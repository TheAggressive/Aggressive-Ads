<?php
/**
 * Campaign email notifications.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Notification;

use LAAO_Advertiser_Portal\Admin\Review_Screen;
use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\User_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use RuntimeException;
use Throwable;

/**
 * Sends individualized, idempotent messages after a transition commits.
 *
 * One wp_mail() call per person keeps reviewer addresses private. A receipt is
 * reserved before each call and retained only on success, so a repeated hook
 * skips successful recipients while retrying only failures.
 */
final class Notification_Service implements Service {

	public const RETRY_HOOK = 'laao_ads_retry_submission_notifications';

	private const STAFF_SUBMISSION = 'staff_submission';
	private const MAX_RETRIES      = 3;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository $campaigns Campaign persistence.
	 * @param Org_Repository      $orgs      Organization persistence.
	 * @param User_Repository     $users     Recipient resolution.
	 * @param Audit_Repository    $audit     Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Org_Repository $orgs,
		private readonly User_Repository $users,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Attaches after-commit notification delivery.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'laao_ads_notify_campaign_transitioned', array( $this, 'campaign_transitioned' ), 10, 4 );
		add_action( self::RETRY_HOOK, array( $this, 'retry_submission' ), 10, 3 );
	}

	/**
	 * Sends only genuine advertiser submissions and resubmissions.
	 *
	 * A staff unclaim also enters submitted, but it is queue management rather
	 * than new advertiser work and must not email the entire review team.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param string               $from        Previous campaign status.
	 * @param string               $to          New campaign status.
	 * @param array<string, mixed> $context     Transition context. Unused.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	public function campaign_transitioned( int $campaign_id, string $from, string $to, array $context = array() ): void {
		if ( Post_Statuses::SUBMITTED !== $to || ! in_array( $from, array( Post_Statuses::DRAFT, Post_Statuses::CHANGES ), true ) ) {
			return;
		}

		$revision = $this->campaigns->revision( $campaign_id );

		try {
			$this->queue_submission( $campaign_id, $revision );
		} catch ( RuntimeException $exception ) {
			$this->schedule_retry( $campaign_id, $revision, 1 );

			throw $exception;
		}
	}

	/**
	 * Retries only while the same submission is still waiting in the queue.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $revision    Submission revision scheduled for retry.
	 * @param int $attempt     One-based retry attempt.
	 * @return void
	 */
	public function retry_submission( int $campaign_id, int $revision, int $attempt ): void {
		if (
			$attempt < 1
			|| $attempt > self::MAX_RETRIES
			|| Post_Statuses::SUBMITTED !== $this->campaigns->status( $campaign_id )
			|| $revision !== $this->campaigns->revision( $campaign_id )
		) {
			return;
		}

		try {
			$this->queue_submission( $campaign_id, $revision );
		} catch ( RuntimeException ) {
			$exhausted = self::MAX_RETRIES === $attempt;

			$this->audit->insert(
				new Audit_Event(
					event: $exhausted ? 'campaign.notification_retry_exhausted' : 'campaign.notification_retry_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exhausted ? 'Campaign notification retries exhausted.' : 'Campaign notification retry failed.',
					context: array(
						'notification' => self::STAFF_SUBMISSION,
						'revision'     => $revision,
						'attempt'      => $attempt,
					)
				)
			);

			if ( ! $exhausted ) {
				$this->schedule_retry( $campaign_id, $revision, $attempt + 1 );
			}
		}
	}

	/**
	 * Fans one submission out to every current reviewer who still needs it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $revision    Submission revision.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	private function queue_submission( int $campaign_id, int $revision ): void {
		if ( ! $this->campaigns->exists( $campaign_id ) ) {
			throw new RuntimeException( 'Campaign notification could not resolve its campaign.' );
		}

		$recipients = $this->users->with_capability( Capabilities::REVIEW_CAMPAIGNS );

		if ( array() === $recipients ) {
			throw new RuntimeException( 'No eligible campaign-review notification recipients were found.' );
		}

		$queued = 0;
		$failed = 0;

		foreach ( $recipients as $recipient ) {
			$email   = trim( $recipient['email'] );
			$receipt = self::STAFF_SUBMISSION . ':' . $revision . ':' . $recipient['id'];

			if ( ! is_email( $email ) ) {
				++$failed;
				continue;
			}

			if ( ! $this->campaigns->reserve_notification_receipt( $campaign_id, $receipt ) ) {
				continue;
			}

			if ( ! $this->send_submission( $email, $recipient['id'], $campaign_id, $revision ) ) {
				$this->campaigns->release_notification_receipt( $campaign_id, $receipt );
				++$failed;
				continue;
			}

			++$queued;
		}

		if ( $queued > 0 ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'campaign.notification_queued',
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: 'Staff submission notification queued.',
					context: array(
						'notification'    => self::STAFF_SUBMISSION,
						'revision'        => $revision,
						'recipient_count' => $queued,
					)
				)
			);
		}

		if ( $failed > 0 ) {
			throw new RuntimeException(
				sprintf( '%d campaign-review notification recipient(s) could not be queued.', $failed )
			);
		}
	}

	/**
	 * Schedules one deduplicated, bounded retry.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $revision    Submission revision.
	 * @param int $attempt     One-based retry attempt.
	 * @return void
	 */
	private function schedule_retry( int $campaign_id, int $revision, int $attempt ): void {
		if ( $attempt < 1 || $attempt > self::MAX_RETRIES ) {
			return;
		}

		$args = array( $campaign_id, $revision, $attempt );

		if ( false !== wp_get_scheduled_event( self::RETRY_HOOK, $args ) ) {
			return;
		}

		$delay = match ( $attempt ) {
			1       => 5 * MINUTE_IN_SECONDS,
			2       => 30 * MINUTE_IN_SECONDS,
			default => 2 * HOUR_IN_SECONDS,
		};
		$result = wp_schedule_single_event( time() + $delay, self::RETRY_HOOK, $args, true );

		if ( false !== $result && ! is_wp_error( $result ) ) {
			return;
		}

		// Another request may have scheduled the same retry between our check
		// and insert. In that race the recovery exists, so it is not a failure.
		if ( false !== wp_next_scheduled( self::RETRY_HOOK, $args ) ) {
			return;
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.notification_retry_schedule_failed',
				outcome: Audit_Event::OUTCOME_FAILED,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Campaign notification retry could not be scheduled.',
				context: array(
					'notification' => self::STAFF_SUBMISSION,
					'revision'     => $revision,
					'attempt'      => $attempt,
				)
			)
		);
	}

	/**
	 * Queues one localized plain-text submission email.
	 *
	 * @param string $email       Validated recipient address.
	 * @param int    $user_id     Recipient user id.
	 * @param int    $campaign_id Campaign post id.
	 * @param int    $revision    Submission revision.
	 * @return bool
	 */
	private function send_submission( string $email, int $user_id, int $campaign_id, int $revision ): bool {
		$switched = switch_to_user_locale( $user_id );

		try {
			$message = $this->submission_message( $campaign_id, $revision );

			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional notification to individually authorized staff, not bulk or marketing email.
			return wp_mail( $email, $message['subject'], $message['body'] );
		} catch ( Throwable ) {
			return false;
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Builds a plain-text message with no private creative or internal-note data.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $revision    Submission revision.
	 * @return array{subject: string, body: string}
	 */
	private function submission_message( int $campaign_id, int $revision ): array {
		$title        = sanitize_text_field( $this->campaigns->title( $campaign_id ) );
		$organization = sanitize_text_field( $this->orgs->name( $this->campaigns->org_id( $campaign_id ) ) );
		$site_name    = sanitize_text_field( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$resubmitted  = $revision > 0;

		if ( $resubmitted ) {
			/* translators: 1: site name. 2: campaign title. */
			$subject = sprintf( __( '[%1$s] Campaign resubmitted: %2$s', 'laao-advertiser-portal' ), $site_name, $title );
		} else {
			/* translators: 1: site name. 2: campaign title. */
			$subject = sprintf( __( '[%1$s] New campaign submitted: %2$s', 'laao-advertiser-portal' ), $site_name, $title );
		}

		$intro = $resubmitted
			? __( 'A revised advertising campaign is ready for review.', 'laao-advertiser-portal' )
			: __( 'A new advertising campaign is ready for review.', 'laao-advertiser-portal' );

		$body = array(
			$intro,
			'',
			/* translators: %s: organization name. */
			sprintf( __( 'Organization: %s', 'laao-advertiser-portal' ), $organization ),
			/* translators: %s: campaign title. */
			sprintf( __( 'Campaign: %s', 'laao-advertiser-portal' ), $title ),
			/* translators: %s: campaign revision number. */
			sprintf( __( 'Revision: %s', 'laao-advertiser-portal' ), number_format_i18n( $revision ) ),
			'',
			__( 'Review campaign:', 'laao-advertiser-portal' ),
			Review_Screen::campaign_url( $campaign_id ),
		);

		return array(
			'subject' => sanitize_text_field( $subject ),
			'body'    => implode( "\n", $body ),
		);
	}
}
