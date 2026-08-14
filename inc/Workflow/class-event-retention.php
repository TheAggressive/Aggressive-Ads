<?php
/**
 * Deletes native fill events past the configured retention window.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Repository\Event_Repository;

/**
 * Bounded hourly retention for aggr_events. Only days already reconciled into
 * rollups are eligible for deletion.
 */
final class Event_Retention implements Service {

	public const HOOK       = 'aggr_purge_fill_events';
	public const RECURRENCE = 'hourly';

	private const BATCH_SIZE          = 10_000;
	private const MAX_BATCHES_PER_RUN = 10;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository  $events   Append-only log.
	 * @param Settings          $settings   Retention days.
	 * @param Rollup_Reconciler $reconciler Closed-day projection repair.
	 */
	public function __construct(
		private readonly Event_Repository $events,
		private readonly Settings $settings,
		private readonly Rollup_Reconciler $reconciler
	) {
	}

	/**
	 * Attaches the sweep and keeps it scheduled.
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedules the sweep, repairing a drifted recurrence.
	 */
	public function ensure_scheduled(): void {
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( false !== $scheduled && self::RECURRENCE === wp_get_schedule( self::HOOK ) ) {
			return;
		}

		if ( false !== $scheduled ) {
			wp_clear_scheduled_hook( self::HOOK );
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, self::RECURRENCE, self::HOOK );
	}

	/**
	 * Removes the scheduled sweep.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * One sweep.
	 */
	public function run(): void {
		$days = $this->settings->retention_days();

		if ( $days <= 0 ) {
			return;
		}

		$this->reconciler->run();

		$through = $this->reconciler->reconciled_through();
		$safe    = '' === $through ? false : strtotime( $through . ' +1 day UTC' );

		if ( false === $safe ) {
			return;
		}

		$cutoff = min( time() - ( $days * DAY_IN_SECONDS ), $safe );

		for ( $batch = 0; $batch < self::MAX_BATCHES_PER_RUN; ++$batch ) {
			if ( self::BATCH_SIZE !== $this->events->purge_before( $cutoff, self::BATCH_SIZE ) ) {
				break;
			}
		}
	}
}
