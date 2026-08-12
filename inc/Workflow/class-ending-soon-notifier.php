<?php
/**
 * Reminds advertisers when a running campaign is about to end.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Notification\Ending_Soon_Mailer;
use LAAO_Advertiser_Portal\Repository\Campaign_Lifecycle_Repository;

/**
 * Hourly sweep that emails org members seven days before end_ts.
 *
 * Not a state transition: the campaign stays live (or paused). The notice is
 * keyed to the end timestamp so extending the schedule can re-arm a later
 * reminder, and open-ended campaigns are never candidates.
 */
final class Ending_Soon_Notifier implements Service {

	/**
	 * The cron hook.
	 */
	public const HOOK = 'laao_ads_notify_ending_soon';

	/**
	 * How often the sweep runs.
	 */
	public const RECURRENCE = 'hourly';

	/**
	 * How far ahead of end_ts the reminder fires.
	 */
	public const WINDOW = 7 * DAY_IN_SECONDS;

	/**
	 * Campaigns examined per run.
	 */
	public const BATCH = 100;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Lifecycle_Repository $lifecycle Batch campaign lookups.
	 * @param Ending_Soon_Mailer            $mailer    Delivery.
	 */
	public function __construct(
		private readonly Campaign_Lifecycle_Repository $lifecycle,
		private readonly Ending_Soon_Mailer $mailer
	) {
	}

	/**
	 * Attaches the sweep and keeps it scheduled.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedules the sweep, repairing a drifted recurrence.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( false !== $scheduled && self::RECURRENCE === wp_get_schedule( self::HOOK ) ) {
			return;
		}

		if ( false !== $scheduled ) {
			wp_clear_scheduled_hook( self::HOOK );
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::RECURRENCE, self::HOOK );
	}

	/**
	 * Removes the scheduled sweep.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The cron callback.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->notify();
	}

	/**
	 * One sweep.
	 *
	 * @return int How many campaigns produced a notification attempt.
	 */
	public function notify(): int {
		$now   = time();
		$from  = $now;
		$to    = $now + self::WINDOW;
		$count = 0;

		foreach ( $this->lifecycle->ids_ending_between( $from, $to, self::BATCH ) as $campaign_id ) {
			$this->mailer->notify( $campaign_id );
			++$count;
		}

		return $count;
	}
}
