<?php
/**
 * Telling the review team an advertiser has asked for something.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Throwable;

/**
 * One notice path for both kinds of request: changes submitted for review,
 * and a pause, restart or cancel. Its own class since the two moved apart
 * (`Campaign_Change_Manager`, `Campaign_Action_Requests`) and the notice has
 * to stay one thing — the revision it bumps is the receipt the mailer keys on.
 */
final class Request_Notifier {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository         $campaigns Campaign ownership.
	 * @param Campaign_Request_Repository $requests  The request revision.
	 * @param Audit_Repository            $audit     Where a failed notice is recorded.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Campaign_Request_Repository $requests,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Tells the review team something is waiting, after the write has committed.
	 *
	 * The counter is bumped here rather than in the mailer, and that is the
	 * whole point of it: the mailer reads it, so a cron retry re-reads the same
	 * number and reserves the same receipt. Bumping it on the retry path would
	 * make every attempt a new notification and mail the review team on every
	 * tick.
	 *
	 * Failures are swallowed for the reason `Campaign_State_Machine::notify()`
	 * swallows them — the advertiser's request is already saved, and returning
	 * an error now would tell them it was not.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 * @return void
	 */
	public function send( int $campaign_id, string $kind ): void {
		$this->requests->increment_request_revision( $campaign_id );

		try {
			// Spelled out rather than referenced through Request_Mailer, as
			// Campaign_State_Machine spells out its own notify hook: a hook name
			// that only exists as a constant is a hook nobody can grep for.
			do_action( 'aggr_notify_advertiser_request', $campaign_id, $kind );
		} catch ( Throwable $exception ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'campaign.notification_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exception->getMessage(),
					context: array( 'kind' => $kind )
				)
			);
		}
	}
}
