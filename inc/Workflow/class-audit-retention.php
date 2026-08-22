<?php
/**
 * Deletes audit rows past the configured window.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Repository\Audit_Repository;

/**
 * Bounded daily retention for the audit log.
 *
 * Off unless a publisher turns it on. The shipped setting is "keep forever",
 * because how long a business must hold evidence of who approved what is a
 * compliance question rather than an engineering one, and a plugin that picks
 * an answer deletes records somebody may be required to produce.
 *
 * Refusals are never deleted, whatever the window. `outcome = denied` is the
 * record of somebody attempting what they were not allowed to — the row the log
 * gets opened for — and a retention policy is about volume, which refusals do
 * not have.
 */
final class Audit_Retention implements Service {

	public const HOOK       = 'aggr_purge_audit_log';
	public const RECURRENCE = 'daily';

	/**
	 * Rows per pass, and passes per run.
	 *
	 * Smaller than the event sweep's ten thousand because this table is read by
	 * the review screen staff use all day, and a long lock there is visible.
	 */
	private const BATCH_SIZE          = 2_000;
	private const MAX_BATCHES_PER_RUN = 5;

	/**
	 * Constructor.
	 *
	 * @param Audit_Repository $audit    Audit persistence.
	 * @param Settings         $settings Stored configuration.
	 */
	public function __construct(
		private readonly Audit_Repository $audit,
		private readonly Settings $settings
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
	 * Scheduled even when retention is off, so turning it on does not wait for
	 * the next activation to take effect.
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

		wp_schedule_event( time() + HOUR_IN_SECONDS, self::RECURRENCE, self::HOOK );
	}

	/**
	 * Removes the sweep. Called at uninstall.
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
		$this->purge();
	}

	/**
	 * One sweep.
	 *
	 * @return int Rows deleted.
	 */
	public function purge(): int {
		$days = $this->settings->audit_retention_days();

		// Zero is "keep forever", and is the default. Returning before touching
		// the table means the shipped configuration cannot delete anything.
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$deleted = 0;

		for ( $pass = 0; $pass < self::MAX_BATCHES_PER_RUN; $pass++ ) {
			$removed = $this->audit->delete_before( $cutoff, self::BATCH_SIZE );

			if ( $removed <= 0 ) {
				break;
			}

			$deleted += $removed;
		}

		return $deleted;
	}
}
