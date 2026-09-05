<?php
/**
 * Decision outcome counters.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;

/**
 * Aggregate decision outcomes so "nothing is serving" has a diagnosable cause.
 *
 * **This class used to be an option, and the option was the defect.** Every
 * decision that excluded anything did `get_option()`, mutated a nested array
 * and wrote all of it back — on the public fill path. Measured on the
 * integration suite it reached 228 KB at a thousand placements, growing
 * linearly with the site and pruned by nothing, and being a read-modify-write
 * it lost concurrent increments: the counts were quietly lowest under exactly
 * the traffic that made them worth reading.
 *
 * `Conversion_Metrics` copied this class's shape and named the two things it
 * should have taken back. Both are here now, plus the one neither had:
 *
 * - **Keys are bounded by the domain.** Only `Decision_Outcome` codes are
 *   stored, so no caller grows the store by passing a new string.
 * - **Counts have a day.** A number with no time dimension reads as current no
 *   matter how old it is, and cannot be pruned, rebuilt or compared.
 * - **The increment is atomic at the database**, so overlapping decisions add
 *   rather than overwrite.
 *
 * Buffered in memory and flushed once on `shutdown`, which keeps the cost off
 * the path the client waits on: a page with six slots and three distinct
 * reasons performs one write per placement, not one per count.
 *
 * See docs/platform-p13-event-analytics-schema.md.
 */
final class Decision_Metrics implements Service {

	/**
	 * The option this class used to write.
	 *
	 * Kept as a name so migration 23 can delete it, and so a site that rolls
	 * back does not leave an orphan nobody recognises. Nothing reads it.
	 */
	public const LEGACY_OPTION_EXCLUSIONS = 'aggr_decision_exclusion_counts';

	/**
	 * Counts for this request, keyed by placement then outcome.
	 *
	 * @var array<int, array<string, int>>
	 */
	private array $buffered = array();

	/**
	 * Which kind of inventory this request's counts describe.
	 *
	 * One field rather than a dimension on every buffered entry, because a fill
	 * request is either a page's first or a refresh of it and cannot be both.
	 * A page batch decides many slots at once and they are all the same page
	 * view; a rotation refetches one slot and is a refresh. There is no request
	 * that produces both.
	 *
	 * **Every entry point declares it, rather than one of them resetting it.**
	 * This service outlives a request under a long-running SAPI, so a kind left
	 * set would file the next request's counts under the last one's. A reset in
	 * `flush()` would also prevent that, and was written first — but it made
	 * correctness depend on a line running somewhere else, and no test could
	 * fail over its removal while both callers happened to set the kind anyway.
	 * Declaring at each entry is correct by construction and every line of it
	 * is defended by a test.
	 *
	 * @var string
	 */
	private string $opportunity = Opportunity::PAGE;

	/**
	 * Constructor.
	 *
	 * @param Decision_Rollup_Repository $rollups Durable per-day counters.
	 */
	public function __construct( private readonly Decision_Rollup_Repository $rollups ) {
	}

	/**
	 * Writes what this request decided, after the response has gone.
	 *
	 * `shutdown` rather than a destructor: the hook is greppable, testable and
	 * skipped entirely on a request that decided nothing, and a destructor
	 * running during PHP's shutdown sequence cannot rely on the database
	 * connection still being open.
	 */
	public function init(): void {
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Declares what kind of opportunity this request is counting.
	 *
	 * Called once, before decisioning. An unknown kind resets to page rather
	 * than being ignored: ignoring would leave whatever the previous request
	 * set, and on a long-running worker that is a refresh filed as a page
	 * — or the other way around — which is the leftover-kind defect the
	 * `for_slots` test exists to prevent.
	 *
	 * @param string $opportunity `Domain\Opportunity` kind.
	 */
	public function for_opportunity( string $opportunity ): void {
		$this->opportunity = Opportunity::is_valid( $opportunity )
			? $opportunity
			: Opportunity::PAGE;
	}

	/**
	 * Counts one opportunity that was presented to decisioning.
	 *
	 * The denominator. Without it the plugin can say how often it succeeded and
	 * never how often it was asked, which is why fill rate cannot be computed
	 * from delivery alone.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function record_request( int $placement_id ): void {
		$this->buffer( $placement_id, Decision_Outcome::REQUEST );
	}

	/**
	 * Counts one opportunity that returned an advertisement.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function record_fill( int $placement_id ): void {
		$this->buffer( $placement_id, Decision_Outcome::FILL );
	}

	/**
	 * Counts one opportunity that returned nothing, and why.
	 *
	 * The reason is mapped through `No_Fill_Reason` rather than stored as the
	 * pipeline's internal exclusion code, so the stored vocabulary is the one
	 * P10 defined and an internal rename cannot change what history says.
	 *
	 * @param int    $placement_id     Placement post id.
	 * @param string $exclusion_reason Internal Domain\Exclusion_Reason code.
	 */
	public function record_no_fill( int $placement_id, string $exclusion_reason ): void {
		$this->buffer( $placement_id, No_Fill_Reason::from_exclusion_reason( $exclusion_reason ) );
	}

	/**
	 * Adds one outcome to this request's buffer.
	 *
	 * Unstorable codes are dropped here rather than at the database, so a
	 * mapping bug is a missing count instead of a rejected write on the fill
	 * path.
	 *
	 * @param int    $placement_id Placement post id.
	 * @param string $outcome      Storable outcome code.
	 */
	private function buffer( int $placement_id, string $outcome ): void {
		if ( $placement_id <= 0 || ! Decision_Outcome::is_storable( $outcome ) ) {
			return;
		}

		$this->buffered[ $placement_id ][ $outcome ] = ( $this->buffered[ $placement_id ][ $outcome ] ?? 0 ) + 1;
	}

	/**
	 * Makes this request's counts durable.
	 *
	 * Public because `shutdown` calls it, and because a caller that needs the
	 * counts readable *now* — a test, a future admin action — must be able to
	 * ask rather than reach into the buffer.
	 *
	 * Returns early on the overwhelmingly common request that decided nothing,
	 * so the hook costs an array check on every other request in wp-admin.
	 *
	 * **A failed write is dropped, not retried and never raised.** This runs
	 * after the response; a counter is a diagnostic and may not become a reason
	 * a page errors. The buffer is cleared either way, because a retry on the
	 * next request would attribute today's decisions to whatever day that
	 * request happened to land on.
	 */
	public function flush(): void {
		if ( array() === $this->buffered ) {
			return;
		}

		$day = gmdate( 'Y-m-d' );

		foreach ( $this->buffered as $placement_id => $increments ) {
			$this->rollups->add( $day, (int) $placement_id, $increments, $this->opportunity );
		}

		$this->buffered = array();
	}

	/**
	 * Outcome totals across every placement for a UTC day range.
	 *
	 * **Durable counts only, never the buffer.** A reader sees what survived a
	 * request, so a test cannot pass by reading back something that was never
	 * written — which is how a counter comes to be believed while nothing
	 * persists it.
	 *
	 * @param string $from_utc First UTC day, inclusive, `Y-m-d`.
	 * @param string $to_utc   Last UTC day, inclusive, `Y-m-d`.
	 * @return array<string, int> Outcome code => total, highest first.
	 */
	public function totals( string $from_utc, string $to_utc ): array {
		return $this->rollups->totals( $from_utc, $to_utc );
	}

	/**
	 * Outcome totals for one placement across a UTC day range.
	 *
	 * @param int    $placement_id Placement post id.
	 * @param string $from_utc     First UTC day, inclusive, `Y-m-d`.
	 * @param string $to_utc       Last UTC day, inclusive, `Y-m-d`.
	 * @return array<string, int>
	 */
	public function totals_for_placement( int $placement_id, string $from_utc, string $to_utc ): array {
		return $this->rollups->totals_for_placement( $placement_id, $from_utc, $to_utc );
	}
}
