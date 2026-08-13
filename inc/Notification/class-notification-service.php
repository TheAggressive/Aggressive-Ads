<?php
/**
 * Campaign email notifications.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Notification;

use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use RuntimeException;

/**
 * Sends individualized, idempotent messages after a transition commits.
 *
 * One wp_mail() call per person keeps reviewer addresses private. A receipt is
 * reserved before each call and retained only on success, so a repeated hook
 * skips successful recipients while retrying only failures.
 */
final class Notification_Service implements Service {

	public const RETRY_HOOK            = 'aggr_retry_submission_notifications';
	public const ADVERTISER_RETRY_HOOK = 'aggr_retry_advertiser_notifications';

	private const STAFF_SUBMISSION    = 'staff_submission';
	private const ADVERTISER_DECISION = 'advertiser_decision';

	/**
	 * The statuses worth telling an advertiser about, and nothing else.
	 *
	 * An allowlist rather than "everything that is not a submission": most
	 * transitions are internal queue movement. A reviewer claiming a campaign
	 * and releasing it again would otherwise send two emails describing nothing
	 * the advertiser can act on, and the surest way to make people ignore a
	 * notification is to send one that does not matter.
	 *
	 * @var array<int, string>
	 */
	private const ADVERTISER_STATUSES = array(
		Post_Statuses::CHANGES,
		Post_Statuses::REJECTED,
		Post_Statuses::APPROVED,
		Post_Statuses::LIVE,
		Post_Statuses::COMPLETE,
	);

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository   $campaigns Campaign persistence.
	 * @param Org_Repository        $orgs      Organization persistence.
	 * @param User_Repository       $users     Recipient resolution.
	 * @param Audit_Repository      $audit     Audit persistence.
	 * @param Notification_Delivery $delivery  Shared receipt and retry helpers.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Org_Repository $orgs,
		private readonly User_Repository $users,
		private readonly Audit_Repository $audit,
		private readonly Notification_Delivery $delivery
	) {
	}

	/**
	 * Attaches after-commit notification delivery.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'aggr_notify_campaign_transitioned', array( $this, 'campaign_transitioned' ), 10, 4 );
		add_action( self::RETRY_HOOK, array( $this, 'retry_submission' ), 10, 3 );
		add_action( self::ADVERTISER_RETRY_HOOK, array( $this, 'retry_advertiser_notice' ), 10, 4 );
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
		if ( in_array( $to, self::ADVERTISER_STATUSES, true ) ) {
			$this->notify_advertiser( $campaign_id, $to );

			return;
		}

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
	 * Tells the advertiser what happened to their campaign.
	 *
	 * The acute case is CHANGES and REJECTED. GUARD_REVIEW_NOTES *forces* a
	 * reviewer to write advertiser-facing feedback before either, and until
	 * this existed that feedback went nowhere: it sat on a screen the
	 * advertiser had no reason to open, so the guard was compelling people to
	 * write into a void.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $to          New status.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	private function notify_advertiser( int $campaign_id, string $to ): void {
		$revision = $this->campaigns->revision( $campaign_id );

		try {
			$this->queue_advertiser_notice( $campaign_id, $to, $revision );
		} catch ( RuntimeException $exception ) {
			$this->schedule_advertiser_retry( $campaign_id, $to, $revision, 1 );

			throw $exception;
		}
	}

	/**
	 * Retries only while the campaign is still in the status being announced.
	 *
	 * A campaign that has moved on since the failure must not receive a late
	 * email describing a state it is no longer in — "changes requested" landing
	 * after the advertiser has already resubmitted is worse than silence.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Status the notice announces.
	 * @param int    $revision    Revision at the time of the transition.
	 * @param int    $attempt     One-based retry attempt.
	 * @return void
	 */
	public function retry_advertiser_notice( int $campaign_id, string $status, int $revision, int $attempt ): void {
		if (
			$attempt < 1
			|| $attempt > Notification_Delivery::MAX_RETRIES
			|| $status !== $this->campaigns->status( $campaign_id )
			|| $revision !== $this->campaigns->revision( $campaign_id )
		) {
			return;
		}

		try {
			$this->queue_advertiser_notice( $campaign_id, $status, $revision );
		} catch ( RuntimeException ) {
			$exhausted = Notification_Delivery::MAX_RETRIES === $attempt;

			$this->audit->insert(
				new Audit_Event(
					event: $exhausted ? 'campaign.notification_retry_exhausted' : 'campaign.notification_retry_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exhausted ? 'Advertiser notification retries exhausted.' : 'Advertiser notification retry failed.',
					context: array(
						'notification' => self::ADVERTISER_DECISION,
						'status'       => $status,
						'revision'     => $revision,
						'attempt'      => $attempt,
					)
				)
			);

			if ( ! $exhausted ) {
				$this->schedule_advertiser_retry( $campaign_id, $status, $revision, $attempt + 1 );
			}
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
			|| $attempt > Notification_Delivery::MAX_RETRIES
			|| Post_Statuses::SUBMITTED !== $this->campaigns->status( $campaign_id )
			|| $revision !== $this->campaigns->revision( $campaign_id )
		) {
			return;
		}

		try {
			$this->queue_submission( $campaign_id, $revision );
		} catch ( RuntimeException ) {
			$exhausted = Notification_Delivery::MAX_RETRIES === $attempt;

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

			$result = $this->delivery->deliver(
				$campaign_id,
				$receipt,
				fn (): bool => $this->send_submission( $email, $recipient['id'], $campaign_id, $revision )
			);

			if ( Notification_Delivery::RESULT_SKIPPED === $result ) {
				continue;
			}

			if ( Notification_Delivery::RESULT_FAILED === $result ) {
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
		$this->delivery->schedule_retry(
			self::RETRY_HOOK,
			array( $campaign_id, $revision, $attempt ),
			$attempt,
			$campaign_id,
			array(
				'notification' => self::STAFF_SUBMISSION,
				'revision'     => $revision,
				'attempt'      => $attempt,
			)
		);
	}


	/**
	 * Fans one decision out to every member of the owning organization.
	 *
	 * Everyone in the organization rather than the campaign's author: an agency
	 * where the person who built the campaign has left, or is on leave, is the
	 * normal case rather than the exception, and a rejection nobody reads is a
	 * campaign that silently never runs.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Status being announced.
	 * @param int    $revision    Revision at the time of the transition.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	private function queue_advertiser_notice( int $campaign_id, string $status, int $revision ): void {
		if ( ! $this->campaigns->exists( $campaign_id ) ) {
			throw new RuntimeException( 'Advertiser notification could not resolve its campaign.' );
		}

		$org_id     = $this->campaigns->org_id( $campaign_id );
		$recipients = $this->orgs->user_ids_for_org( $org_id );

		if ( array() === $recipients ) {
			throw new RuntimeException( 'No eligible advertiser notification recipients were found.' );
		}

		$queued = 0;
		$failed = 0;

		foreach ( $recipients as $user_id ) {
			$user = get_userdata( $user_id );

			/*
			 * The receipt carries the status and the revision, so a campaign
			 * that goes to changes, is resubmitted and goes to changes again
			 * sends a second email — a different decision about different work.
			 * Keying on the status alone would silence every round after the
			 * first.
			 */
			$receipt = self::ADVERTISER_DECISION . ':' . $status . ':' . $revision . ':' . $user_id;
			$email   = false === $user ? '' : trim( (string) $user->user_email );

			if ( ! is_email( $email ) ) {
				++$failed;

				continue;
			}

			$result = $this->delivery->deliver(
				$campaign_id,
				$receipt,
				fn (): bool => $this->send_advertiser_notice( $email, $user_id, $campaign_id, $status )
			);

			if ( Notification_Delivery::RESULT_SKIPPED === $result ) {
				continue;
			}

			if ( Notification_Delivery::RESULT_FAILED === $result ) {
				++$failed;

				continue;
			}

			++$queued;
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.advertiser_notified',
				outcome: 0 === $failed ? Audit_Event::OUTCOME_OK : Audit_Event::OUTCOME_FAILED,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $org_id,
				message: 0 === $failed
					? sprintf( '%d advertiser notification(s) queued.', $queued )
					: sprintf( '%d advertiser notification recipient(s) could not be queued.', $failed ),
				context: array(
					'notification' => self::ADVERTISER_DECISION,
					'status'       => $status,
					'revision'     => $revision,
				)
			)
		);

		if ( $failed > 0 ) {
			throw new RuntimeException( sprintf( '%d advertiser notification recipient(s) could not be queued.', $failed ) );
		}
	}

	/**
	 * Schedules one advertiser retry, backing off as the staff one does.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Status the notice announces.
	 * @param int    $revision    Revision at the time of the transition.
	 * @param int    $attempt     One-based retry attempt.
	 * @return void
	 */
	private function schedule_advertiser_retry( int $campaign_id, string $status, int $revision, int $attempt ): void {
		$this->delivery->schedule_retry(
			self::ADVERTISER_RETRY_HOOK,
			array( $campaign_id, $status, $revision, $attempt ),
			$attempt,
			$campaign_id,
			array(
				'notification' => self::ADVERTISER_DECISION,
				'status'       => $status,
				'revision'     => $revision,
				'attempt'      => $attempt,
			)
		);
	}


	/**
	 * Queues one localized plain-text decision email.
	 *
	 * @param string $email       Validated recipient address.
	 * @param int    $user_id     Recipient user id.
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Status being announced.
	 * @return bool
	 */
	private function send_advertiser_notice( string $email, int $user_id, int $campaign_id, string $status ): bool {
		$result = $this->delivery->with_user_locale(
			$user_id,
			function () use ( $email, $campaign_id, $status ): bool {
				$message = $this->advertiser_message( $campaign_id, $status );

				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional notification to a member of the owning organization, not bulk or marketing email.
				return wp_mail( $email, $message['subject'], $message['body'] );
			}
		);

		return true === $result;
	}


	/**
	 * Builds the advertiser's plain-text message.
	 *
	 * Carries the reviewer's advertiser-facing feedback and nothing else from
	 * the review: internal notes are staff-only and must never leave the admin,
	 * which is why they are read from a different meta key and not read here.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Status being announced.
	 * @return array{subject: string, body: string}
	 */
	private function advertiser_message( int $campaign_id, string $status ): array {
		$title     = sanitize_text_field( $this->campaigns->title( $campaign_id ) );
		$site_name = sanitize_text_field( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );

		$headline = match ( $status ) {
			Post_Statuses::CHANGES  => __( 'Changes requested', 'aggressive-ads' ),
			Post_Statuses::REJECTED => __( 'Campaign not approved', 'aggressive-ads' ),
			Post_Statuses::APPROVED => __( 'Campaign approved', 'aggressive-ads' ),
			Post_Statuses::LIVE     => __( 'Campaign is now running', 'aggressive-ads' ),
			default                 => __( 'Campaign finished', 'aggressive-ads' ),
		};

		$intro = match ( $status ) {
			Post_Statuses::CHANGES  => __( 'The review team has asked for changes before this campaign can run.', 'aggressive-ads' ),
			Post_Statuses::REJECTED => __( 'The review team was not able to approve this campaign.', 'aggressive-ads' ),
			Post_Statuses::APPROVED => __( 'This campaign has been approved and will run on its scheduled dates.', 'aggressive-ads' ),
			Post_Statuses::LIVE     => __( 'This campaign is now being shown on the site.', 'aggressive-ads' ),
			default                 => __( 'This campaign has reached its end date and is no longer being shown.', 'aggressive-ads' ),
		};

		$body = array(
			$intro,
			'',
			/* translators: %s: campaign title. */
			sprintf( __( 'Campaign: %s', 'aggressive-ads' ), $title ),
		);

		$notes = trim( $this->campaigns->review_notes( $campaign_id ) );

		if ( '' !== $notes && in_array( $status, array( Post_Statuses::CHANGES, Post_Statuses::REJECTED ), true ) ) {
			$body[] = '';
			$body[] = __( 'Feedback from the review team:', 'aggressive-ads' );
			$body[] = sanitize_textarea_field( $notes );
		}

		$body[] = '';
		$body[] = __( 'Open your campaign:', 'aggressive-ads' );
		$body[] = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		return array(
			'subject' => sanitize_text_field(
				sprintf(
					/* translators: 1: site name. 2: headline, e.g. Changes requested. 3: campaign title. */
					__( '[%1$s] %2$s: %3$s', 'aggressive-ads' ),
					$site_name,
					$headline,
					$title
				)
			),
			'body'    => implode( "\n", $body ),
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
		$result = $this->delivery->with_user_locale(
			$user_id,
			function () use ( $email, $campaign_id, $revision ): bool {
				$message = $this->submission_message( $campaign_id, $revision );

				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional notification to individually authorized staff, not bulk or marketing email.
				return wp_mail( $email, $message['subject'], $message['body'] );
			}
		);

		return true === $result;
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
			$subject = sprintf( __( '[%1$s] Campaign resubmitted: %2$s', 'aggressive-ads' ), $site_name, $title );
		} else {
			/* translators: 1: site name. 2: campaign title. */
			$subject = sprintf( __( '[%1$s] New campaign submitted: %2$s', 'aggressive-ads' ), $site_name, $title );
		}

		$intro = $resubmitted
			? __( 'A revised advertising campaign is ready for review.', 'aggressive-ads' )
			: __( 'A new advertising campaign is ready for review.', 'aggressive-ads' );

		$body = array(
			$intro,
			'',
			/* translators: %s: organization name. */
			sprintf( __( 'Organization: %s', 'aggressive-ads' ), $organization ),
			/* translators: %s: campaign title. */
			sprintf( __( 'Campaign: %s', 'aggressive-ads' ), $title ),
			/* translators: %s: campaign revision number. */
			sprintf( __( 'Revision: %s', 'aggressive-ads' ), number_format_i18n( $revision ) ),
			'',
			__( 'Review campaign:', 'aggressive-ads' ),
			Review_Screen::campaign_url( $campaign_id ),
		);

		return array(
			'subject' => sanitize_text_field( $subject ),
			'body'    => implode( "\n", $body ),
		);
	}
}
