<?php
/**
 * Staff email for an advertiser's request against a running campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Notification;

use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use RuntimeException;

/**
 * Tells the review team that an advertiser is waiting on them.
 *
 * The queue tab and the menu badge already *show* a request. Nobody was ever
 * *told* about one, so a request against a live campaign sat until somebody
 * happened to open the queue — which is the one screen a reviewer has no reason
 * to open when nothing has been submitted.
 *
 * Modelled on `Notification_Service::queue_submission()` and kept in its own
 * class for the reason `Ending_Soon_Mailer` is: one class per trigger, so no
 * single mailer grows past the file-length gate.
 *
 * The structural difference from every other campaign notification is that this
 * one does not hang off a transition. A request is a meta write against a
 * campaign whose status does not change, so it has its own hook and its own
 * counter rather than borrowing the submission revision.
 */
final class Request_Mailer implements Service {

	public const RETRY_HOOK = 'aggr_retry_request_notifications';

	/**
	 * The kind used when the advertiser is proposing field changes.
	 *
	 * Every other kind is the status they are asking staff to move the campaign
	 * to, so this one must not collide with a status slug.
	 */
	public const KIND_EDITS = 'edits';

	private const TYPE = 'staff_request';

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
	 * Attaches request delivery and its retry.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'aggr_notify_advertiser_request', array( $this, 'advertiser_requested' ), 10, 2 );
		add_action( self::RETRY_HOOK, array( $this, 'retry' ), 10, 3 );
	}

	/**
	 * Fans one request out to every current reviewer.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	public function advertiser_requested( int $campaign_id, string $kind ): void {
		$revision = $this->campaigns->request_revision( $campaign_id );

		try {
			$this->queue( $campaign_id, $kind, $revision );
		} catch ( RuntimeException $exception ) {
			$this->schedule_retry( $campaign_id, $kind, 1 );

			throw $exception;
		}
	}

	/**
	 * Retries only while that request is still waiting for an answer.
	 *
	 * A withdrawn or already-decided request must not produce a late email: the
	 * same rule `Notification_Service::retry_advertiser_notice()` applies to a
	 * status that has moved on. The revision is re-read rather than carried in
	 * the arguments so a request withdrawn and made again is a different one,
	 * and the retry for the first cannot deliver mail for the second.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 * @param int    $attempt     One-based retry attempt.
	 * @return void
	 */
	public function retry( int $campaign_id, string $kind, int $attempt ): void {
		if (
			$attempt < 1
			|| $attempt > Notification_Delivery::MAX_RETRIES
			|| ! $this->still_pending( $campaign_id, $kind )
		) {
			return;
		}

		try {
			$this->queue( $campaign_id, $kind, $this->campaigns->request_revision( $campaign_id ) );
		} catch ( RuntimeException ) {
			$exhausted = Notification_Delivery::MAX_RETRIES === $attempt;

			$this->audit->insert(
				new Audit_Event(
					event: $exhausted ? 'campaign.notification_retry_exhausted' : 'campaign.notification_retry_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exhausted ? 'Advertiser request notification retries exhausted.' : 'Advertiser request notification retry failed.',
					context: array(
						'notification' => self::TYPE,
						'kind'         => $kind,
						'attempt'      => $attempt,
					)
				)
			);

			if ( ! $exhausted ) {
				$this->schedule_retry( $campaign_id, $kind, $attempt + 1 );
			}
		}
	}

	/**
	 * Whether the request being announced is still outstanding.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 */
	private function still_pending( int $campaign_id, string $kind ): bool {
		if ( self::KIND_EDITS === $kind ) {
			return $this->campaigns->pending_edits_submitted( $campaign_id );
		}

		$request = $this->campaigns->action_request( $campaign_id );

		return array() !== $request && $request['action'] === $kind;
	}

	/**
	 * Reserves one receipt per reviewer and sends what is not already sent.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 * @param int    $revision    Request counter at the time of the request.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	private function queue( int $campaign_id, string $kind, int $revision ): void {
		if ( ! $this->campaigns->exists( $campaign_id ) ) {
			throw new RuntimeException( 'Advertiser request notification could not resolve its campaign.' );
		}

		$recipients = $this->users->with_capability( Capabilities::REVIEW_CAMPAIGNS );

		if ( array() === $recipients ) {
			throw new RuntimeException( 'No eligible campaign-review notification recipients were found.' );
		}

		$queued = 0;
		$failed = 0;

		foreach ( $recipients as $recipient ) {
			$email = trim( $recipient['email'] );

			/*
			 * The revision is what makes a withdrawn and resubmitted request a
			 * second email rather than a suppressed duplicate. Keying on kind
			 * alone would tell the review team once and then stay silent every
			 * time the advertiser asked again.
			 */
			$receipt = self::TYPE . ':' . $kind . ':' . $revision . ':' . $recipient['id'];

			if ( ! is_email( $email ) ) {
				++$failed;

				continue;
			}

			$result = $this->delivery->deliver(
				$campaign_id,
				$receipt,
				fn (): bool => $this->send( $email, $recipient['id'], $campaign_id, $kind )
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
					message: 'Advertiser request notification queued.',
					context: array(
						'notification'    => self::TYPE,
						'kind'            => $kind,
						'revision'        => $revision,
						'recipient_count' => $queued,
					)
				)
			);
		}

		if ( $failed > 0 ) {
			throw new RuntimeException(
				sprintf( '%d advertiser request notification recipient(s) could not be queued.', $failed )
			);
		}
	}

	/**
	 * Schedules one deduplicated, bounded retry.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 * @param int    $attempt     One-based retry attempt.
	 * @return void
	 */
	private function schedule_retry( int $campaign_id, string $kind, int $attempt ): void {
		$this->delivery->schedule_retry(
			self::RETRY_HOOK,
			array( $campaign_id, $kind, $attempt ),
			$attempt,
			$campaign_id,
			array(
				'notification' => self::TYPE,
				'kind'         => $kind,
				'attempt'      => $attempt,
			)
		);
	}

	/**
	 * Queues one localized plain-text message.
	 *
	 * @param string $email       Validated recipient address.
	 * @param int    $user_id     Recipient user id.
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 * @return bool
	 */
	private function send( string $email, int $user_id, int $campaign_id, string $kind ): bool {
		$result = $this->delivery->with_user_locale(
			$user_id,
			function () use ( $email, $campaign_id, $kind ): bool {
				$message = $this->message( $campaign_id, $kind );

				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional notification to individually authorized staff, not bulk or marketing email.
				return wp_mail( $email, $message['subject'], $message['body'], Notification_Delivery::sender_headers() );
			}
		);

		return true === $result;
	}

	/**
	 * Builds the reviewer's plain-text message.
	 *
	 * Carries what was asked for and why, because that is the whole content of a
	 * request, and nothing about the campaign a reviewer cannot already see on
	 * the review screen this links to. Proposed *values* stay out: they change
	 * while the request waits, and an email quoting a superseded destination URL
	 * is worse than one that says to go and look.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 * @return array{subject: string, body: string}
	 */
	private function message( int $campaign_id, string $kind ): array {
		$title        = sanitize_text_field( $this->campaigns->title( $campaign_id ) );
		$organization = sanitize_text_field( $this->orgs->name( $this->campaigns->org_id( $campaign_id ) ) );
		$site_name    = sanitize_text_field( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$is_edits     = self::KIND_EDITS === $kind;

		$headline = $is_edits
			? __( 'Changes requested', 'aggressive-ads' )
			: __( 'Action requested', 'aggressive-ads' );

		$body = array(
			$is_edits
				? __( 'An advertiser has proposed changes to a running campaign.', 'aggressive-ads' )
				: __( 'An advertiser has asked the review team to act on a running campaign.', 'aggressive-ads' ),
			'',
			/* translators: %s: organization name. */
			sprintf( __( 'Organization: %s', 'aggressive-ads' ), $organization ),
			/* translators: %s: campaign title. */
			sprintf( __( 'Campaign: %s', 'aggressive-ads' ), $title ),
		);

		if ( ! $is_edits ) {
			$request = $this->campaigns->action_request( $campaign_id );

			$body[] = sprintf(
				/* translators: %s: the requested action, already translated. */
				__( 'Requested: %s', 'aggressive-ads' ),
				sanitize_text_field( Campaign_Change_Manager::request_label( $kind ) )
			);

			$reason = array() === $request ? '' : trim( $request['reason'] );

			if ( '' !== $reason ) {
				$body[] = '';
				$body[] = __( 'Reason given by the advertiser:', 'aggressive-ads' );
				$body[] = sanitize_textarea_field( $reason );
			}
		}

		$body[] = '';
		$body[] = __( 'Review campaign:', 'aggressive-ads' );

		// Onto the requests tab rather than the default pending queue: the
		// campaign this is about is live, so it is not in the pending list at
		// all and the reviewer would arrive at a screen that does not show it.
		$body[] = Review_Screen::campaign_url( $campaign_id, 'requests' );

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
}
