<?php
/**
 * Repairs reporting counters from the durable event ledger.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;

/**
 * Rebuilds closed UTC days in bounded, restartable batches.
 */
final class Rollup_Reconciler implements Service {

	public const HOOK       = 'aggr_reconcile_fill_rollups';
	public const OPTION     = 'aggr_rollups_reconciled_through';
	public const RECURRENCE = 'hourly';

	/** Maximum closed days repaired by one request. */
	private const MAX_DAYS_PER_RUN = 7;

	/** Lets requests crossing UTC midnight finish before that day is sealed. */
	private const CLOSURE_GRACE_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository  $events  Durable event ledger.
	 * @param Rollup_Repository $rollups Reporting projection.
	 */
	public function __construct(
		private readonly Event_Repository $events,
		private readonly Rollup_Repository $rollups
	) {
	}

	/** Registers and repairs the hourly schedule. */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/** WordPress action adapter; metrics stay available from run(). */
	public function run_scheduled(): void {
		$this->run();
	}

	/** Ensures code and persisted recurrence cannot drift. */
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

	/** Removes the scheduled repair job. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Reconciles a bounded number of closed days and advances the watermark.
	 *
	 * Current UTC day is never rebuilt while events can still arrive. That
	 * avoids racing an exact aggregate against the synchronous increment path.
	 *
	 * @return int Days reconciled.
	 */
	public function run(): int {
		$open_day_start = strtotime( gmdate( 'Y-m-d', time() - self::CLOSURE_GRACE_SECONDS ) . ' 00:00:00 UTC' );

		if ( false === $open_day_start ) {
			return 0;
		}

		$latest_closed = gmdate( 'Y-m-d', $open_day_start - DAY_IN_SECONDS );
		$next          = $this->next_day( $open_day_start );

		if ( null === $next ) {
			update_option( self::OPTION, $latest_closed, false );

			return 0;
		}

		$done = 0;

		while ( $next <= $latest_closed && $done < self::MAX_DAYS_PER_RUN ) {
			if ( ! $this->rollups->reconcile_day( $next ) ) {
				break;
			}

			update_option( self::OPTION, $next, false );
			++$done;
			$following = strtotime( $next . ' +1 day UTC' );

			if ( false === $following ) {
				break;
			}

			$next = gmdate( 'Y-m-d', $following );
		}

		return $done;
	}

	/** Last closed day known to match the event ledger. */
	public function reconciled_through(): string {
		$value = get_option( self::OPTION, '' );

		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * First day after the watermark, or the oldest closed ledger day.
	 *
	 * @param int $open_day_start Start of the first day not safe to seal.
	 */
	private function next_day( int $open_day_start ): ?string {
		$through = $this->reconciled_through();

		if ( '' !== $through ) {
			$next = strtotime( $through . ' +1 day UTC' );

			return false === $next ? null : gmdate( 'Y-m-d', $next );
		}

		return $this->events->earliest_day_between( 0, $open_day_start );
	}
}
