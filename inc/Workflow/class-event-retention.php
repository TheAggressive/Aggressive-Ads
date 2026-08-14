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
 * Daily retention job for aggr_events. Rollups stay; they are the reporting
 * source and are not personal data.
 */
final class Event_Retention implements Service {

	public const HOOK       = 'aggr_purge_fill_events';
	public const RECURRENCE = 'daily';

	/**
	 * Constructor.
	 *
	 * @param Event_Repository $events   Append-only log.
	 * @param Settings         $settings Retention days.
	 */
	public function __construct(
		private readonly Event_Repository $events,
		private readonly Settings $settings
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

		$this->events->purge_before( time() - ( $days * DAY_IN_SECONDS ) );
	}
}
