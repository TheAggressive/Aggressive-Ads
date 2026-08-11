<?php
/**
 * Moves campaigns along the edges only time can open.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Domain\Transition_Table;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;

/**
 * The reconciler behind approved → scheduled → live → complete.
 *
 * **Why this exists at all.** The four clock-derived edges carry no capability
 * and no actor; they become true when the clock says so. The transition table
 * calls them "pure functions of time: there is no moment at which something
 * must run for them to become true", and that sentence describes what makes
 * this class *safe*, not what makes it unnecessary. Nothing was calling
 * apply_system(), so status stopped moving the moment a campaign was approved:
 * AdSanity kept serving the ad correctly off its own date meta while every
 * screen in the portal reported `Approved` forever, the queue's Running tab
 * stayed empty, and no campaign ever reached `Completed`.
 *
 * **Why the status is written rather than derived.** Deriving it at read time
 * is the obvious alternative and it does not survive contact with the
 * repositories: every listing filters `post_status` in SQL, so a derived status
 * forces filtering in PHP, which makes page two short for reasons nobody can
 * explain. It also loses the two things that matter most — the audit row
 * recording *when* a campaign went live, which is a billing question, and the
 * transition effects, which do real work in AdSanity. A view cannot have side
 * effects.
 *
 * **Recovery is the whole design.** A missed run costs nothing: the next sweep
 * reads the clock as it is then and reaches the same state. That is why the
 * batch is bounded and why nothing here tracks where it got to.
 */
final class Campaign_Clock implements Service {

	/**
	 * The cron hook.
	 */
	public const HOOK = 'laao_ads_reconcile_campaigns';

	/**
	 * How often the sweep runs.
	 *
	 * Hourly, matching the granularity anyone schedules a campaign at. A
	 * campaign therefore goes live within an hour of its start date, which is
	 * the resolution the portal promises — the date picker has no time field.
	 */
	public const RECURRENCE = 'hourly';

	/**
	 * Campaigns examined per run.
	 *
	 * Bounded because cron shares a request with whatever else is due. Falling
	 * behind is recoverable; timing out every hour and never finishing is not.
	 */
	public const BATCH = 100;

	/**
	 * Most edges one campaign can travel in a single sweep.
	 *
	 * A campaign approved after its own start date has to cross two edges at
	 * once — approved → live → complete when the window closed while it sat in
	 * review. Bounded rather than `while (true)`: a guard that wrongly reports
	 * true forever must not take the site down with it.
	 */
	private const MAX_HOPS = 4;

	/**
	 * Constructor.
	 *
	 * @param Campaign_State_Machine $machine   The lifecycle.
	 * @param Campaign_Repository    $campaigns Campaign persistence.
	 */
	public function __construct(
		private readonly Campaign_State_Machine $machine,
		private readonly Campaign_Repository $campaigns
	) {
	}

	/**
	 * Attaches the sweep and makes sure it is scheduled.
	 *
	 * Scheduled from init rather than from activation. Activation is a hint —
	 * it does not run on a file-only deploy, an in-place update or a database
	 * restore — and a reconciler that silently stopped being scheduled looks
	 * exactly like one that has nothing to do.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedules the sweep, and repairs it when the recurrence has drifted.
	 *
	 * The obvious version returns early whenever anything is scheduled, and it
	 * is not enough: a site that ran a release where this swept daily keeps
	 * sweeping daily forever, because an event exists and nothing ever looks at
	 * what it says. Changing RECURRENCE would then be a change that silently
	 * applied to new installs only.
	 *
	 * Checking the recurrence rather than only the existence also gives the
	 * early return something to protect — core already refuses to schedule a
	 * duplicate, so existence alone was never the thing this guarded.
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
	 * Separate from reconcile() only because an action callback must return
	 * nothing, and the count is worth having for WP-CLI and for tests.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->reconcile();
	}

	/**
	 * One sweep.
	 *
	 * @return int How many campaigns changed status.
	 */
	public function reconcile(): int {
		$changed = 0;

		foreach ( $this->campaigns->ids_in_status( Transition_Table::system_sources(), self::BATCH ) as $campaign_id ) {
			$changed += $this->reconcile_campaign( $campaign_id ) ? 1 : 0;
		}

		return $changed;
	}

	/**
	 * Advances one campaign as far as the clock currently allows.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return bool Whether the campaign moved at all.
	 */
	public function reconcile_campaign( int $campaign_id ): bool {
		$moved = false;

		for ( $hop = 0; $hop < self::MAX_HOPS; $hop++ ) {
			$status = $this->campaigns->status( $campaign_id );

			if ( '' === $status || ! $this->advance( $campaign_id, $status ) ) {
				break;
			}

			$moved = true;
		}

		return $moved;
	}

	/**
	 * Tries every system edge out of a status, stopping at the first that takes.
	 *
	 * The guards decide, not this method, and that is the point: whether a
	 * campaign is scheduled or live is the difference between GUARD_STARTED and
	 * GUARD_NOT_STARTED, which are mutually exclusive and already tested. A
	 * reconciler that decided for itself would be a second lifecycle, and the
	 * two would disagree the first time one of them changed.
	 *
	 * A refusal is expected and silent: most campaigns in these statuses are
	 * simply not due yet. Only a genuine fault is worth a word, and the state
	 * machine has already written the audit row for it.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Current status.
	 * @return bool Whether an edge was taken.
	 */
	private function advance( int $campaign_id, string $status ): bool {
		foreach ( Transition_Table::available_to( $status, Transition_Table::ACTOR_SYSTEM ) as $transition ) {
			if ( true === $this->machine->apply_system( $campaign_id, $transition->to ) ) {
				return true;
			}
		}

		return false;
	}
}
