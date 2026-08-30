<?php
/**
 * Staff email for a creative waiting on a running campaign.
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
use Aggressive\Ads\Workflow\Creative_Approval;
use RuntimeException;

/**
 * Tells the review team that artwork is waiting on a campaign already running.
 *
 * A creative uploaded to a running campaign has missed the transition that
 * publishes artwork, so it needs a reviewer. It appears on the Ad updates tab
 * with a counter, and that was the whole of it: nobody was told, and the tab is
 * one a reviewer has no reason to open when nothing has been submitted. The
 * creative therefore sat unpublished, refused by the decision engine for a
 * missing attachment, until somebody happened to look.
 *
 * **Its own class rather than a `creative` kind on `Request_Mailer`**, which is
 * where this was first expected to live. That class asks "is this campaign
 * request still open?" to decide whether a retry may still deliver, and keys
 * its receipt on a request revision counter. Neither question has an answer for
 * a creative, so sharing the class would mean branching `still_pending()` and
 * `message()` on a kind that takes the other path through both — two classes
 * interleaved in one file, which is what `Ending_Soon_Mailer` and
 * `Request_Mailer` are already split to avoid.
 */
final class Creative_Mailer implements Service {

	public const RETRY_HOOK = 'aggr_retry_creative_notifications';

	private const TYPE = 'creative_awaiting';

	/**
	 * Constructor.
	 *
	 * @param Creative_Approval     $approvals Whether the creative is still waiting.
	 * @param Campaign_Repository   $campaigns Campaign persistence.
	 * @param Org_Repository        $orgs      Organization persistence.
	 * @param User_Repository       $users     Recipient resolution.
	 * @param Audit_Repository      $audit     Audit persistence.
	 * @param Notification_Delivery $delivery  Shared receipt and retry helpers.
	 */
	public function __construct(
		private readonly Creative_Approval $approvals,
		private readonly Campaign_Repository $campaigns,
		private readonly Org_Repository $orgs,
		private readonly User_Repository $users,
		private readonly Audit_Repository $audit,
		private readonly Notification_Delivery $delivery
	) {
	}

	/**
	 * Attaches creative delivery and its retry.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'aggr_notify_creative_awaiting', array( $this, 'creative_awaiting' ), 10, 3 );
		add_action( self::RETRY_HOOK, array( $this, 'retry' ), 10, 4 );
	}

	/**
	 * Fans one waiting creative out to every current reviewer.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $creative_id Creative post id.
	 * @param int $actor_id    User who uploaded it, excluded from the fan-out.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	public function creative_awaiting( int $campaign_id, int $creative_id, int $actor_id ): void {
		try {
			$this->queue( $campaign_id, $creative_id, $actor_id );
		} catch ( RuntimeException $exception ) {
			$this->schedule_retry( $campaign_id, $creative_id, $actor_id, 1 );

			throw $exception;
		}
	}

	/**
	 * Retries only while that creative is still waiting for a decision.
	 *
	 * A creative published or turned down before the retry fires must not
	 * produce a late email telling staff to go and decide something already
	 * decided — the rule `Request_Mailer::retry()` applies to a request that has
	 * been answered. The queue is re-read rather than trusted from the
	 * arguments, so the answer is the current one.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $creative_id Creative post id.
	 * @param int $actor_id    User who uploaded it, excluded from the fan-out.
	 * @param int $attempt     One-based retry attempt.
	 * @return void
	 */
	public function retry( int $campaign_id, int $creative_id, int $actor_id, int $attempt ): void {
		if (
			$attempt < 1
			|| $attempt > Notification_Delivery::MAX_RETRIES
			|| ! $this->still_waiting( $campaign_id, $creative_id )
		) {
			return;
		}

		try {
			$this->queue( $campaign_id, $creative_id, $actor_id );
		} catch ( RuntimeException ) {
			$exhausted = Notification_Delivery::MAX_RETRIES === $attempt;

			$this->audit->insert(
				new Audit_Event(
					event: $exhausted ? 'campaign.notification_retry_exhausted' : 'campaign.notification_retry_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exhausted ? 'Creative review notification retries exhausted.' : 'Creative review notification retry failed.',
					context: array(
						'notification' => self::TYPE,
						'creative_id'  => $creative_id,
						'attempt'      => $attempt,
					)
				)
			);

			if ( ! $exhausted ) {
				$this->schedule_retry( $campaign_id, $creative_id, $actor_id, $attempt + 1 );
			}
		}
	}

	/**
	 * Whether the creative being announced still needs a decision.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $creative_id Creative post id.
	 */
	private function still_waiting( int $campaign_id, int $creative_id ): bool {
		return in_array( $creative_id, $this->approvals->awaiting( $campaign_id ), true );
	}

	/**
	 * Reserves one receipt per reviewer and sends what is not already sent.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $creative_id Creative post id.
	 * @param int $actor_id    User who uploaded it, excluded from the fan-out.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	private function queue( int $campaign_id, int $creative_id, int $actor_id ): void {
		if ( ! $this->campaigns->exists( $campaign_id ) ) {
			throw new RuntimeException( 'Creative review notification could not resolve its campaign.' );
		}

		/*
		 * The capability that can act on this, which is review rather than
		 * publish. Publishing a creative needs both, but a reviewer holding only
		 * REVIEW_CAMPAIGNS can still turn one down, and that is a decision worth
		 * telling them there is one to make.
		 */
		$recipients = $this->users->with_capability( Capabilities::REVIEW_CAMPAIGNS );

		if ( array() === $recipients ) {
			throw new RuntimeException( 'No eligible campaign-review notification recipients were found.' );
		}

		/*
		 * Everyone who can put a creative on a running campaign is already in
		 * this list. `Edit_Window` only opens a running campaign to
		 * REVIEW_CAMPAIGNS, so unlike an advertiser's request — which no
		 * recipient could have made — the uploader is always one of the people
		 * about to be told to go and look at it.
		 *
		 * Telling them is the difference between a notification and noise, and
		 * a queue whose mail is noise stops being read. The other reviewers
		 * still need it: an account manager may upload artwork without holding
		 * PUBLISH_TO_ADSANITY, which is the whole reason the creative is waiting
		 * rather than served.
		 */
		$recipients = array_values(
			array_filter(
				$recipients,
				static fn ( array $recipient ): bool => (int) $recipient['id'] !== $actor_id
			)
		);

		/*
		 * Not an error, and deliberately not a retry. A single reviewer who
		 * uploads their own artwork has nobody to be told, which is a correct
		 * outcome rather than a delivery failure — throwing here would schedule
		 * three retries and audit three failures over a site that is working.
		 */
		if ( array() === $recipients ) {
			return;
		}

		$queued = 0;
		$failed = 0;

		foreach ( $recipients as $recipient ) {
			$email = trim( $recipient['email'] );

			/*
			 * The creative id is the revision. `Request_Mailer` needs a counter
			 * because one campaign's request can be withdrawn and made again,
			 * and both are the same campaign and kind; a creative turned down
			 * and re-uploaded is a different post with a different id, so the
			 * second announcement cannot be suppressed as a duplicate of the
			 * first without one being added.
			 */
			$receipt = self::TYPE . ':' . $creative_id . ':' . $recipient['id'];

			if ( ! is_email( $email ) ) {
				++$failed;

				continue;
			}

			$result = $this->delivery->deliver(
				$campaign_id,
				$receipt,
				fn (): bool => $this->send( $email, $recipient['id'], $campaign_id )
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
					message: 'Creative review notification queued.',
					context: array(
						'notification'    => self::TYPE,
						'creative_id'     => $creative_id,
						'recipient_count' => $queued,
					)
				)
			);
		}

		if ( $failed > 0 ) {
			throw new RuntimeException(
				sprintf( '%d creative review notification recipient(s) could not be queued.', $failed )
			);
		}
	}

	/**
	 * Schedules one deduplicated, bounded retry.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $creative_id Creative post id.
	 * @param int $actor_id    User who uploaded it, excluded from the fan-out.
	 * @param int $attempt     One-based retry attempt.
	 * @return void
	 */
	private function schedule_retry( int $campaign_id, int $creative_id, int $actor_id, int $attempt ): void {
		$this->delivery->schedule_retry(
			self::RETRY_HOOK,
			array( $campaign_id, $creative_id, $actor_id, $attempt ),
			$attempt,
			$campaign_id,
			array(
				'notification' => self::TYPE,
				'creative_id'  => $creative_id,
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
	 * @return bool
	 */
	private function send( string $email, int $user_id, int $campaign_id ): bool {
		$result = $this->delivery->with_user_locale(
			$user_id,
			function () use ( $email, $campaign_id ): bool {
				$message = $this->message( $campaign_id );

				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional notification to individually authorized staff, not bulk or marketing email.
				return wp_mail( $email, $message['subject'], $message['body'], Notification_Delivery::sender_headers() );
			}
		);

		return true === $result;
	}

	/**
	 * Builds the reviewer's plain-text message.
	 *
	 * Says which campaign and nothing about the artwork. A reviewer cannot judge
	 * an image from a description, so anything this quoted would be read instead
	 * of the creative rather than alongside it, and the file name an advertiser
	 * chose is not evidence of anything.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array{subject: string, body: string}
	 */
	private function message( int $campaign_id ): array {
		$title        = sanitize_text_field( $this->campaigns->title( $campaign_id ) );
		$organization = sanitize_text_field( $this->orgs->name( $this->campaigns->org_id( $campaign_id ) ) );
		$site_name    = sanitize_text_field( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );

		$body = array(
			__( 'An advertiser has added artwork to a campaign that is already running. It will not be served until somebody approves it.', 'aggressive-ads' ),
			'',
			/* translators: %s: organization name. */
			sprintf( __( 'Organization: %s', 'aggressive-ads' ), $organization ),
			/* translators: %s: campaign title. */
			sprintf( __( 'Campaign: %s', 'aggressive-ads' ), $title ),
			'',
			__( 'Review campaign:', 'aggressive-ads' ),

			// Onto the ad-updates tab rather than the default pending queue, for
			// the reason Request_Mailer links to the requests tab: the campaign
			// this is about is running, so the pending list does not contain it
			// and the reviewer would arrive at a screen that does not show it.
			Review_Screen::campaign_url( $campaign_id, 'updates' ),
		);

		return array(
			'subject' => sanitize_text_field(
				sprintf(
					/* translators: 1: site name. 2: campaign title. */
					__( '[%1$s] Ad waiting for review: %2$s', 'aggressive-ads' ),
					$site_name,
					$title
				)
			),
			'body'    => implode( "\n", $body ),
		);
	}
}
