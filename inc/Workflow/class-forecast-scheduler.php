<?php
/**
 * Produces forecast snapshots, and closes the ones whose windows have run.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Admin\Forecast_Data;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Repository\Placement_Repository;

/**
 * The job that gives the outlook screen something to read.
 *
 * Snapshots are produced on a schedule because a forecast is only useful if it
 * exists before somebody asks. Without this, `aggr_forecasts` stays empty and
 * the outlook screen reports every placement as never forecast — a table
 * nothing writes, read by a screen that looks broken.
 *
 * Daily rather than hourly: the window it forecasts moves once a day, and the
 * history behind it gains one sealed day at a time.
 *
 * **Recovery is the design, as it is for the campaign clock.** Nothing tracks
 * where the last run got to. A missed day costs the version that day would
 * have written; the next run forecasts the window as it stands then, and
 * maturing finds every closed window still waiting.
 */
final class Forecast_Scheduler implements Service {

	/** Cron hook. */
	public const HOOK = 'aggr_forecast_inventory';

	/** How often it runs. */
	public const RECURRENCE = 'daily';

	/** Placements forecast in one pass, so a large catalogue cannot stall cron. */
	public const MAX_PER_RUN = 50;

	/**
	 * Reads the catalogue and writes the snapshots.
	 *
	 * @param Placement_Repository $placements Active placements.
	 * @param Forecast_Recorder    $recorder   Snapshot writer.
	 * @param Forecast_Data        $windows    Supplies the window staff will read.
	 */
	public function __construct(
		private readonly Placement_Repository $placements,
		private readonly Forecast_Recorder $recorder,
		private readonly Forecast_Data $windows
	) {}

	/** Registers and repairs the daily schedule. */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * The cron entry point.
	 *
	 * Separate from `run()` because an action callback must return nothing,
	 * and `run()`'s count is what makes it testable — the same split
	 * `Rollup_Reconciler` uses.
	 */
	public function run_scheduled(): void {
		$this->run();
	}

	/** Books the recurring event, repairing a wrong recurrence. */
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

	/** Removes the scheduled job. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Forecasts the window staff read, then closes what has finished.
	 *
	 * **Both kinds, separately.** Page and refresh are different inventory, and
	 * a screen that can be switched between them needs a snapshot for each or
	 * one of the two reads as never forecast.
	 *
	 * @return int Snapshots written.
	 */
	public function run(): int {
		$window  = $this->windows->default_window();
		$written = 0;
		$seen    = 0;

		foreach ( $this->placements->active_ids() as $placement ) {
			if ( $seen >= self::MAX_PER_RUN ) {
				break;
			}

			++$seen;

			foreach ( Opportunity::all() as $kind ) {
				$result = $this->recorder->snapshot( (int) $placement, $kind, $window['from'], $window['to'] );

				if ( $result['version'] > 0 ) {
					++$written;
				}
			}
		}

		/*
		 * Maturing runs after, and on every pass rather than only when
		 * something was written: a window that closed yesterday is waiting
		 * whether or not today's forecast produced anything.
		 */
		$this->recorder->mature( gmdate( 'Y-m-d' ) );

		return $written;
	}
}
