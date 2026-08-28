<?php
/**
 * Ending-soon campaign email delivery.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Notification;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use RuntimeException;

/**
 * Individualized, receipt-backed reminders before a campaign's end date.
 *
 * Kept separate from Notification_Service so transition mail and clock mail do
 * not share one class that grows past the file-length gate.
 */
final class Ending_Soon_Mailer implements Service {

	public const RETRY_HOOK = 'aggr_retry_ending_soon_notifications';

	private const TYPE = 'ending_soon';

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository   $campaigns Campaign persistence.
	 * @param Org_Repository        $orgs      Organization persistence.
	 * @param Audit_Repository      $audit     Audit persistence.
	 * @param Notification_Delivery $delivery  Shared receipt and retry helpers.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Org_Repository $orgs,
		private readonly Audit_Repository $audit,
		private readonly Notification_Delivery $delivery
	) {
	}

	/**
	 * Attaches retry delivery.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::RETRY_HOOK, array( $this, 'retry' ), 10, 4 );
	}

	/**
	 * Reminds org members that a running campaign ends within seven days.
	 *
	 * Failures are audited and retried; they never change campaign status.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return void
	 */
	public function notify( int $campaign_id ): void {
		$end_ts = $this->campaigns->end_ts( $campaign_id );
		$status = $this->campaigns->status( $campaign_id );

		if (
			$end_ts <= 0
			|| ! in_array( $status, array( Post_Statuses::LIVE, Post_Statuses::PAUSED ), true )
		) {
			return;
		}

		try {
			$this->queue( $campaign_id, $end_ts, $status );
		} catch ( RuntimeException $exception ) {
			$this->delivery->schedule_retry(
				self::RETRY_HOOK,
				array( $campaign_id, $end_ts, $status, 1 ),
				1,
				$campaign_id,
				array(
					'notification' => self::TYPE,
					'end_ts'       => $end_ts,
					'attempt'      => 1,
				)
			);

			$this->audit->insert(
				new Audit_Event(
					event: 'campaign.notification_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exception->getMessage(),
					context: array(
						'notification' => self::TYPE,
						'end_ts'       => $end_ts,
					)
				)
			);
		}
	}

	/**
	 * Retries only while the campaign is still live/paused with the same end.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param int    $end_ts      End timestamp the notice announced.
	 * @param string $status      Status at the original attempt.
	 * @param int    $attempt     One-based retry attempt.
	 * @return void
	 */
	public function retry( int $campaign_id, int $end_ts, string $status, int $attempt ): void {
		if (
			$attempt < 1
			|| $attempt > Notification_Delivery::MAX_RETRIES
			|| $end_ts !== $this->campaigns->end_ts( $campaign_id )
			|| $status !== $this->campaigns->status( $campaign_id )
			|| ! in_array( $status, array( Post_Statuses::LIVE, Post_Statuses::PAUSED ), true )
		) {
			return;
		}

		try {
			$this->queue( $campaign_id, $end_ts, $status );
		} catch ( RuntimeException ) {
			$exhausted = Notification_Delivery::MAX_RETRIES === $attempt;

			$this->audit->insert(
				new Audit_Event(
					event: $exhausted ? 'campaign.notification_retry_exhausted' : 'campaign.notification_retry_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exhausted ? 'Ending-soon notification retries exhausted.' : 'Ending-soon notification retry failed.',
					context: array(
						'notification' => self::TYPE,
						'end_ts'       => $end_ts,
						'attempt'      => $attempt,
					)
				)
			);

			if ( ! $exhausted ) {
				$this->delivery->schedule_retry(
					self::RETRY_HOOK,
					array( $campaign_id, $end_ts, $status, $attempt + 1 ),
					$attempt + 1,
					$campaign_id,
					array(
						'notification' => self::TYPE,
						'end_ts'       => $end_ts,
						'attempt'      => $attempt + 1,
					)
				);
			}
		}
	}

	/**
	 * Fans out one notice per org member.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param int    $end_ts      End timestamp being announced.
	 * @param string $status      Current live/paused status.
	 * @return void
	 *
	 * @throws RuntimeException When one or more messages cannot be queued.
	 */
	private function queue( int $campaign_id, int $end_ts, string $status ): void {
		if ( ! $this->campaigns->exists( $campaign_id ) ) {
			throw new RuntimeException( 'Ending-soon notification could not resolve its campaign.' );
		}

		$org_id     = $this->campaigns->org_id( $campaign_id );
		$recipients = $this->orgs->user_ids_for_org( $org_id );

		if ( array() === $recipients ) {
			throw new RuntimeException( 'No eligible ending-soon notification recipients were found.' );
		}

		$queued = 0;
		$failed = 0;

		foreach ( $recipients as $user_id ) {
			$user    = get_userdata( $user_id );
			$receipt = self::TYPE . ':' . $end_ts . ':' . $user_id;
			$email   = false === $user ? '' : trim( (string) $user->user_email );

			if ( ! is_email( $email ) ) {
				++$failed;

				continue;
			}

			$result = $this->delivery->deliver(
				$campaign_id,
				$receipt,
				fn (): bool => $this->send( $email, $user_id, $campaign_id, $end_ts )
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
				event: 'campaign.ending_soon_notified',
				outcome: 0 === $failed ? Audit_Event::OUTCOME_OK : Audit_Event::OUTCOME_FAILED,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $org_id,
				message: 0 === $failed
					? sprintf( '%d ending-soon notification(s) queued.', $queued )
					: sprintf( '%d ending-soon notification recipient(s) could not be queued.', $failed ),
				context: array(
					'notification' => self::TYPE,
					'end_ts'       => $end_ts,
					'status'       => $status,
				)
			)
		);

		if ( $failed > 0 ) {
			throw new RuntimeException( sprintf( '%d ending-soon notification recipient(s) could not be queued.', $failed ) );
		}
	}

	/**
	 * Queues one localized plain-text email.
	 *
	 * @param string $email       Validated recipient address.
	 * @param int    $user_id     Recipient user id.
	 * @param int    $campaign_id Campaign post id.
	 * @param int    $end_ts      End timestamp being announced.
	 * @return bool
	 */
	private function send( string $email, int $user_id, int $campaign_id, int $end_ts ): bool {
		$result = $this->delivery->with_user_locale(
			$user_id,
			function () use ( $email, $campaign_id, $end_ts ): bool {
				$title     = sanitize_text_field( $this->campaigns->title( $campaign_id ) );
				$site_name = sanitize_text_field( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
				$end_date  = wp_date( (string) get_option( 'date_format' ), $end_ts );
				$subject   = sanitize_text_field(
					sprintf(
						/* translators: 1: site name. 2: campaign title. */
						__( '[%1$s] Campaign ending soon: %2$s', 'aggressive-ads' ),
						$site_name,
						$title
					)
				);
				$body = implode(
					"\n",
					array(
						__( 'This campaign is scheduled to stop running soon.', 'aggressive-ads' ),
						'',
						/* translators: %s: campaign title. */
						sprintf( __( 'Campaign: %s', 'aggressive-ads' ), $title ),
						/* translators: %s: localized end date. */
						sprintf( __( 'Ends on: %s', 'aggressive-ads' ), $end_date ),
						'',
						__( 'Open your campaign:', 'aggressive-ads' ),
						Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ),
					)
				);

				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional notification to a member of the owning organization, not bulk or marketing email.
				return wp_mail( $email, $subject, $body, Notification_Delivery::sender_headers() );
			}
		);

		return true === $result;
	}
}
